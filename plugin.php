<?php
/**
 * Plugin Name:       Pediment AI
 * Plugin URI:        https://github.com/bergert/pediment-ai
 * Description:       Gutenberg AI composer for pediment: compose, edit, and refine pages with Claude.
 * Version:           0.1.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jonas Bergert
 * Author URI:        https://bergert.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pediment-ai
 *
 * @package PedimentAi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEDIMENT_AI_VERSION', '0.1.6' );
define( 'PEDIMENT_AI_PLUGIN_FILE', __FILE__ );
define( 'PEDIMENT_AI_PLUGIN_DIR', __DIR__ );
define( 'PEDIMENT_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

// Action Scheduler's procedural API (as_schedule_single_action etc.) is registered
// by its bootstrap file, which Composer's autoloader does not execute.
if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once __DIR__ . '/src/Schema/tables.php';
register_activation_hook( PEDIMENT_AI_PLUGIN_FILE, 'pediment_ai_install_tables' );
add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'pediment_ai_db_version' ) !== PEDIMENT_AI_VERSION ) {
			pediment_ai_install_tables();
		}
	},
	5
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/wp-cli/DumpSchemaCommand.php';
	\WP_CLI::add_command( 'pediment-ai dump-schema', \PedimentAi\Cli\DumpSchemaCommand::class );
}

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\PedimentAi\\Bootstrap' ) ) {
			( new \PedimentAi\Bootstrap() )->register();
		}
	}
);
