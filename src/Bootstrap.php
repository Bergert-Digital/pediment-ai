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

		add_action(
			'rest_api_init',
			static function () {
				( new \StarterAi\Rest\ComposeController() )->register();
				( new \StarterAi\Rest\EditController() )->register();
				( new \StarterAi\Rest\RefineController() )->register();
				( new \StarterAi\Rest\StatusController() )->register();
			}
		);

		add_action(
			'starter_ai_job_run',
			static function ( int $job_id ) {
				$store    = new \StarterAi\Jobs\JobStore();
				$provider = apply_filters(
					'starter_ai_provider',
					new \StarterAi\Anthropic\Client(
						(string) ( defined( 'ANTHROPIC_API_KEY' ) ? ANTHROPIC_API_KEY : get_option( 'starter_ai_api_key', '' ) )
					)
				);
				( new \StarterAi\Jobs\ComposeJob( $store, $provider ) )->run( $job_id );
			},
			10,
			1
		);
	}
}
