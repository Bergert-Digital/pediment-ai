<?php
/**
 * Anthropic Messages API client.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Anthropic;

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
			'pediment_ai_anthropic_' . $status,
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
			return new \WP_Error( 'pediment_ai_curl_init', 'curl_init failed' );
		}

		$parser = new SseParser();
		$queue  = [];
		$raw    = '';

		curl_setopt_array( $ch, [
			CURLOPT_URL           => rtrim( $this->baseUrl, '/' ) . '/v1/messages',
			CURLOPT_POST          => true,
			CURLOPT_HTTPHEADER    => [
				'x-api-key: ' . $this->apiKey,
				'anthropic-version: ' . self::API_VERSION,
				'content-type: application/json',
				'accept: text/event-stream',
			],
			CURLOPT_POSTFIELDS    => wp_json_encode( $args ),
			CURLOPT_TIMEOUT       => $this->timeout,
			// Invoked by libcurl as bytes arrive — feed the incremental parser
			// so events become available mid-transfer, not after it completes.
			CURLOPT_WRITEFUNCTION => function ( $h, $chunk ) use ( $parser, &$queue, &$raw ) {
				$raw .= $chunk;
				foreach ( $parser->push( $chunk ) as $event ) {
					$queue[] = $event;
				}
				return strlen( $chunk );
			},
		] );

		$mh = curl_multi_init();
		curl_multi_add_handle( $mh, $ch );

		// Pump until the HTTP status is known (headers parsed) or the transfer ends.
		$running = null;
		do {
			curl_multi_exec( $mh, $running );
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			if ( $status > 0 ) {
				break;
			}
			if ( $running ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running );

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

		if ( 0 === $status ) {
			$err = curl_error( $ch ) ?: 'cURL failed';
			curl_multi_remove_handle( $mh, $ch );
			curl_multi_close( $mh );
			return new \WP_Error( 'pediment_ai_curl_failed', $err );
		}

		if ( $status < 200 || $status >= 300 ) {
			// Error responses are a small JSON body, not SSE — drain then report.
			while ( $running ) {
				curl_multi_exec( $mh, $running );
				curl_multi_select( $mh, 1.0 );
			}
			curl_multi_remove_handle( $mh, $ch );
			curl_multi_close( $mh );
			$body = json_decode( $raw, true );
			return new \WP_Error(
				'pediment_ai_anthropic_' . $status,
				is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Anthropic API error',
				[ 'status' => $status ]
			);
		}

		return ( function () use ( $mh, $ch, $parser, &$queue, &$running ) {
			try {
				while ( $running ) {
					curl_multi_exec( $mh, $running );
					while ( $queue ) {
						yield array_shift( $queue );
					}
					if ( $running ) {
						curl_multi_select( $mh, 1.0 );
					}
				}
				// Transfer finished — emit anything the last exec produced.
				while ( $queue ) {
					yield array_shift( $queue );
				}
				foreach ( $parser->flush() as $event ) {
					yield $event;
				}
			} finally {
				curl_multi_remove_handle( $mh, $ch );
				curl_multi_close( $mh );
			}
		} )();
	}

	/**
	 * Parses a complete SSE blob into a generator of decoded `data:` events.
	 * Public so tests can drive the parse without a real HTTP call.
	 *
	 * @param string $sse
	 * @return \Generator<int,array<string,mixed>>
	 */
	public function parseSseStream( string $sse ): \Generator {
		$parser = new SseParser();
		foreach ( $parser->push( $sse ) as $event ) {
			yield $event;
		}
		foreach ( $parser->flush() as $event ) {
			yield $event;
		}
	}
}
