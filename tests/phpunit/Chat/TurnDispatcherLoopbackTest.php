<?php
namespace StarterAi\Tests\Chat;

use StarterAi\Chat\TurnDispatcher;

class TurnDispatcherLoopbackTest extends \WP_UnitTestCase {
	public function test_dispatch_fires_nonblocking_loopback_with_token_header(): void {
		$captured = [];
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = [ 'args' => $args, 'url' => $url ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );

		( new TurnDispatcher() )->dispatch( 77, 'tok-abc' );

		remove_all_filters( 'pre_http_request' );
		$this->assertStringContainsString( 'rest_route=', $captured['url'] );
		$this->assertStringContainsString( '/starter-ai/v1/chat/turns/77/run', urldecode( $captured['url'] ) );
		$this->assertFalse( $captured['args']['blocking'], 'must be non-blocking' );
		$this->assertSame( 'tok-abc', $captured['args']['headers']['X-Starter-Ai-Token'] );
		$this->assertLessThanOrEqual( 1.0, $captured['args']['timeout'] );
	}

	public function test_empty_token_does_not_fire_a_request(): void {
		$fired = false;
		add_filter( 'pre_http_request', function ( $pre ) use ( &$fired ) {
			$fired = true;
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		} );

		( new TurnDispatcher() )->dispatch( 9, '' );

		remove_all_filters( 'pre_http_request' );
		$this->assertFalse( $fired, 'empty token must not fire a loopback' );
	}

	public function test_loopback_base_is_filterable(): void {
		$seen = '';
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$seen ) {
			$seen = $url;
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );
		add_filter( 'starter_ai_loopback_url', fn() => 'http://127.0.0.1' );

		( new TurnDispatcher() )->dispatch( 5, 't' );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'starter_ai_loopback_url' );
		$this->assertStringStartsWith( 'http://127.0.0.1/?rest_route=', $seen );
	}
}
