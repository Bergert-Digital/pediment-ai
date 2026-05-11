<?php
/**
 * REST controller for POST /v1/edit.
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
 * Enqueues an edit job via Action Scheduler.
 */
final class EditController {
	public function register(): void {
		register_rest_route(
			ComposeController::NS,
			'/edit',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'callback'            => [ $this, 'handle' ],
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$limits = (array) get_option( 'starter_ai_rate_limits', \StarterAi\Usage\RateLimiter::DEFAULTS );
		if ( ! ( new \StarterAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), 'edit' ) ) {
			return new \WP_Error( 'starter_ai_rate_limited', __( 'Rate limit reached. Try again later.', 'starter-ai' ), [ 'status' => 429 ] );
		}

		$instruction = trim( (string) $request->get_param( 'instruction' ) );
		$tree        = $request->get_param( 'tree' );

		if ( '' === $instruction ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Instruction is required.', 'starter-ai' ), [ 'status' => 400 ] );
		}
		if ( ! is_array( $tree ) ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Tree must be an array.', 'starter-ai' ), [ 'status' => 400 ] );
		}

		$store  = new JobStore();
		$job_id = $store->create(
			get_current_user_id(),
			'edit',
			[
				'prompt'        => $instruction,
				'existing_tree' => $tree,
			]
		);

		as_schedule_single_action( time(), 'starter_ai_job_run', [ $job_id ], 'starter-ai' );

		return new \WP_REST_Response( [ 'job_id' => $job_id ], 202 );
	}
}
