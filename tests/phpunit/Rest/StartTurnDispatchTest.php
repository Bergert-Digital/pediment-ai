<?php
namespace StarterAi\Tests\Rest;

use StarterAi\Chat\ConversationStore;

class StartTurnDispatchTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		\starter_ai_install_tables();
		( new \StarterAi\Rest\ChatController() )->register();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function start( int $post_id, int $conv ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/starter-ai/v1/chat/turns' );
		$req->set_body_params( [
			'post_id'         => $post_id,
			'conversation_id' => $conv,
			'message'         => 'create a landing page',
			'block_tree'      => [],
		] );
		return rest_get_server()->dispatch( $req );
	}

	public function test_auto_mode_returns_202_fast_and_fires_loopback_without_running_inline(): void {
		$post = self::factory()->post->create();
		$conv = ( new ConversationStore() )->getOrCreate( $post, get_current_user_id() )['id'];

		$fired = false;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$fired ) {
			if ( false !== strpos( $url, '/run' ) ) {
				$fired = true;
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );

		$res = $this->start( $post, $conv );

		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 202, $res->get_status() );
		$turn_id = $res->get_data()['turn_id'];
		$this->assertTrue( $fired, 'loopback /run was dispatched' );
		$this->assertSame( 'streaming', ( new ConversationStore() )->getMessage( $turn_id )['status'] );
	}

	public function test_inline_mode_runs_synchronously(): void {
		$post = self::factory()->post->create();
		$conv = ( new ConversationStore() )->getOrCreate( $post, get_current_user_id() )['id'];
		add_filter( 'starter_ai_dispatch_mode', fn() => 'inline' );
		add_filter( 'starter_ai_provider', fn() => new \StarterAi\Mock\MockProvider( STARTER_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );

		$res     = $this->start( $post, $conv );
		$turn_id = $res->get_data()['turn_id'];

		remove_all_filters( 'starter_ai_dispatch_mode' );
		remove_all_filters( 'starter_ai_provider' );
		$this->assertSame( 202, $res->get_status() );
		$this->assertContains(
			( new ConversationStore() )->getMessage( $turn_id )['status'],
			[ 'complete', 'error' ],
			'inline mode ran the turn before returning'
		);
	}
}
