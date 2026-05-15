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

		add_action( 'admin_notices', [ new \StarterAi\Activation\StreamingCheck(), 'renderNotice' ] );

		add_action( 'enqueue_block_editor_assets', static function () {
			$asset_path = STARTER_AI_PLUGIN_DIR . '/build/index.asset.php';
			if ( ! file_exists( $asset_path ) ) {
				return;
			}
			$asset = include $asset_path;
			// Older WP installs don't register react-jsx-runtime; bundles built by @wordpress/scripts depend on it.
			// Register our own shim so the dep resolves and the bundle's JSX runtime gets a working global.
			if ( in_array( 'react-jsx-runtime', $asset['dependencies'] ?? [], true ) && ! wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
				wp_register_script(
					'react-jsx-runtime',
					STARTER_AI_PLUGIN_URL . 'assets/react-jsx-runtime-shim.js',
					[ 'react' ],
					STARTER_AI_VERSION,
					true
				);
			}
			wp_enqueue_script(
				'starter-ai-editor',
				STARTER_AI_PLUGIN_URL . 'build/index.js',
				$asset['dependencies'] ?? [],
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
			$val = ( new \StarterAi\Settings\OptionsStore() )->get( 'model_edit', '' );
			return '' !== $val ? $val : $default;
		} );
		add_filter( 'starter_ai_model_refine', static function ( $default ) {
			$val = ( new \StarterAi\Settings\OptionsStore() )->get( 'model_refine', '' );
			return '' !== $val ? $val : $default;
		} );

		add_action(
			'rest_api_init',
			static function () {
				( new \StarterAi\Rest\ChatController() )->register();
			}
		);
	}
}
