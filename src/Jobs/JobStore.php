<?php
/**
 * CRUD for the wp_starter_ai_jobs table.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsulates all SQL access to the jobs table.
 */
final class JobStore {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'starter_ai_jobs';
	}

	/**
	 * @param int                 $user_id Author of the job.
	 * @param string              $kind    'compose' | 'edit' | 'refine'.
	 * @param array<string,mixed> $payload Request payload.
	 * @return int New job id.
	 */
	public function create( int $user_id, string $kind, array $payload ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table,
			[
				'user_id'     => $user_id,
				'kind'        => $kind,
				'status'      => 'queued',
				'payload'     => wp_json_encode( $payload ),
				'events_json' => wp_json_encode( [] ),
				'created_at'  => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	public function updateStatus( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( $this->table, [ 'status' => $status ], [ 'id' => $id ] );
	}

	/**
	 * @param array<string,mixed> $event Event data.
	 */
	public function appendEvent( int $id, array $event ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT events_json FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$events = json_decode( (string) $row['events_json'], true );
		if ( ! is_array( $events ) ) {
			$events = [];
		}
		$events[] = $event;
		$wpdb->update( $this->table, [ 'events_json' => wp_json_encode( $events ) ], [ 'id' => $id ] );
	}

	/**
	 * @param array<string,mixed> $result Final job result.
	 */
	public function complete( int $id, array $result ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			[
				'status'       => 'complete',
				'result_json'  => wp_json_encode( $result ),
				'completed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	public function fail( int $id, string $message ): void {
		global $wpdb;
		$wpdb->update(
			$this->table,
			[
				'status'        => 'error',
				'error_message' => $message,
				'completed_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getById( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return [
			'id'            => (int) $row['id'],
			'user_id'       => (int) $row['user_id'],
			'kind'          => (string) $row['kind'],
			'status'        => (string) $row['status'],
			'payload'       => json_decode( (string) $row['payload'], true ) ?: [],
			'events'        => json_decode( (string) ( $row['events_json'] ?? '[]' ), true ) ?: [],
			'result'        => $row['result_json'] ? ( json_decode( (string) $row['result_json'], true ) ?: [] ) : null,
			'error_message' => $row['error_message'] ? (string) $row['error_message'] : null,
			'created_at'    => (string) $row['created_at'],
			'completed_at'  => $row['completed_at'] ? (string) $row['completed_at'] : null,
		];
	}
}
