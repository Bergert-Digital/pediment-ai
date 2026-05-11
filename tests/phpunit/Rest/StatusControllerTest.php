<?php
namespace StarterAi\Tests\Rest;

use StarterAi\Jobs\JobStore;

class StatusControllerTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();
		\starter_ai_install_tables();
		global $wpdb, $wp_rest_server;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}starter_ai_jobs" );
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function test_returns_polling_shape(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$store = new JobStore();
		$id    = $store->create( $user_id, 'compose', [] );
		$store->updateStatus( $id, 'composing' );
		$store->appendEvent( $id, [ 'url_fetched' => 'https://x' ] );

		$req = new \WP_REST_Request( 'GET', "/starter-ai/v1/jobs/{$id}" );
		$res = $this->server->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$body = $res->get_data();
		$this->assertSame( 'composing',    $body['status'] );
		$this->assertSame( [ 'https://x' ], $body['urls_fetched'] );
		$this->assertNull( $body['result'] );
		$this->assertNull( $body['error'] );
	}

	public function test_completed_job_includes_result(): void {
		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$store = new JobStore();
		$id    = $store->create( $user_id, 'compose', [] );
		$store->complete( $id, [ 'blocks' => [ [ 'name' => 'starter/hero', 'attributes' => [], 'innerBlocks' => [] ] ] ] );

		$req = new \WP_REST_Request( 'GET', "/starter-ai/v1/jobs/{$id}" );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 'complete', $res->get_data()['status'] );
		$this->assertSame( 'starter/hero', $res->get_data()['result']['blocks'][0]['name'] );
	}

	public function test_other_user_cannot_read_job(): void {
		$owner = $this->factory->user->create( [ 'role' => 'editor' ] );
		$other = $this->factory->user->create( [ 'role' => 'editor' ] );

		$store = new JobStore();
		$id    = $store->create( $owner, 'compose', [] );

		wp_set_current_user( $other );
		$req = new \WP_REST_Request( 'GET', "/starter-ai/v1/jobs/{$id}" );
		$this->assertSame( 403, $this->server->dispatch( $req )->get_status() );
	}
}
