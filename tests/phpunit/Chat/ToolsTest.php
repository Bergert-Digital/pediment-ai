<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\BlockTree\Validator;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\VirtualTree;

class ToolsTest extends \WP_UnitTestCase {
	private function tools(): Tools {
		$schema = [
			'core/paragraph' => [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'attributes' => [ 'content' => [ 'type' => 'string' ], 'level' => [ 'type' => 'number' ] ], 'allowsInnerBlocks' => false ],
		];
		return new Tools( $schema, new Validator( $schema ) );
	}

	public function test_definitions_lists_block_and_web_tools(): void {
		$names = array_column( $this->tools()->definitions(), 'name' );
		$this->assertEqualsCanonicalizing(
			[ 'insert_block', 'update_block', 'delete_block', 'move_block', 'read_block', 'web_search', 'web_fetch' ],
			$names
		);
	}

	public function test_web_tools_are_server_side_definitions(): void {
		$byName = [];
		foreach ( $this->tools()->definitions() as $tool ) {
			$byName[ $tool['name'] ] = $tool;
		}

		// Server tools are typed (no input_schema) — Anthropic runs them.
		$this->assertSame( 'web_search_20260209', $byName['web_search']['type'] );
		$this->assertSame( 'web_fetch_20260209',  $byName['web_fetch']['type'] );
		$this->assertArrayNotHasKey( 'input_schema', $byName['web_fetch'] );
		// Bounded so a single turn cannot fetch without limit.
		$this->assertSame( 5, $byName['web_fetch']['max_uses'] );
	}

	public function test_web_tools_filter_can_disable_web_access(): void {
		add_filter( 'pediment_ai_web_tools', '__return_empty_array' );
		$names = array_column( $this->tools()->definitions(), 'name' );
		remove_all_filters( 'pediment_ai_web_tools' );

		$this->assertNotContains( 'web_fetch', $names );
		$this->assertNotContains( 'web_search', $names );
		$this->assertContains( 'insert_block', $names );
	}

	public function test_apply_insert_block_returns_client_id_and_mutates_tree(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'insert_block', [
			'after_client_id' => null,
			'position'        => 'end',
			'block'           => [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertNotEmpty( $result['content']['client_id'] );
		$this->assertSame( 'core/paragraph', $tree->toArray()[0]['name'] );
	}

	public function test_apply_insert_block_rejects_invalid_block(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'insert_block', [
			'after_client_id' => null,
			'position'        => 'end',
			'block'           => [ 'name' => 'core/nope', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$this->assertTrue( $result['is_error'] );
		$this->assertStringContainsString( 'core/nope', (string) $result['content'] );
	}

	public function test_apply_update_block_returns_ok(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Old' ], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'a', 'content' => 'New' ] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertSame( 'New', $tree->find( 'a' )['attributes']['content'] );
	}

	public function test_apply_update_block_repairs_orphaned_unicode_escapes(): void {
		// The model occasionally transcribes a JSON escape body (& → &) without
		// its backslash, landing literal "u0026" in the post. Repair at the boundary.
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/heading', 'attributes' => [ 'content' => 'Old' ], 'innerBlocks' => [] ],
		] );
		$this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'a', 'content' => 'Transfer u0026 Verankerung' ] );
		$this->assertSame( 'Transfer & Verankerung', $tree->find( 'a' )['attributes']['content'] );
	}

	public function test_apply_update_block_repairs_escapes_in_attrs(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/heading', 'attributes' => [ 'content' => 'x' ], 'innerBlocks' => [] ],
		] );
		$this->tools()->apply( $tree, 'update_block', [
			'client_id' => 'a',
			'attrs'     => [ 'content' => 'A u003c B u003e C u0022quoteu0022' ],
		] );
		$this->assertSame( 'A < B > C "quote"', $tree->find( 'a' )['attributes']['content'] );
	}

	public function test_apply_insert_block_repairs_escapes_in_nested_children(): void {
		$schema = [
			'core/group'     => [ 'attributes' => [], 'allowsInnerBlocks' => true ],
			'core/paragraph' => [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ], 'allowsInnerBlocks' => false ],
		];
		$tools = new Tools( $schema, new Validator( $schema ) );
		$tree  = new VirtualTree( [] );
		$tools->apply( $tree, 'insert_block', [
			'after_client_id' => null,
			'position'        => 'end',
			'block'           => [
				'name'        => 'core/group',
				'attributes'  => [ 'tagName' => 'section u0026 wrap' ],
				'innerBlocks' => [
					[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Soll u0026 Haben' ], 'innerBlocks' => [] ],
				],
			],
		] );
		$node = $tree->toArray()[0];
		$this->assertSame( 'section & wrap', $node['attributes']['tagName'] );
		$this->assertSame( 'Soll & Haben', $node['innerBlocks'][0]['attributes']['content'] );
	}

	public function test_apply_update_block_leaves_real_text_untouched(): void {
		// A bare "u" followed by non-escape digits must survive verbatim.
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'x' ], 'innerBlocks' => [] ],
		] );
		$this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'a', 'content' => 'Ubuntu 0026 release u1234' ] );
		$this->assertSame( 'Ubuntu 0026 release u1234', $tree->find( 'a' )['attributes']['content'] );
	}

	public function test_apply_update_block_for_missing_id_returns_error(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'missing', 'content' => 'x' ] );
		$this->assertTrue( $result['is_error'] );
		$this->assertStringContainsString( 'Block not found', (string) $result['content'] );
	}

	public function test_apply_read_block_returns_full_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Full text' ], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'read_block', [ 'client_id' => 'a' ] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertSame( 'core/paragraph', $result['content']['name'] );
		$this->assertSame( 'Full text',       $result['content']['attributes']['content'] );
	}

	public function test_apply_unknown_tool_returns_error(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'do_unspeakable_things', [] );
		$this->assertTrue( $result['is_error'] );
	}

	public function test_apply_delete_block_removes_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'delete_block', [ 'client_id' => 'a' ] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertNull( $tree->find( 'a' ) );
		$this->assertNotNull( $tree->find( 'b' ) );
	}

	public function test_apply_move_block_repositions_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'c', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'move_block', [
			'client_id'        => 'c',
			'target_client_id' => 'a',
			'position'         => 'before',
		] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertSame( [ 'c', 'a', 'b' ], array_column( $tree->toArray(), 'clientId' ) );
	}
}
