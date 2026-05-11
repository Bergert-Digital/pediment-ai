<?php
namespace StarterAi\Tests\Jobs;

use StarterAi\Jobs\JobStore;

class JobStoreTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		\starter_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}starter_ai_jobs" );
	}

	public function test_create_returns_id_and_stores_payload(): void {
		$store = new JobStore();
		$id    = $store->create( 1, 'compose', [ 'prompt' => 'Hi' ] );
		$this->assertGreaterThan( 0, $id );
		$job = $store->getById( $id );
		$this->assertSame( 1, $job['user_id'] );
		$this->assertSame( 'compose', $job['kind'] );
		$this->assertSame( 'queued',  $job['status'] );
		$this->assertSame( 'Hi',      $job['payload']['prompt'] );
	}

	public function test_update_status(): void {
		$store = new JobStore();
		$id = $store->create( 1, 'compose', [] );
		$store->updateStatus( $id, 'composing' );
		$this->assertSame( 'composing', $store->getById( $id )['status'] );
	}

	public function test_append_event_collects_urls(): void {
		$store = new JobStore();
		$id = $store->create( 1, 'compose', [] );
		$store->appendEvent( $id, [ 'url_fetched' => 'https://example.com/a' ] );
		$store->appendEvent( $id, [ 'url_fetched' => 'https://example.com/b' ] );
		$events = $store->getById( $id )['events'];
		$this->assertCount( 2, $events );
		$this->assertSame( 'https://example.com/a', $events[0]['url_fetched'] );
	}

	public function test_complete_with_result(): void {
		$store = new JobStore();
		$id = $store->create( 1, 'compose', [] );
		$store->complete( $id, [ 'blocks' => [] ] );
		$job = $store->getById( $id );
		$this->assertSame( 'complete', $job['status'] );
		$this->assertSame( [],         $job['result']['blocks'] );
		$this->assertNotNull( $job['completed_at'] );
	}

	public function test_fail_with_error(): void {
		$store = new JobStore();
		$id = $store->create( 1, 'compose', [] );
		$store->fail( $id, 'API down' );
		$job = $store->getById( $id );
		$this->assertSame( 'error',    $job['status'] );
		$this->assertSame( 'API down', $job['error_message'] );
	}

	public function test_get_by_id_returns_null_for_missing(): void {
		$this->assertNull( ( new JobStore() )->getById( 99999 ) );
	}
}
