<?php
namespace StarterAi\Tests\Schema;

class TablesTest extends \WP_UnitTestCase {
	public function test_tables_exist_after_install(): void {
		\starter_ai_install_tables();
		global $wpdb;
		$jobs  = $wpdb->prefix . 'starter_ai_jobs';
		$usage = $wpdb->prefix . 'starter_ai_usage';
		$this->assertSame( $jobs,  $wpdb->get_var( "SHOW TABLES LIKE '{$jobs}'" ) );
		$this->assertSame( $usage, $wpdb->get_var( "SHOW TABLES LIKE '{$usage}'" ) );
	}

	public function test_install_is_idempotent(): void {
		\starter_ai_install_tables();
		\starter_ai_install_tables();
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}starter_ai_jobs" ) );
	}
}
