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

	public function test_definitions_lists_all_five_tools(): void {
		$names = array_column( $this->tools()->definitions(), 'name' );
		$this->assertEqualsCanonicalizing(
			[ 'insert_block', 'update_block', 'delete_block', 'move_block', 'read_block' ],
			$names
		);
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
