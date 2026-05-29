<?php
/**
 * Token-to-USD pricing for Anthropic models.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimates per-call cost from token usage.
 */
final class Pricing {
	/**
	 * USD per 1M tokens.
	 *
	 * @var array<string, array{input:float, output:float, cache_read:float, cache_write:float}>
	 */
	private const TABLE = [
		'claude-sonnet-4-6' => [ 'input' => 3.00,  'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75 ],
		'claude-haiku-4-5'  => [ 'input' => 0.80,  'output' => 4.00,  'cache_read' => 0.08, 'cache_write' => 1.00 ],
		'claude-opus-4-7'   => [ 'input' => 15.00, 'output' => 75.00, 'cache_read' => 1.50, 'cache_write' => 18.75 ],
	];

	public const WEB_FETCH_USD_PER_CALL = 0.01;

	public static function estimate(
		string $model,
		int $input_tokens,
		int $output_tokens,
		int $cache_read = 0,
		int $cache_write = 0,
		int $web_fetch_count = 0
	): float {
		$rates = self::TABLE[ $model ] ?? self::TABLE['claude-sonnet-4-6'];
		$cost  = 0.0;
		$cost += ( $input_tokens  / 1_000_000 ) * $rates['input'];
		$cost += ( $output_tokens / 1_000_000 ) * $rates['output'];
		$cost += ( $cache_read    / 1_000_000 ) * $rates['cache_read'];
		$cost += ( $cache_write   / 1_000_000 ) * $rates['cache_write'];
		$cost += $web_fetch_count * self::WEB_FETCH_USD_PER_CALL;
		return round( $cost, 6 );
	}
}
