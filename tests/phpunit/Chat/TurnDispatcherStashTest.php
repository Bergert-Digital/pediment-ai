<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\TurnDispatcher;

class TurnDispatcherStashTest extends \WP_UnitTestCase {
	public function test_stash_roundtrips_then_clears(): void {
		$d       = new TurnDispatcher();
		$payload = [
			'conversation_id' => 5,
			'message'         => 'create a landing page',
			'selected_block'  => null,
			'block_tree'      => [ [ 'name' => 'core/paragraph', 'clientId' => 'a' ] ],
		];
		$d->stashInput( 11, $payload );

		$this->assertSame( $payload, $d->takeInput( 11 ) );
		$this->assertNull( $d->takeInput( 11 ), 'second take is empty (consumed)' );
	}

	public function test_take_missing_returns_null(): void {
		$this->assertNull( ( new TurnDispatcher() )->takeInput( 123456 ) );
	}
}
