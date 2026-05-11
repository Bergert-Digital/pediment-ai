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

		add_filter( 'starter_ai_provider', static function ( $default ) {
			$mock_const   = defined( 'STARTER_AI_MOCK' ) && STARTER_AI_MOCK;
			$mock_setting = (bool) ( new \StarterAi\Settings\OptionsStore() )->get( 'mock_mode', false );
			if ( $mock_const || $mock_setting ) {
				return new \StarterAi\Mock\MockProvider( STARTER_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
			}
			return $default;
		} );

		( new \StarterAi\Settings\Page() )->register();

		add_action( 'enqueue_block_editor_assets', static function () {
			$asset_path = STARTER_AI_PLUGIN_DIR . '/build/index.asset.php';
			if ( ! file_exists( $asset_path ) ) {
				return;
			}
			$asset = include $asset_path;
			// Drop any deps WP doesn't register on this install; otherwise WP silently skips our script.
			$deps = array_values( array_filter( $asset['dependencies'] ?? [], static function ( $handle ) {
				return wp_script_is( $handle, 'registered' );
			} ) );
			wp_enqueue_script(
				'starter-ai-editor',
				STARTER_AI_PLUGIN_URL . 'build/index.js',
				$deps,
				$asset['version'] ?? STARTER_AI_VERSION,
				true
			);
			if ( file_exists( STARTER_AI_PLUGIN_DIR . '/build/index.css' ) ) {
				wp_enqueue_style(
					'starter-ai-editor',
					STARTER_AI_PLUGIN_URL . 'build/index.css',
					[],
					$asset['version'] ?? STARTER_AI_VERSION
				);
			}
		} );

		add_filter( 'starter_ai_model_compose', static function ( $default ) {
			$val = ( new \StarterAi\Settings\OptionsStore() )->get( 'model_compose', '' );
			return '' !== $val ? $val : $default;
		} );
		add_filter( 'starter_ai_model_edit', static function ( $default ) {
			$val = ( new \StarterAi\Settings\OptionsStore() )->get( 'model_compose', '' );
			return '' !== $val ? $val : $default;
		} );
		add_filter( 'starter_ai_model_refine', static function ( $default ) {
			$val = ( new \StarterAi\Settings\OptionsStore() )->get( 'model_refine', '' );
			return '' !== $val ? $val : $default;
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
			'starter_ai_job_completed',
			static function ( int $job_id, array $response, string $kind ) {
				$job = ( new \StarterAi\Jobs\JobStore() )->getById( $job_id );
				if ( ! $job ) {
					return;
				}
				$fetched = count( $job['result']['urls_fetched'] ?? [] );
				( new \StarterAi\Usage\Tracker() )->record( $job['user_id'], $kind, $response, $fetched );
			},
			10,
			3
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
