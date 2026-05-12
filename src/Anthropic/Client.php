<?php
/**
 * Anthropic Messages API client.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around wp_remote_post against the Anthropic Messages API.
 */
final class Client implements ProviderInterface {
	private const API_VERSION    = '2023-06-01';
	private const DEFAULT_TIMEOUT = 90;

	/**
	 * @param string $apiKey  Anthropic API key.
	 * @param string $baseUrl Base URL for the API.
	 * @param int    $timeout HTTP timeout in seconds.
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.anthropic.com',
		private readonly int $timeout = self::DEFAULT_TIMEOUT
	) {}

	/**
	 * Calls POST /v1/messages.
	 *
	 * @param array<string,mixed> $args Anthropic Messages API request body.
	 * @return array<string,mixed>|\WP_Error Decoded response body or WP_Error.
	 */
	public function messages( array $args ) {
		return $this->postWithRetry( '/v1/messages', $args );
	}

	/**
	 * @param string              $path    Path under the base URL.
	 * @param array<string,mixed> $args    Request body.
	 * @param int                 $attempt Retry counter.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function postWithRetry( string $path, array $args, int $attempt = 0 ) {
		$response = wp_remote_post(
			rtrim( $this->baseUrl, '/' ) . $path,
			[
				'timeout' => $this->timeout,
				'headers' => [
					'x-api-key'         => $this->apiKey,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( $args ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$body   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $body ) ? $body : [];
		}

		if ( ( 429 === $status || ( $status >= 500 && $status < 600 ) ) && $attempt < 1 ) {
			usleep( 750_000 );
			return $this->postWithRetry( $path, $args, $attempt + 1 );
		}

		$error_type = is_array( $body ) && isset( $body['error']['type'] ) ? (string) $body['error']['type'] : 'unknown';
		$message    = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Anthropic API error';

		return new \WP_Error(
			'starter_ai_anthropic_' . $status,
			$message,
			[ 'error_type' => $error_type, 'status' => $status ]
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return iterable<int,array<string,mixed>>|\WP_Error
	 */
	public function stream_messages( array $args ) {
		$args['stream'] = true;

		$ch = curl_init();
		if ( false === $ch ) {
			return new \WP_Error( 'starter_ai_curl_init', 'curl_init failed' );
		}
		$buffer = '';
		curl_setopt_array( $ch, [
			CURLOPT_URL            => rtrim( $this->baseUrl, '/' ) . '/v1/messages',
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'x-api-key: ' . $this->apiKey,
				'anthropic-version: ' . self::API_VERSION,
				'content-type: application/json',
				'accept: text/event-stream',
			],
			CURLOPT_POSTFIELDS     => wp_json_encode( $args ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_WRITEFUNCTION  => function ( $h, $chunk ) use ( &$buffer ) {
				$buffer .= $chunk;
				return strlen( $chunk );
			},
		] );
		$ok      = curl_exec( $ch );
		$err     = curl_error( $ch );
		$status  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $ok ) {
			return new \WP_Error( 'starter_ai_curl_failed', $err ?: 'cURL failed' );
		}
		if ( $status < 200 || $status >= 300 ) {
			$body = json_decode( $buffer, true );
			return new \WP_Error(
				'starter_ai_anthropic_' . $status,
				is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Anthropic API error',
				[ 'status' => $status ]
			);
		}

		return $this->parseSseStream( $buffer );
	}

	/**
	 * Parses an SSE blob into a generator of decoded `data:` events.
	 * Public so tests can drive it without a real HTTP call.
	 *
	 * @param string $sse
	 * @return \Generator<int,array<string,mixed>>
	 */
	public function parseSseStream( string $sse ): \Generator {
		$blocks = preg_split( "/\r?\n\r?\n/", $sse );
		foreach ( (array) $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			foreach ( preg_split( "/\r?\n/", $block ) as $line ) {
				if ( str_starts_with( $line, 'data: ' ) ) {
					$payload = substr( $line, 6 );
					$decoded = json_decode( $payload, true );
					if ( is_array( $decoded ) ) {
						yield $decoded;
					}
				}
			}
		}
	}
}
