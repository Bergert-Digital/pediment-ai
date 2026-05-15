<?php
namespace StarterAi\Tests\Chat;

use StarterAi\Chat\TurnDispatcher;

class TurnDispatcherTokenTest extends \WP_UnitTestCase {
	public function test_minted_token_verifies_once_then_is_consumed(): void {
		$d     = new TurnDispatcher();
		$token = $d->mintToken( 42 );

		$this->assertNotSame( '', $token );
		$this->assertTrue( $d->consumeToken( 42, $token ), 'first use valid' );
		$this->assertFalse( $d->consumeToken( 42, $token ), 'second use rejected (one-time)' );
	}

	public function test_wrong_token_is_rejected(): void {
		$d     = new TurnDispatcher();
		$token = $d->mintToken( 7 );
		$this->assertFalse( $d->consumeToken( 7, 'not-the-token' ) );
		$this->assertFalse( $d->consumeToken( 999, 'anything' ), 'unknown turn rejected' );

		// A failed probe must NOT consume the real token.
		$this->assertTrue( $d->consumeToken( 7, $token ), 'correct token still valid after a failed probe' );
	}
}
