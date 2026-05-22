<?php
namespace PedimentAi\Tests\Usage;

use PedimentAi\Usage\Tracker;

class TrackerTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_usage" );
	}

	public function test_records_a_call(): void {
		$tracker = new Tracker();
		$tracker->record( 1, 'compose', [
			'model' => 'claude-sonnet-4-6',
			'usage' => [ 'input_tokens' => 1000, 'output_tokens' => 500, 'cache_read_input_tokens' => 100 ],
		], 2 );

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}pediment_ai_usage", ARRAY_A );
		$this->assertSame( 'compose', $row['kind'] );
		$this->assertSame( '1000',    $row['input_tokens'] );
		$this->assertSame( '500',     $row['output_tokens'] );
		$this->assertSame( '100',     $row['cache_read_tokens'] );
		$this->assertSame( '2',       $row['web_fetch_count'] );
		$this->assertGreaterThan( 0.0, (float) $row['cost_usd'] );
	}

	public function test_month_to_date_totals(): void {
		$tracker = new Tracker();
		$tracker->record( 1, 'compose', [ 'model' => 'claude-sonnet-4-6', 'usage' => [ 'input_tokens' => 1000, 'output_tokens' => 500 ] ], 0 );
		$tracker->record( 1, 'refine',  [ 'model' => 'claude-haiku-4-5',  'usage' => [ 'input_tokens' => 300,  'output_tokens' => 100 ] ], 0 );

		$totals = $tracker->totalsThisMonth();
		$this->assertSame( 1300, $totals['input_tokens'] );
		$this->assertSame( 600,  $totals['output_tokens'] );
		$this->assertGreaterThan( 0.0, $totals['cost_usd'] );
	}
}
