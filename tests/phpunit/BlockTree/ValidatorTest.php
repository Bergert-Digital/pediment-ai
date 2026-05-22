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
}
