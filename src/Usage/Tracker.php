<?php
/**
 * Records per-call telemetry to the wp_pediment_ai_usage table.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records usage events and aggregates month-to-date totals.
 */
final class Tracker {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'pediment_ai_usage';
	}

	/**
	 * @param int                 $user_id         Job owner.
	 * @param string              $kind            'compose' | 'edit' | 'refine'.
	 * @param array<string,mixed> $response        Anthropic response (contains `model` + `usage`).
	 * @param int                 $web_fetch_count Number of web_fetch calls in the response.
	 */
	public function record( int $user_id, string $kind, array $response, int $web_fetch_count = 0 ): void {
		global $wpdb;

		$model       = (string) ( $response['model'] ?? 'unknown' );
		$usage       = (array) ( $response['usage'] ?? [] );
		$input       = (int) ( $usage['input_tokens']                ?? 0 );
		$output      = (int) ( $usage['output_tokens']               ?? 0 );
		$cache_read  = (int) ( $usage['cache_read_input_tokens']     ?? 0 );
		$cache_write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );

		$cost = Pricing::estimate( $model, $input, $output, $cache_read, $cache_write, $web_fetch_count );

		$wpdb->insert(
			$this->table,
			[
				'user_id'            => $user_id,
				'kind'               => $kind,
				'model'              => $model,
				'input_tokens'       => $input,
				'output_tokens'      => $output,
				'cache_read_tokens'  => $cache_read,
				'cache_write_tokens' => $cache_write,
				'web_fetch_count'    => $web_fetch_count,
				'cost_usd'           => $cost,
				'created_at'         => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * @return array{input_tokens:int, output_tokens:int, cache_read_tokens:int, cache_write_tokens:int, web_fetch_count:int, cost_usd:float}
	 */
	public function totalsThisMonth(): array {
		global $wpdb;
		$since = gmdate( 'Y-m-01 00:00:00' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE( SUM( input_tokens ),       0 ) AS input_tokens,
					COALESCE( SUM( output_tokens ),      0 ) AS output_tokens,
					COALESCE( SUM( cache_read_tokens ),  0 ) AS cache_read_tokens,
					COALESCE( SUM( cache_write_tokens ), 0 ) AS cache_write_tokens,
					COALESCE( SUM( web_fetch_count ),    0 ) AS web_fetch_count,
					COALESCE( SUM( cost_usd ),           0 ) AS cost_usd
				FROM {$this->table}
				WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		return [
			'input_tokens'       => (int) $row['input_tokens'],
			'output_tokens'      => (int) $row['output_tokens'],
			'cache_read_tokens'  => (int) $row['cache_read_tokens'],
			'cache_write_tokens' => (int) $row['cache_write_tokens'],
			'web_fetch_count'    => (int) $row['web_fetch_count'],
			'cost_usd'           => (float) $row['cost_usd'],
		];
	}
}
