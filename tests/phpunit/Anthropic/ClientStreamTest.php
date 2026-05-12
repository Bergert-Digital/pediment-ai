<?php
namespace StarterAi\Tests\Anthropic;

use StarterAi\Anthropic\Client;

class ClientStreamTest extends \WP_UnitTestCase {
	public function test_stream_messages_parses_sse_events(): void {
		$sse = implode( '', [
			"event: message_start\n",
			"data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"model\":\"claude-sonnet-4-6\",\"usage\":{\"input_tokens\":10}}}\n\n",
			"event: content_block_start\n",
			"data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
			"event: content_block_delta\n",
			"data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n",
			"event: content_block_delta\n",
			"data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" world\"}}\n\n",
			"event: message_delta\n",
			"data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
			"event: message_stop\n",
			"data: {\"type\":\"message_stop\"}\n\n",
		] );

		$client = new Client( 'k' );
		$events = iterator_to_array( $client->parseSseStream( $sse ) );
		$types  = array_column( $events, 'type' );

		$this->assertContains( 'message_start',         $types );
		$this->assertContains( 'content_block_delta',   $types );
		$this->assertContains( 'message_delta',         $types );
		$this->assertContains( 'message_stop',          $types );
	}
}
