<?php
namespace StarterAi\Tests\Chat;

use StarterAi\Chat\VirtualTree;

class VirtualTreeTest extends \WP_UnitTestCase {
	public function test_loads_initial_tree_with_client_ids(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertNotNull( $tree->find( 'a' ) );
	}

	public function test_insert_at_end_appends_and_returns_new_client_id(): void {
		$tree = new VirtualTree( [] );
		$cid  = $tree->insert( null, 'end', [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'New' ], 'innerBlocks' => [] ] );
		$this->assertNotEmpty( $cid );
		$node = $tree->find( $cid );
		$this->assertSame( 'core/paragraph', $node['name'] );
	}

	public function test_insert_after_existing_block(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$cid = $tree->insert( 'a', 'after', [ 'name' => 'core/heading', 'attributes' => [ 'content' => 'H' ], 'innerBlocks' => [] ] );
		$order = array_column( $tree->toArray(), 'clientId' );
		$this->assertSame( [ 'a', $cid, 'b' ], $order );
	}

	public function test_update_merges_attributes(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Old', 'dropCap' => true ], 'innerBlocks' => [] ],
		] );
		$tree->update( 'a', [ 'content' => 'New' ], null );
		$node = $tree->find( 'a' );
		$this->assertSame( 'New', $node['attributes']['content'] );
		$this->assertTrue( $node['attributes']['dropCap'] );
	}

	public function test_delete_removes_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$tree->delete( 'a' );
		$this->assertNull( $tree->find( 'a' ) );
	}

	public function test_move_reorders_blocks(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'c', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$tree->move( 'c', 'a', 'before' );
		$this->assertSame( [ 'c', 'a', 'b' ], array_column( $tree->toArray(), 'clientId' ) );
	}

	public function test_find_returns_null_for_missing_client_id(): void {
		$tree = new VirtualTree( [] );
		$this->assertNull( $tree->find( 'missing' ) );
	}

	public function test_skeleton_with_focus_emits_full_content_near_target(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'long ', 200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'mid ',  200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'c', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'far ',  200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'd', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'farr ', 200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'e', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'farrr ',200 ) ], 'innerBlocks' => [] ],
		] );
		// focus on 'a', window=3 → a,b,c full; d,e truncated
		$skeleton = $tree->skeleton( 'a', 3 );
		$this->assertFalse( ! empty( $skeleton[0]['truncated'] ) );
		$this->assertFalse( ! empty( $skeleton[1]['truncated'] ) );
		$this->assertFalse( ! empty( $skeleton[2]['truncated'] ) );
		$this->assertTrue( ! empty( $skeleton[3]['truncated'] ) );
	}
}
