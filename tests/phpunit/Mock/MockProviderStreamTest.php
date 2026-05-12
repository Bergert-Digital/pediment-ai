<?php
namespace StarterAi\Tests\Mock;

use StarterAi\Mock\MockProvider;

class MockProviderStreamTest extends \WP_UnitTestCase {
	public function test_stream_messages_yields_insert_event_for_compose_request(): void {
		$provider = new MockProvider( STARTER_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
		$events   = iterator_to_array(
			$provider->stream_messages( [
				'tools'    => [ [ 'name' => 'insert_block' ] ],
				'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Add a paragraph that says hi' ] ] ] ],
			] )
		);
		$types = array_column( $events, 'type' );
		$this->assertContains( 'content_block_start', $types );
		$this->assertContains( 'message_stop',        $types );

		$tools = array_filter( $events, fn( $e ) => ( $e['type'] ?? '' ) === 'content_block_start' && ( $e['content_block']['type'] ?? '' ) === 'tool_use' );
		$this->assertNotEmpty( $tools );
		$first = array_values( $tools )[0];
		$this->assertSame( 'insert_block', $first['content_block']['name'] );
	}

	public function test_stream_messages_yields_update_event_when_selection_present(): void {
		$provider = new MockProvider( STARTER_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
		$events   = iterator_to_array(
			$provider->stream_messages( [
				'tools'    => [ [ 'name' => 'update_block' ] ],
				'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Shorten the selected paragraph (selected_block.clientId=abc)' ] ] ] ],
			] )
		);
		$tools = array_filter( $events, fn( $e ) => ( $e['type'] ?? '' ) === 'content_block_start' && ( $e['content_block']['type'] ?? '' ) === 'tool_use' );
		$first = array_values( $tools )[0];
		$this->assertSame( 'update_block', $first['content_block']['name'] );
	}
}
