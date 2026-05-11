<?php
/**
 * Bootstrap class for the Starter AI plugin.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's hooks, REST routes, and settings page.
 */
final class Bootstrap {
	/**
	 * Registers hooks. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', static function ( $args ) {
			\StarterAi\Anthropic\SchemaBuilder::invalidate();
			return $args;
		} );
	}
}
