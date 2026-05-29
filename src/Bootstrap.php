<?php
/**
 * Bootstrap class for the Pediment AI plugin.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi;

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
			\PedimentAi\Anthropic\SchemaBuilder::invalidate();
			return $args;
		} );

		add_filter( 'pediment_ai_provider', static function ( $default ) {
			$mock_const   = defined( 'PEDIMENT_AI_MOCK' ) && PEDIMENT_AI_MOCK;
			$mock_setting = (bool) ( new \PedimentAi\Settings\OptionsStore() )->get( 'mock_mode', false );
			if ( $mock_const || $mock_setting ) {
				return new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
			}
			return $default;
		} );

		( new \PedimentAi\Settings\Page() )->register();

		add_action( 'enqueue_block_editor_assets', static function () {
			$asset_path = PEDIMENT_AI_PLUGIN_DIR . '/build/index.asset.php';
			if ( ! file_exists( $asset_path ) ) {
				return;
			}
			$asset = include $asset_path;
			// Older WP installs don't register react-jsx-runtime; bundles built by @wordpress/scripts depend on it.
			// Register our own shim so the dep resolves and the bundle's JSX runtime gets a working global.
			if ( in_array( 'react-jsx-runtime', $asset['dependencies'] ?? [], true ) && ! wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
				wp_register_script(
					'react-jsx-runtime',
					PEDIMENT_AI_PLUGIN_URL . 'assets/react-jsx-runtime-shim.js',
					[ 'react' ],
					PEDIMENT_AI_VERSION,
					true
				);
			}
			wp_enqueue_script(
				'pediment-ai-editor',
				PEDIMENT_AI_PLUGIN_URL . 'build/index.js',
				$asset['dependencies'] ?? [],
				$asset['version'] ?? PEDIMENT_AI_VERSION,
				true
			);
			if ( file_exists( PEDIMENT_AI_PLUGIN_DIR . '/build/index.css' ) ) {
				wp_enqueue_style(
					'pediment-ai-editor',
					PEDIMENT_AI_PLUGIN_URL . 'build/index.css',
					[],
					$asset['version'] ?? PEDIMENT_AI_VERSION
				);
			}
		} );

		add_filter( 'pediment_ai_model_compose', static function ( $default ) {
			$val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_compose', '' );
			return '' !== $val ? $val : $default;
		} );
		add_filter( 'pediment_ai_model_edit', static function ( $default ) {
			$val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_edit', '' );
			return '' !== $val ? $val : $default;
		} );
		add_filter( 'pediment_ai_model_refine', static function ( $default ) {
			$val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_refine', '' );
			return '' !== $val ? $val : $default;
		} );

		add_action(
			'rest_api_init',
			static function () {
				( new \PedimentAi\Rest\ChatController() )->register();
			}
		);
	}
}
