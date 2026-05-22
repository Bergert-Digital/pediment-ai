<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\Client;

class ClientTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		remove_all_filters( 'pre_http_request' );
	}

	public function test_sends_message_with_correct_headers_and_body(): void {
		$captured = null;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = compact( 'args', 'url' );
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [
					'id'           => 'msg_01',
					'type'         => 'message',
					'role'         => 'assistant',
					'model'        => 'claude-sonnet-4-6',
					'content'      => [ [ 'type' => 'text', 'text' => 'hi' ] ],
					'stop_reason'  => 'end_turn',
					'usage'        => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
				] ),
				'headers' => [],
			];
		}, 10, 3 );

		$client = new Client( 'test-key', 'https://api.anthropic.com' );
		$body   = $client->messages( [
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => 1024,
			'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
		] );

		$this->assertSame( 'msg_01', $body['id'] );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $captured['url'] );
		$this->assertSame( 'POST', $captured['args']['method'] );
		$this->assertSame( 'test-key',         $captured['args']['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01',       $captured['args']['headers']['anthropic-version'] );
		$this->assertSame( 'application/json', $captured['args']['headers']['content-type'] );

		$sent_body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'claude-sonnet-4-6', $sent_body['model'] );
	}

	public function test_returns_wp_error_on_http_error(): void {
		add_filter( 'pre_http_request', function () {
			return new \WP_Error( 'http_request_failed', 'Connection refused' );
		} );

		$client = new Client( 'test-key' );
		$result = $client->messages( [
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => 100,
			'messages'   => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_returns_wp_error_on_anthropic_4xx(): void {
		add_filter( 'pre_http_request', function () {
			return [
				'response' => [ 'code' => 400 ],
				'body'     => wp_json_encode( [
					'type'  => 'error',
					'error' => [ 'type' => 'invalid_request_error', 'message' => 'Invalid model' ],
				] ),
				'headers' => [],
			];
		} );

		$client = new Client( 'test-key' );
		$result = $client->messages( [ 'model' => 'bad', 'max_tokens' => 1, 'messages' => [] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'pediment_ai_anthropic_400', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'invalid_request_error', $data['error_type'] );
	}

	public function test_retries_once_on_429(): void {
		$calls = 0;
		add_filter( 'pre_http_request', function () use ( &$calls ) {
			$calls++;
			if ( $calls === 1 ) {
				return [
					'response' => [ 'code' => 429 ],
					'body'     => wp_json_encode( [ 'type' => 'error', 'error' => [ 'type' => 'rate_limit', 'message' => 'slow down' ] ] ),
					'headers'  => [],
				];
			}
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'id' => 'msg_retry', 'content' => [], 'usage' => [ 'input_tokens' => 1, 'output_tokens' => 1 ] ] ),
				'headers'  => [],
			];
		} );

		$client = new Client( 'test-key' );
		$body   = $client->messages( [ 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1, 'messages' => [] ] );
		$this->assertSame( 'msg_retry', $body['id'] );
		$this->assertSame( 2, $calls );
	}
}
