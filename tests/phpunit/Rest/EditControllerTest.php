<?php
namespace StarterAi\Tests\Rest;

class EditControllerTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();
		\starter_ai_install_tables();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
	}

	public function test_accepts_block_tree_and_returns_job_id(): void {
		$req = new \WP_REST_Request( 'POST', '/starter-ai/v1/edit' );
		$req->set_param( 'instruction', 'Add a CTA' );
		$req->set_param( 'tree', [
			[ 'name' => 'starter/hero', 'attributes' => [ 'headline' => 'Old' ], 'innerBlocks' => [] ],
		] );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 202, $res->get_status() );
		$this->assertGreaterThan( 0, $res->get_data()['job_id'] );
	}

	public function test_rejects_empty_instruction(): void {
		$req = new \WP_REST_Request( 'POST', '/starter-ai/v1/edit' );
		$req->set_param( 'instruction', '' );
		$req->set_param( 'tree', [] );
		$this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
	}

	public function test_rejects_non_array_tree(): void {
		$req = new \WP_REST_Request( 'POST', '/starter-ai/v1/edit' );
		$req->set_param( 'instruction', 'x' );
		$req->set_param( 'tree', 'not an array' );
		$this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
	}
}
