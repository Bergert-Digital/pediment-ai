<?php
/**
 * Runs when the plugin is deleted from wp-admin. Drops AI plugin tables.
 *
 * @package PedimentAi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pediment_ai_jobs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pediment_ai_usage" );

delete_option( 'pediment_ai_settings' );

wp_clear_scheduled_hook( 'pediment_ai_job_run' );
