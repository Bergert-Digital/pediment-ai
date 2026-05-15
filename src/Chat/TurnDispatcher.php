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
}
