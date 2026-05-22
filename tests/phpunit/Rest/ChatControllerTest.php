<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Chat\ConversationStore;
use PedimentAi\Rest\ChatController;

class ChatControllerTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;
	private int $post_id;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		// Explicit registration so the test doesn't depend on Bootstrap wiring (Task 12).
		( new ChatController() )->register();

		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		$this->post_id = $this->factory->post->create( [ 'post_author' => $user_id, 'post_status' => 'draft' ] );

		// Force the mock provider regardless of constants.
		add_filter( 'pediment_ai_provider', static fn() => new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );
	}

	public function test_get_conversation_creates_on_first_call(): void {
		$req = new \WP_REST_Request( 'GET', '/pediment-ai/v1/chat/conversations' );
		$req->set_param( 'post_id', $this->post_id );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertGreaterThan( 0, $res->get_data()['id'] );
		$this->assertSame( [], $res->get_data()['messages'] );
	}

	public function test_post_turn_returns_202_and_persists_user_message(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		$req  = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'message', 'Hi' );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 202, $res->get_status() );
		$this->assertGreaterThan( 0, $res->get_data()['turn_id'] );

		$loaded = ( new ConversationStore() )->findById( $conv['id'] );
		// startTurn dispatches the turn via non-blocking loopback (auto mode) and returns
		// immediately; the assistant row is created in 'streaming' status. We assert the
		// row exists and has the correct role — not that it completed.
		$this->assertGreaterThanOrEqual( 2, count( $loaded['messages'] ) );
		$this->assertSame( 'user',      $loaded['messages'][0]['role'] );
		$this->assertSame( 'assistant', $loaded['messages'][1]['role'] );
	}

	public function test_get_turn_returns_turn_state(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		$req  = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'message', 'Add a paragraph that says hi' );
		$post_res = $this->server->dispatch( $req );
		$turn_id  = $post_res->get_data()['turn_id'];

		$get = new \WP_REST_Request( 'GET', "/pediment-ai/v1/chat/turns/{$turn_id}" );
		$res = $this->server->dispatch( $get );
		$this->assertSame( 200, $res->get_status() );
		$this->assertContains( $res->get_data()['status'], [ 'streaming', 'complete' ] );
	}

	public function test_delete_turn_marks_aborted(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		// Manually create a streaming assistant turn and abort it immediately, without
		// going through startTurn — this tests the abort path independent of dispatch mode.
		$store   = new ConversationStore();
		$store->appendUserMessage( $conv['id'], 'x' );
		$turn_id = $store->startAssistantTurn( $conv['id'] );

		$del = new \WP_REST_Request( 'DELETE', "/pediment-ai/v1/chat/turns/{$turn_id}" );
		$res = $this->server->dispatch( $del );
		$this->assertSame( 204, $res->get_status() );
		$this->assertSame( 'aborted', $store->getMessage( $turn_id )['status'] );
	}

	public function test_delete_conversation_clears_messages(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		( new ConversationStore() )->appendUserMessage( $conv['id'], 'a' );

		$del = new \WP_REST_Request( 'DELETE', "/pediment-ai/v1/chat/conversations/{$conv['id']}" );
		$res = $this->server->dispatch( $del );
		$this->assertSame( 204, $res->get_status() );
		$this->assertSame( [], ( new ConversationStore() )->findById( $conv['id'] )['messages'] );
	}

	public function test_post_turn_rejects_for_post_user_cannot_edit(): void {
		$other = $this->factory->post->create( [ 'post_status' => 'draft', 'post_author' => 999 ] );
		// Drop to a user who cannot edit others' posts.
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );
		$conv = ( new ConversationStore() )->getOrCreate( $other, get_current_user_id() );

		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $other );
		$req->set_param( 'message', 'x' );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 403, $res->get_status() );
	}
}
