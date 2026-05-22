<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\TurnDispatcher;

class RunTurnRouteTest extends \WP_UnitTestCase {
	private int $conv;
	private int $turn;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();

		// Bootstrap REST server, then register routes on rest_api_init (WP 5.1+).
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		( new \PedimentAi\Rest\ChatController() )->register();

		// Authenticate as an editor so WP returns 403 (forbidden) rather than 401
		// (unauthenticated) when the token permission check fails. The /run route is a
		// system-to-system call authenticated by a one-time token, not by user session.
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$store      = new ConversationStore();
		$c          = $store->getOrCreate( 1, $user_id );
		$this->conv = $c['id'];
		$store->appendUserMessage( $this->conv, 'create a landing page' );
		$this->turn = $store->startAssistantTurn( $this->conv );
		add_filter( 'pediment_ai_provider', fn() => new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );
	}

	private function call( array $headers ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns/' . $this->turn . '/run' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_missing_or_wrong_token_is_rejected(): void {
		$this->assertSame( 403, $this->call( [] )->get_status() );
		$this->assertSame( 403, $this->call( [ 'X-Pediment-Ai-Token' => 'nope' ] )->get_status() );
	}

	public function test_valid_token_runs_turn_once_and_is_idempotent(): void {
		$d     = new TurnDispatcher();
		$token = $d->mintToken( $this->turn );
		$d->stashInput( $this->turn, [
			'conversation_id' => $this->conv,
			'message'         => 'create a landing page',
			'selected_block'  => null,
			'block_tree'      => [],
		] );

		$first = $this->call( [ 'X-Pediment-Ai-Token' => $token ] );
		$this->assertSame( 204, $first->get_status() );

		$msg = ( new ConversationStore() )->getMessage( $this->turn );
		$this->assertContains( $msg['status'], [ 'complete', 'error' ], 'turn actually ran' );

		$replay = $this->call( [ 'X-Pediment-Ai-Token' => $token ] );
		$this->assertSame( 403, $replay->get_status() );
	}
}
