<?php
/**
 * REST controller for POST /v1/compose.
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
 * Enqueues a compose job via Action Scheduler.
 */
final class ComposeController {
	public const NS = 'starter-ai/v1';

	public function register(): void {
		register_rest_route(
			self::NS,
			'/compose',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'callback'            => [ $this, 'handle' ],
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$limits = (array) get_option( 'starter_ai_rate_limits', \StarterAi\Usage\RateLimiter::DEFAULTS );
		if ( ! ( new \StarterAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), 'compose' ) ) {
			return new \WP_Error( 'starter_ai_rate_limited', __( 'Rate limit reached. Try again later.', 'starter-ai' ), [ 'status' => 429 ] );
		}

		$prompt    = trim( (string) $request->get_param( 'prompt' ) );
		$page_type = sanitize_key( (string) $request->get_param( 'page_type' ) );
		$tone      = sanitize_text_field( (string) $request->get_param( 'tone' ) );

		if ( '' === $prompt ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Prompt is required.', 'starter-ai' ), [ 'status' => 400 ] );
		}

		$store  = new JobStore();
		$job_id = $store->create(
			get_current_user_id(),
			'compose',
			[
				'prompt'    => $prompt,
				'page_type' => $page_type ?: 'other',
				'tone'      => $tone,
			]
		);

		as_schedule_single_action( time(), 'starter_ai_job_run', [ $job_id ], 'starter-ai' );

		return new \WP_REST_Response( [ 'job_id' => $job_id ], 202 );
	}
}
