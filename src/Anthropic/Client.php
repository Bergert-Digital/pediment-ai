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
final class Client {
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
}
