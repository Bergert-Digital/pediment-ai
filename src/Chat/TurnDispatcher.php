<?php
/**
 * Dispatches a chat turn to run in a separate (loopback) request so the
 * starting request can return immediately and the poller can stream.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TurnDispatcher {
	private const TTL = 300; // seconds; a turn must start within 5 min.

	private function tokenKey( int $turn_id ): string {
		return 'starter_ai_turn_token_' . $turn_id;
	}

	public function mintToken( int $turn_id ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( $this->tokenKey( $turn_id ), $token, self::TTL );
		return $token;
	}

	public function consumeToken( int $turn_id, string $token ): bool {
		$stored = get_transient( $this->tokenKey( $turn_id ) );
		if ( ! is_string( $stored ) || '' === $stored || ! hash_equals( $stored, $token ) ) {
			return false;
		}
		delete_transient( $this->tokenKey( $turn_id ) );
		return true;
	}

	private function inputKey( int $turn_id ): string {
		return 'starter_ai_turn_input_' . $turn_id;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function stashInput( int $turn_id, array $payload ): void {
		set_transient( $this->inputKey( $turn_id ), $payload, self::TTL );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function takeInput( int $turn_id ): ?array {
		$v = get_transient( $this->inputKey( $turn_id ) );
		if ( ! is_array( $v ) ) {
			return null;
		}
		delete_transient( $this->inputKey( $turn_id ) );
		return $v;
	}

	/**
	 * Loopback base URL. Defaults to the site home. Override for containerised
	 * dev (e.g. wp-env: define STARTER_AI_LOOPBACK_URL = 'http://127.0.0.1')
	 * because the mapped host port is not reachable from inside the container.
	 */
	public function loopbackUrl(): string {
		$base = defined( 'STARTER_AI_LOOPBACK_URL' ) ? (string) STARTER_AI_LOOPBACK_URL : home_url();
		/**
		 * Filter the loopback base URL used to run chat turns out-of-band.
		 *
		 * @param string $base Base origin, no trailing path.
		 */
		return (string) apply_filters( 'starter_ai_loopback_url', $base );
	}

	public function dispatch( int $turn_id, string $token ): void {
		$base = rtrim( $this->loopbackUrl(), '/' );
		$url  = $base . '/?rest_route=' . rawurlencode( '/' . \StarterAi\Rest\ChatController::NS . '/chat/turns/' . $turn_id . '/run' );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'headers'   => array_filter( [
				'X-Starter-Ai-Token' => $token,
				'Host'               => $host,
			] ),
			'body'      => [ 'turn_id' => $turn_id ],
		] );
	}
}
