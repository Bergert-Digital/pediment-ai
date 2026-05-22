<?php
/**
 * Per-user, per-kind rate limiter backed by WP transients.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sliding-window-ish rate limit per user per call kind.
 */
final class RateLimiter {
	public const DEFAULTS       = [ 'compose' => 30, 'edit' => 30, 'refine' => 200 ];
	public const WINDOW_SECONDS = HOUR_IN_SECONDS;

	/** @param array<string,int> $limits Per-kind limits. */
	public function __construct( private readonly array $limits = self::DEFAULTS ) {}

	public function consume( int $user_id, string $kind ): bool {
		$key   = $this->key( $user_id, $kind );
		$count = (int) get_transient( $key );
		$limit = $this->limits[ $kind ] ?? self::DEFAULTS[ $kind ] ?? 0;
		if ( $limit > 0 && $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, self::WINDOW_SECONDS );
		return true;
	}

	public function remaining( int $user_id, string $kind ): int {
		$count = (int) get_transient( $this->key( $user_id, $kind ) );
		$limit = $this->limits[ $kind ] ?? self::DEFAULTS[ $kind ] ?? 0;
		return max( 0, $limit - $count );
	}

	private function key( int $user_id, string $kind ): string {
		return "pediment_ai_rl_{$user_id}_{$kind}";
	}
}
