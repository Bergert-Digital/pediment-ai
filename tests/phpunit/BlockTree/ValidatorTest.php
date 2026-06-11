<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Validator;

class ValidatorTest extends \WP_UnitTestCase {
	private function schema(): array {
		return [
			'pediment/hero' => [
				'description' => 'Hero',
				'attributes'  => [ 'headline' => [ 'type' => 'string' ] ],
				'allowsInnerBlocks' => false,
			],
			'pediment/faq' => [
				'description'        => 'FAQ',
				'attributes'         => [],
				'allowsInnerBlocks'  => true,
				'allowedChildBlocks' => [ 'pediment/faq-item' ],
			],
			'pediment/faq-item' => [
				'description' => 'FAQ item',
				'attributes'  => [],
				'allowsInnerBlocks' => false,
				'requiresParent'    => [ 'pediment/faq' ],
			],
		];
	}

	public function test_valid_tree_passes(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertSame( [], $errors );
	}

	public function test_unknown_block_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'pediment/nope', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'pediment/nope', $errors[0] );
	}

	public function test_inner_blocks_on_non_container_fail(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[
				'name'        => 'pediment/hero',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
				],
			],
		] );
		$this->assertNotEmpty( $errors );
	}

	public function test_disallowed_child_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[
				'name'        => 'pediment/faq',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'pediment/hero', 'attributes' => [], 'innerBlocks' => [] ],
				],
			],
		] );
		$this->assertNotEmpty( $errors );
	}

	public function test_attributes_not_object_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'pediment/hero', 'attributes' => 'oops', 'innerBlocks' => [] ],
		] );
		$this->assertNotEmpty( $errors );
	}

	public function test_validate_node_returns_no_errors_for_valid_block(): void {
		$schema = [
			'core/paragraph' => [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ], 'allowsInnerBlocks' => false ],
		];
		$errors = ( new Validator( $schema ) )->validateNode(
			[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'hi' ], 'innerBlocks' => [] ]
		);
		$this->assertSame( [], $errors );
	}

	public function test_validate_node_rejects_unknown_block(): void {
		$schema = [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ];
		$errors = ( new Validator( $schema ) )->validateNode(
			[ 'name' => 'core/nope', 'attributes' => [], 'innerBlocks' => [] ]
		);
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'core/nope', $errors[0] );
	}

	public function test_validate_node_rejects_inner_when_disallowed(): void {
		$schema = [
			'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'attributes' => [], 'allowsInnerBlocks' => false ],
		];
		$errors = ( new Validator( $schema ) )->validateNode( [
			'name'        => 'core/paragraph',
			'attributes'  => [],
			'innerBlocks' => [ [ 'name' => 'core/heading', 'attributes' => [], 'innerBlocks' => [] ] ],
		] );
		$this->assertNotEmpty( $errors );
	}

	// A parent-locked child block (requiresParent) must never be inserted on its own —
	// the composer has no "insert into parent" op, so a top-level child is an orphan.
	public function test_rejects_parent_locked_block_at_top_level(): void {
		$errors = ( new Validator( $this->schema() ) )->validateNode(
			[ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ]
		);
		$this->assertNotEmpty( $errors, 'A standalone parent-locked block must be rejected at the top level.' );
		$joined = implode( ' ', $errors );
		$this->assertStringContainsString( 'pediment/faq-item', $joined );
		$this->assertStringContainsString( 'pediment/faq', $joined );
		// The message must steer the model toward nesting via innerBlocks.
		$this->assertStringContainsStringIgnoringCase( 'innerBlocks', $joined );
	}

	public function test_accepts_parent_locked_child_nested_in_its_parent(): void {
		$errors = ( new Validator( $this->schema() ) )->validateNode( [
			'name'        => 'pediment/faq',
			'attributes'  => [],
			'innerBlocks' => [
				[ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
				[ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
			],
		] );
		$this->assertSame( [], $errors, 'A container populated with its nested children must validate clean.' );
	}

	public function test_full_tree_validate_flags_top_level_orphan_child(): void {
		// validate() (whole-tree entry point) must also flag a child sitting at the root.
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ],
			[ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'pediment/faq-item', implode( ' ', $errors ) );
	}
}
