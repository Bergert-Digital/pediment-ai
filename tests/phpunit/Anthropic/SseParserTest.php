<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\SseParser;

class SseParserTest extends \WP_UnitTestCase {
	public function test_push_full_blob_returns_all_events_in_order(): void {
		$sse = implode( '', [
			"event: a\ndata: {\"type\":\"a\",\"n\":1}\n\n",
			"event: b\ndata: {\"type\":\"b\",\"n\":2}\n\n",
		] );

		$events = ( new SseParser() )->push( $sse );

		$this->assertSame( [ 'a', 'b' ], array_column( $events, 'type' ) );
		$this->assertSame( 1, $events[0]['n'] );
	}

	public function test_push_split_mid_event_defers_until_complete(): void {
		$p = new SseParser();

		// First chunk cuts off mid-event (no terminating blank line yet).
		$first = $p->push( "event: x\ndata: {\"type\":\"x\"," );
		$this->assertSame( [], $first, 'Incomplete event must not be emitted yet' );

		// Remainder completes the event.
		$second = $p->push( "\"done\":true}\n\n" );
		$this->assertCount( 1, $second );
		$this->assertSame( 'x', $second[0]['type'] );
		$this->assertTrue( $second[0]['done'] );
	}

	public function test_push_skips_non_json_data_lines(): void {
		$events = ( new SseParser() )->push( "data: [DONE]\n\ndata: {\"type\":\"ok\"}\n\n" );

		$this->assertSame( [ 'ok' ], array_column( $events, 'type' ) );
	}

	public function test_flush_emits_trailing_block_without_terminator(): void {
		$p = new SseParser();

		$this->assertSame( [], $p->push( "data: {\"type\":\"tail\"}" ), 'No blank line yet → nothing from push' );
		$flushed = $p->flush();

		$this->assertCount( 1, $flushed );
		$this->assertSame( 'tail', $flushed[0]['type'] );
	}
}
