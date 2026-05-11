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
}
