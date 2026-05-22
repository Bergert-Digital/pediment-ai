<?php
/**
 * Database table installer for the Pediment AI plugin.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates (or upgrades) the wp_pediment_ai_jobs and wp_pediment_ai_usage tables.
 */
function pediment_ai_install_tables(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$jobs    = $wpdb->prefix . 'pediment_ai_jobs';
	$usage   = $wpdb->prefix . 'pediment_ai_usage';

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

	$conv = $wpdb->prefix . 'pediment_ai_chat_conversations';
	$msgs = $wpdb->prefix . 'pediment_ai_chat_messages';

	$sql_conv = "CREATE TABLE {$conv} (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id bigint(20) UNSIGNED NOT NULL,
		user_id bigint(20) UNSIGNED NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY post_user_idx (post_id, user_id)
	) {$charset};";

	$sql_msgs = "CREATE TABLE {$msgs} (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		conversation_id bigint(20) UNSIGNED NOT NULL,
		role varchar(20) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'complete',
		content longtext NOT NULL,
		tool_calls longtext NULL,
		error longtext NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY conv_idx (conversation_id, id)
	) {$charset};";

	dbDelta( $sql_conv );
	dbDelta( $sql_msgs );

	update_option( 'pediment_ai_db_version', PEDIMENT_AI_VERSION );
}
