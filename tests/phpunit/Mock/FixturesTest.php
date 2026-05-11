<?php
namespace StarterAi\Tests\Mock;

class FixturesTest extends \WP_UnitTestCase {
	private const REQUIRED = [
		'compose-landing', 'compose-about', 'compose-services', 'compose-contact',
		'edit-add-faq', 'edit-shorten',
		'refine-hero', 'refine-cta', 'refine-faq-item',
	];

	public function test_all_required_fixtures_exist_and_parse(): void {
		$dir = dirname( __DIR__, 2 ) . '/src/Mock/fixtures';
		foreach ( self::REQUIRED as $name ) {
			$path = "{$dir}/{$name}.json";
			$this->assertFileExists( $path, "Missing fixture: {$name}" );
			$data = json_decode( (string) file_get_contents( $path ), true );
			$this->assertIsArray( $data, "Invalid JSON in: {$name}" );
			$this->assertNotEmpty( $data['content'], "Empty content in: {$name}" );
			$tool = null;
			foreach ( $data['content'] as $block ) {
				if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
					$tool = $block;
					break;
				}
			}
			$this->assertNotNull( $tool, "Fixture must contain tool_use: {$name}" );
			$expected_tool = str_starts_with( $name, 'refine-' ) ? 'emit_block' : 'emit_page';
			$this->assertSame( $expected_tool, $tool['name'] );
		}
	}
}
