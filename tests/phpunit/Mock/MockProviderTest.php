<?php
namespace PedimentAi\Tests\Mock;

use PedimentAi\Mock\MockProvider;

class MockProviderTest extends \WP_UnitTestCase {
	private string $fixturesDir;

	public function setUp(): void {
		parent::setUp();
		$this->fixturesDir = sys_get_temp_dir() . '/pediment-ai-fixtures-' . uniqid();
		mkdir( $this->fixturesDir, 0777, true );
		file_put_contents( $this->fixturesDir . '/compose-landing.json', wp_json_encode( [
			'content' => [
				[ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page',
				  'input' => [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Mock landing' ], 'innerBlocks' => [] ] ] ] ],
			],
			'usage' => [ 'input_tokens' => 0, 'output_tokens' => 0 ],
			'model' => 'mock',
		] ) );
		file_put_contents( $this->fixturesDir . '/refine-hero.json', wp_json_encode( [
			'content' => [
				[ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_block',
				  'input' => [ 'attributes' => [ 'headline' => 'Mock refined' ], 'innerBlocks' => [] ] ],
			],
			'usage' => [ 'input_tokens' => 0, 'output_tokens' => 0 ],
			'model' => 'mock',
		] ) );
	}

	public function tearDown(): void {
		array_map( 'unlink', glob( $this->fixturesDir . '/*.json' ) );
		rmdir( $this->fixturesDir );
		parent::tearDown();
	}

	public function test_returns_compose_fixture(): void {
		$provider = new MockProvider( $this->fixturesDir );
		$response = $provider->messages( [
			'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Page type: landing' ] ] ] ],
			'tools'    => [ [ 'name' => 'emit_page' ] ],
		] );
		$this->assertSame( 'Mock landing', $response['content'][0]['input']['blocks'][0]['attributes']['headline'] );
	}

	public function test_returns_refine_fixture(): void {
		$provider = new MockProvider( $this->fixturesDir );
		$response = $provider->messages( [
			'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Refine this block: pediment/hero' ] ] ] ],
			'tools'    => [ [ 'name' => 'emit_block' ] ],
		] );
		$this->assertSame( 'Mock refined', $response['content'][0]['input']['attributes']['headline'] );
	}

	public function test_falls_back_to_default_compose_fixture(): void {
		$provider = new MockProvider( $this->fixturesDir );
		$response = $provider->messages( [
			'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Page type: spaceships' ] ] ] ],
			'tools'    => [ [ 'name' => 'emit_page' ] ],
		] );
		$this->assertSame( 'Mock landing', $response['content'][0]['input']['blocks'][0]['attributes']['headline'] );
	}
}
