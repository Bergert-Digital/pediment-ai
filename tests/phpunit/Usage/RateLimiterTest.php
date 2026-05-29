<?php
namespace PedimentAi\Tests\Usage;

use PedimentAi\Usage\RateLimiter;

class RateLimiterTest extends \WP_UnitTestCase {
	private int $user_id;

	public function setUp(): void {
		parent::setUp();
		$this->user_id = $this->factory->user->create();
	}

	public function test_allows_below_limit(): void {
		$limiter = new RateLimiter( [ 'compose' => 3 ] );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->consume( $this->user_id, 'compose' ) );
		}
	}

	public function test_rejects_at_limit(): void {
		$limiter = new RateLimiter( [ 'compose' => 2 ] );
		$limiter->consume( $this->user_id, 'compose' );
		$limiter->consume( $this->user_id, 'compose' );
		$this->assertFalse( $limiter->consume( $this->user_id, 'compose' ) );
	}

	public function test_separate_buckets_per_kind(): void {
		$limiter = new RateLimiter( [ 'compose' => 1, 'refine' => 5 ] );
		$limiter->consume( $this->user_id, 'compose' );
		$this->assertFalse( $limiter->consume( $this->user_id, 'compose' ) );
		$this->assertTrue(  $limiter->consume( $this->user_id, 'refine' ) );
	}
}
