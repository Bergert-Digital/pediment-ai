<?php
/**
 * Plugin Name:       Starter AI
 * Plugin URI:        https://github.com/bergert/wp-starter-ai
 * Description:       Gutenberg AI composer for wp-starter-theme: compose, edit, and refine pages with Claude.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jonas Bergert
 * Author URI:        https://bergert.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       starter-ai
 *
 * @package StarterAi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STARTER_AI_VERSION', '0.1.0' );
define( 'STARTER_AI_PLUGIN_FILE', __FILE__ );
define( 'STARTER_AI_PLUGIN_DIR', __DIR__ );
define( 'STARTER_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\StarterAi\\Bootstrap' ) ) {
			( new \StarterAi\Bootstrap() )->register();
		}
	}
);
