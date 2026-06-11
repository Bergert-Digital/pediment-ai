<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\SchemaBuilder;

class SchemaBuilderTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_transient( 'pediment_ai_schema' );

		register_block_type( 'pediment/test-block', [
			'attributes'  => [ 'foo' => [ 'type' => 'string', 'default' => '' ] ],
			'description' => 'A test block.',
		] );
	}

	public function tearDown(): void {
		unregister_block_type( 'pediment/test-block' );
		delete_transient( 'pediment_ai_schema' );
		parent::tearDown();
	}

	public function test_includes_starter_blocks(): void {
		$schema = ( new SchemaBuilder() )->build();
		$this->assertArrayHasKey( 'pediment/test-block', $schema['blocks'] );
		$this->assertSame( 'A test block.', $schema['blocks']['pediment/test-block']['description'] );
	}

	public function test_includes_curated_core_blocks(): void {
		$schema = ( new SchemaBuilder() )->build();
		$this->assertArrayHasKey( 'core/paragraph', $schema['blocks'] );
		$this->assertArrayHasKey( 'core/heading',   $schema['blocks'] );
	}

	public function test_excludes_unrelated_blocks(): void {
		register_block_type( 'someplugin/widget', [ 'attributes' => [], 'description' => 'x' ] );
		$schema = ( new SchemaBuilder() )->build();
		$this->assertArrayNotHasKey( 'someplugin/widget', $schema['blocks'] );
		unregister_block_type( 'someplugin/widget' );
	}

	public function test_caches_result_in_transient(): void {
		$builder = new SchemaBuilder();
		$first   = $builder->build();
		$cached  = get_transient( 'pediment_ai_schema' );
		$this->assertNotFalse( $cached );
		$this->assertSame( $first, $cached );
	}

	public function test_invalidate_clears_transient(): void {
		( new SchemaBuilder() )->build();
		$this->assertNotFalse( get_transient( 'pediment_ai_schema' ) );
		SchemaBuilder::invalidate();
		$this->assertFalse( get_transient( 'pediment_ai_schema' ) );
	}

	public function test_block_namespaces_filter_extends_allowlist(): void {
		\PedimentAi\Anthropic\SchemaBuilder::invalidate();

		register_block_type(
			'acme/promo-banner',
			[
				'description' => 'A promotional banner.',
				'attributes'  => [ 'text' => [ 'type' => 'string' ] ],
			]
		);

		$cb = static function ( $namespaces ) {
			$namespaces[] = 'acme';
			return $namespaces;
		};
		add_filter( 'pediment_ai_block_namespaces', $cb );

		$schema = ( new \PedimentAi\Anthropic\SchemaBuilder() )->build( true );

		$this->assertArrayHasKey( 'acme/promo-banner', $schema['blocks'] );
		$this->assertSame( 'A promotional banner.', $schema['blocks']['acme/promo-banner']['description'] );

		remove_filter( 'pediment_ai_block_namespaces', $cb );
		unregister_block_type( 'acme/promo-banner' );
	}

	public function test_core_group_is_allowlisted_with_inner_blocks(): void {
		$schema = ( new SchemaBuilder() )->build( true );
		$this->assertArrayHasKey( 'core/group', $schema['blocks'] );
		$this->assertTrue( $schema['blocks']['core/group']['allowsInnerBlocks'] );
	}

	public function test_block_namespaces_default_excludes_unknown_namespaces(): void {
		\PedimentAi\Anthropic\SchemaBuilder::invalidate();

		register_block_type(
			'thirdparty/widget',
			[
				'description' => 'Should be ignored by default.',
				'attributes'  => [],
			]
		);

		$schema = ( new \PedimentAi\Anthropic\SchemaBuilder() )->build( true );

		$this->assertArrayNotHasKey( 'thirdparty/widget', $schema['blocks'] );

		unregister_block_type( 'thirdparty/widget' );
	}

	public function test_parent_child_pair_exposes_allowed_children_and_requires_parent(): void {
		\PedimentAi\Anthropic\SchemaBuilder::invalidate();

		register_block_type( 'pediment/demo-grid', [
			'description' => 'A demo container grid.',
			'attributes'  => [],
		] );
		register_block_type( 'pediment/demo-card', [
			'description' => 'A demo card.',
			'attributes'  => [ 'text' => [ 'type' => 'string', 'default' => '' ] ],
			'parent'      => [ 'pediment/demo-grid' ],
		] );

		$blocks = ( new SchemaBuilder() )->build( true )['blocks'];

		// Parent advertises the child and that it accepts inner blocks.
		$this->assertContains( 'pediment/demo-card', $blocks['pediment/demo-grid']['allowedChildBlocks'] );
		$this->assertTrue( $blocks['pediment/demo-grid']['allowsInnerBlocks'] );

		// Child carries requiresParent so the Validator can reject top-level orphans.
		$this->assertSame( [ 'pediment/demo-grid' ], $blocks['pediment/demo-card']['requiresParent'] );

		unregister_block_type( 'pediment/demo-card' );
		unregister_block_type( 'pediment/demo-grid' );
	}
}
