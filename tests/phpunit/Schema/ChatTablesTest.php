<?php
namespace PedimentAi\Tests\Schema;

class ChatTablesTest extends \WP_UnitTestCase {
	public function test_chat_tables_exist_after_install(): void {
		\pediment_ai_install_tables();
		global $wpdb;
		$conv = $wpdb->prefix . 'pediment_ai_chat_conversations';
		$msgs = $wpdb->prefix . 'pediment_ai_chat_messages';
		$this->assertSame( $conv, $wpdb->get_var( "SHOW TABLES LIKE '{$conv}'" ) );
		$this->assertSame( $msgs, $wpdb->get_var( "SHOW TABLES LIKE '{$msgs}'" ) );
	}

	public function test_chat_tables_have_expected_columns(): void {
		\pediment_ai_install_tables();
		global $wpdb;
		$cols = array_column(
			$wpdb->get_results( "DESCRIBE {$wpdb->prefix}pediment_ai_chat_conversations", ARRAY_A ),
			'Field'
		);
		$this->assertSame( [ 'id', 'post_id', 'user_id', 'created_at', 'updated_at' ], $cols );

		$cols = array_column(
			$wpdb->get_results( "DESCRIBE {$wpdb->prefix}pediment_ai_chat_messages", ARRAY_A ),
			'Field'
		);
		$this->assertContains( 'role',       $cols );
		$this->assertContains( 'status',     $cols );
		$this->assertContains( 'content',    $cols );
		$this->assertContains( 'tool_calls', $cols );
	}
}
