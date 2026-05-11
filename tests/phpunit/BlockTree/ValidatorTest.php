<?php
namespace StarterAi\Tests\BlockTree;

use StarterAi\BlockTree\Validator;

class ValidatorTest extends \WP_UnitTestCase {
	private function schema(): array {
		return [
			'starter/hero' => [
				'description' => 'Hero',
				'attributes'  => [ 'headline' => [ 'type' => 'string' ] ],
				'allowsInnerBlocks' => false,
			],
			'starter/faq' => [
				'description'        => 'FAQ',
				'attributes'         => [],
				'allowsInnerBlocks'  => true,
				'allowedChildBlocks' => [ 'starter/faq-item' ],
			],
			'starter/faq-item' => [
				'description' => 'FAQ item',
				'attributes'  => [],
				'allowsInnerBlocks' => false,
			],
		];
	}

	public function test_valid_tree_passes(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'starter/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertSame( [], $errors );
	}

	public function test_unknown_block_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'starter/nope', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'starter/nope', $errors[0] );
	}

	public function test_inner_blocks_on_non_container_fail(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[
				'name'        => 'starter/hero',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'starter/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
				],
			],
		] );
		$this->assertNotEmpty( $errors );
	}

	public function test_disallowed_child_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[
				'name'        => 'starter/faq',
				'attributes'  => [],
				'innerBlocks' => [
					[ 'name' => 'starter/hero', 'attributes' => [], 'innerBlocks' => [] ],
				],
			],
		] );
		$this->assertNotEmpty( $errors );
	}

	public function test_attributes_not_object_fails(): void {
		$errors = ( new Validator( $this->schema() ) )->validate( [
			[ 'name' => 'starter/hero', 'attributes' => 'oops', 'innerBlocks' => [] ],
		] );
		$this->assertNotEmpty( $errors );
	}
}
