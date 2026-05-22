<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\ConversationStore;

class ConversationStoreTest extends \WP_UnitTestCase {
	private ConversationStore $store;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );
		$this->store = new ConversationStore();
	}

	public function test_get_or_create_creates_when_missing(): void {
		$conv = $this->store->getOrCreate( 42, 7 );
		$this->assertSame( 42, $conv['post_id'] );
		$this->assertSame( 7,  $conv['user_id'] );
		$this->assertGreaterThan( 0, $conv['id'] );
		$this->assertSame( [], $conv['messages'] );
	}

	public function test_get_or_create_returns_existing(): void {
		$first  = $this->store->getOrCreate( 42, 7 );
		$second = $this->store->getOrCreate( 42, 7 );
		$this->assertSame( $first['id'], $second['id'] );
	}

	public function test_get_or_create_scopes_per_user(): void {
		$a = $this->store->getOrCreate( 42, 7 );
		$b = $this->store->getOrCreate( 42, 8 );
		$this->assertNotSame( $a['id'], $b['id'] );
	}

	public function test_find_by_id_returns_conversation_when_present(): void {
		$created = $this->store->getOrCreate( 10, 5 );
		$found   = $this->store->findById( $created['id'] );
		$this->assertNotNull( $found );
		$this->assertSame( $created['id'], $found['id'] );
		$this->assertSame( 10, $found['post_id'] );
		$this->assertSame( 5,  $found['user_id'] );
	}

	public function test_find_by_id_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->store->findById( 999_999 ) );
	}

	public function test_append_user_message_writes_row(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->appendUserMessage( $conv['id'], 'Hello world' );
		$loaded = $this->store->findById( $conv['id'] );
		$this->assertCount( 1, $loaded['messages'] );
		$this->assertSame( 'user',    $loaded['messages'][0]['role'] );
		$this->assertSame( 'complete', $loaded['messages'][0]['status'] );
		$this->assertSame( 'Hello world', $loaded['messages'][0]['content'] );
	}

	public function test_start_assistant_turn_returns_streaming_row(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$loaded = $this->store->findById( $conv['id'] );
		$this->assertSame( 'assistant', $loaded['messages'][0]['role'] );
		$this->assertSame( 'streaming', $loaded['messages'][0]['status'] );
		$this->assertSame( '',          $loaded['messages'][0]['content'] );
	}

	public function test_append_assistant_delta_is_cumulative(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->appendAssistantDelta( $id, 'Hel' );
		$this->store->appendAssistantDelta( $id, 'lo!' );
		$msg = $this->store->getMessage( $id );
		$this->assertSame( 'Hello!', $msg['content'] );
	}

	public function test_record_tool_call_appends_to_json(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->recordToolCall( $id, [ 'tool' => 'insert_block', 'input' => [ 'foo' => 'bar' ] ] );
		$this->store->recordToolCall( $id, [ 'tool' => 'delete_block', 'input' => [ 'client_id' => 'x' ] ] );
		$msg = $this->store->getMessage( $id );
		$this->assertCount( 2, $msg['tool_calls'] );
		$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );
	}

	public function test_complete_marks_status(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->complete( $id );
		$this->assertSame( 'complete', $this->store->getMessage( $id )['status'] );
	}

	public function test_fail_marks_error_and_status(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->fail( $id, 'rate_limit', 'Slow down' );
		$msg = $this->store->getMessage( $id );
		$this->assertSame( 'error', $msg['status'] );
		$this->assertSame( 'rate_limit', $msg['error']['code'] );
	}

	public function test_abort_sets_status_and_is_visible_to_polling(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$id   = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->abort( $id );
		$this->assertSame( 'aborted', $this->store->getMessage( $id )['status'] );
		$this->assertTrue( $this->store->isAborted( $id ) );
	}

	public function test_clear_deletes_all_messages_for_conversation(): void {
		$conv = $this->store->getOrCreate( 1, 1 );
		$this->store->appendUserMessage( $conv['id'], 'a' );
		$this->store->appendUserMessage( $conv['id'], 'b' );
		$this->store->clear( $conv['id'] );
		$this->assertSame( [], $this->store->findById( $conv['id'] )['messages'] );
	}
}
