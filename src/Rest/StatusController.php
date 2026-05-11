<?php
/**
 * REST controller for GET /v1/jobs/{id} (polling).
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Rest;

use StarterAi\Jobs\JobStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the current state of a job for client polling.
 */
final class StatusController {
	public function register(): void {
		register_rest_route(
			ComposeController::NS,
			'/jobs/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'callback'            => [ $this, 'handle' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$job = ( new JobStore() )->getById( $id );
		if ( ! $job ) {
			return new \WP_Error( 'starter_ai_not_found', __( 'Job not found.', 'starter-ai' ), [ 'status' => 404 ] );
		}
		if ( $job['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'starter_ai_forbidden', __( 'Not your job.', 'starter-ai' ), [ 'status' => 403 ] );
		}

		$urls = array_values( array_filter( array_column( $job['events'], 'url_fetched' ) ) );
		return new \WP_REST_Response(
			[
				'status'        => $job['status'],
				'urls_fetched'  => $urls,
				'progress_note' => $this->progressNote( $job['status'], $urls ),
				'result'        => $job['result'],
				'error'         => $job['error_message'],
			],
			200
		);
	}

	private function progressNote( string $status, array $urls ): ?string {
		if ( 'queued' === $status ) {
			return 'Queued…';
		}
		if ( 'composing' === $status ) {
			return [] === $urls ? 'Composing…' : 'Composing (fetched ' . count( $urls ) . ' URL' . ( count( $urls ) === 1 ? '' : 's' ) . ')';
		}
		if ( 'error' === $status ) {
			return 'Error';
		}
		if ( 'complete' === $status ) {
			return 'Done';
		}
		return null;
	}
}
