<?php
/**
 * Runs when the plugin is deleted from wp-admin. Drops AI plugin tables.
 *
 * @package StarterAi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}starter_ai_jobs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}starter_ai_usage" );

delete_option( 'starter_ai_settings' );

wp_clear_scheduled_hook( 'starter_ai_job_run' );
