<?php
/**
 * Database table installer for the Starter AI plugin.
 *
 * @package StarterAi
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates (or upgrades) the wp_starter_ai_jobs and wp_starter_ai_usage tables.
 */
function starter_ai_install_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$jobs    = $wpdb->prefix . 'starter_ai_jobs';
	$usage   = $wpdb->prefix . 'starter_ai_usage';

	$sql_jobs = "CREATE TABLE {$jobs} (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id bigint(20) UNSIGNED NOT NULL,
		kind varchar(20) NOT NULL,
		status varchar(20) NOT NULL,
		payload longtext NOT NULL,
		events_json longtext NULL,
		result_json longtext NULL,
		error_message text NULL,
		created_at datetime NOT NULL,
		completed_at datetime NULL,
		PRIMARY KEY  (id),
		KEY status_idx (status),
		KEY user_idx (user_id)
	) {$charset};";

	$sql_usage = "CREATE TABLE {$usage} (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id bigint(20) UNSIGNED NOT NULL,
		kind varchar(20) NOT NULL,
		model varchar(60) NOT NULL,
		input_tokens int NOT NULL DEFAULT 0,
		output_tokens int NOT NULL DEFAULT 0,
		cache_read_tokens int NOT NULL DEFAULT 0,
		cache_write_tokens int NOT NULL DEFAULT 0,
		web_fetch_count int NOT NULL DEFAULT 0,
		cost_usd decimal(10,6) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY user_idx (user_id),
		KEY created_idx (created_at)
	) {$charset};";

	dbDelta( $sql_jobs );
	dbDelta( $sql_usage );

	update_option( 'starter_ai_db_version', STARTER_AI_VERSION );
}
