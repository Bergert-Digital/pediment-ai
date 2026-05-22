<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\ToolUseParser;

class ToolUseParserTest extends \WP_UnitTestCase {
	public function test_extracts_emit_page_tool_input(): void {
		$result = ( new ToolUseParser() )->parse( [
			'content' => [
				[ 'type' => 'text', 'text' => 'Here you go.' ],
				[
					'type'  => 'tool_use',
					'id'    => 'tu_1',
					'name'  => 'emit_page',
					'input' => [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ] ] ],
				],
			],
		] );

		$this->assertSame( 'emit_page', $result['tool'] );
		$this->assertSame( 'pediment/hero', $result['input']['blocks'][0]['name'] );
		$this->assertSame( [], $result['urls_fetched'] );
	}

	public function test_extracts_emit_block_tool_input(): void {
		$result = ( new ToolUseParser() )->parse( [
			'content' => [
				[ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_block', 'input' => [ 'attributes' => [ 'headline' => 'X' ], 'innerBlocks' => [] ] ],
			],
		] );

		$this->assertSame( 'emit_block', $result['tool'] );
		$this->assertSame( 'X', $result['input']['attributes']['headline'] );
	}

	public function test_collects_web_fetch_urls(): void {
		$result = ( new ToolUseParser() )->parse( [
			'content' => [
				[ 'type' => 'server_tool_use', 'id' => 'st_1', 'name' => 'web_fetch', 'input' => [ 'url' => 'https://example.com/a' ] ],
				[ 'type' => 'web_fetch_tool_result', 'tool_use_id' => 'st_1', 'content' => [ [ 'type' => 'text', 'text' => '...' ] ] ],
				[ 'type' => 'server_tool_use', 'id' => 'st_2', 'name' => 'web_fetch', 'input' => [ 'url' => 'https://example.com/b' ] ],
				[ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page', 'input' => [ 'blocks' => [] ] ],
			],
		] );

		$this->assertSame( [ 'https://example.com/a', 'https://example.com/b' ], $result['urls_fetched'] );
	}

	public function test_returns_null_tool_when_no_tool_use_present(): void {
		$result = ( new ToolUseParser() )->parse( [
			'content' => [ [ 'type' => 'text', 'text' => 'No tool call.' ] ],
		] );

		$this->assertNull( $result['tool'] );
		$this->assertSame( [], $result['urls_fetched'] );
	}
}
