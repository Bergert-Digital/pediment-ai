<?php
namespace PedimentAi\Tests;

use PedimentAi\Chat\ConversationStore;

class ConversationStoreTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		// DDL is not rolled back between tests; idempotent dbDelta is safe to re-run.
		pediment_ai_install_tables();
	}

	public function test_attachments_round_trip(): void {
		$store = new ConversationStore();
		$conv  = $store->getOrCreate( 1, 1 );
		$mid   = $store->appendUserMessage( $conv['id'], 'build this', [
			[ 'media_type' => 'image/png', 'data' => 'AAAB' ],
		] );

		$atts = $store->getAttachments( $mid );
		$this->assertCount( 1, $atts );
		$this->assertSame( 'image/png', $atts[0]['media_type'] );
		$this->assertSame( 'AAAB', $atts[0]['data'] );
	}

	public function test_user_message_result_includes_attachments(): void {
		$store = new ConversationStore();
		$conv  = $store->getOrCreate( 2, 1 );
		$store->appendUserMessage( $conv['id'], 'with image', [
			[ 'media_type' => 'image/jpeg', 'data' => 'ZZZZ' ],
		] );

		$reloaded = $store->getOrCreate( 2, 1 );
		$userMsg  = $reloaded['messages'][0];
		$this->assertSame( 'user', $userMsg['role'] );
		$this->assertSame( 'image/jpeg', $userMsg['attachments'][0]['media_type'] );
		$this->assertSame( 'ZZZZ', $userMsg['attachments'][0]['data'] );
	}

	public function test_clear_removes_attachments(): void {
		$store = new ConversationStore();
		$conv  = $store->getOrCreate( 3, 1 );
		$mid   = $store->appendUserMessage( $conv['id'], 'x', [
			[ 'media_type' => 'image/png', 'data' => 'QQQQ' ],
		] );

		$store->clear( $conv['id'] );
		$this->assertSame( [], $store->getAttachments( $mid ) );
	}
}
