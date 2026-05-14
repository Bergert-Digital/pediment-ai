<?php
namespace StarterAi\Tests\Anthropic;

use StarterAi\Anthropic\SchemaBuilder;

class SchemaBuilderTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		delete_transient( 'starter_ai_schema' );

		register_block_type( 'starter/test-block', [
			'attributes'  => [ 'foo' => [ 'type' => 'string', 'default' => '' ] ],
			'description' => 'A test block.',
		] );
	}

	public function tearDown(): void {
		unregister_block_type( 'starter/test-block' );
		delete_transient( 'starter_ai_schema' );
		parent::tearDown();
	}

	public function test_includes_starter_blocks(): void {
		$schema = ( new SchemaBuilder() )->build();
		$this->assertArrayHasKey( 'starter/test-block', $schema['blocks'] );
		$this->assertSame( 'A test block.', $schema['blocks']['starter/test-block']['description'] );
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
		$cached  = get_transient( 'starter_ai_schema' );
		$this->assertNotFalse( $cached );
		$this->assertSame( $first, $cached );
	}

	public function test_invalidate_clears_transient(): void {
		( new SchemaBuilder() )->build();
		$this->assertNotFalse( get_transient( 'starter_ai_schema' ) );
		SchemaBuilder::invalidate();
		$this->assertFalse( get_transient( 'starter_ai_schema' ) );
	}

	public function test_block_namespaces_filter_extends_allowlist(): void {
		\StarterAi\Anthropic\SchemaBuilder::invalidate();

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
		add_filter( 'starter_ai_block_namespaces', $cb );

		$schema = ( new \StarterAi\Anthropic\SchemaBuilder() )->build( true );

		$this->assertArrayHasKey( 'acme/promo-banner', $schema['blocks'] );
		$this->assertSame( 'A promotional banner.', $schema['blocks']['acme/promo-banner']['description'] );

		remove_filter( 'starter_ai_block_namespaces', $cb );
		unregister_block_type( 'acme/promo-banner' );
	}

	public function test_block_namespaces_default_excludes_unknown_namespaces(): void {
		\StarterAi\Anthropic\SchemaBuilder::invalidate();

		register_block_type(
			'thirdparty/widget',
			[
				'description' => 'Should be ignored by default.',
				'attributes'  => [],
			]
		);

		$schema = ( new \StarterAi\Anthropic\SchemaBuilder() )->build( true );

		$this->assertArrayNotHasKey( 'thirdparty/widget', $schema['blocks'] );

		unregister_block_type( 'thirdparty/widget' );
	}
}
