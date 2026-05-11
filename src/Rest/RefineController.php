<?php
/**
 * REST controller for POST /v1/refine (synchronous single-block refinement).
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Rest;

use StarterAi\Anthropic\Client;
use StarterAi\Anthropic\SchemaBuilder;
use StarterAi\Anthropic\ToolUseParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refines one block synchronously by calling the model inline.
 */
final class RefineController {
	public function register(): void {
		register_rest_route(
			ComposeController::NS,
			'/refine',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'callback'            => [ $this, 'handle' ],
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$limits = (array) get_option( 'starter_ai_rate_limits', \StarterAi\Usage\RateLimiter::DEFAULTS );
		if ( ! ( new \StarterAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), 'refine' ) ) {
			return new \WP_Error( 'starter_ai_rate_limited', __( 'Rate limit reached. Try again later.', 'starter-ai' ), [ 'status' => 429 ] );
		}

		$block_name  = (string) $request->get_param( 'blockName' );
		$attributes  = $request->get_param( 'attributes' );
		$inner       = $request->get_param( 'innerBlocks' );
		$instruction = trim( (string) $request->get_param( 'instruction' ) );

		if ( '' === $instruction ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Instruction is required.', 'starter-ai' ), [ 'status' => 400 ] );
		}

		$schema = ( new SchemaBuilder() )->build();
		if ( ! isset( $schema['blocks'][ $block_name ] ) ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Unknown block.', 'starter-ai' ), [ 'status' => 400 ] );
		}

		$spec     = $schema['blocks'][ $block_name ];
		$provider = apply_filters(
			'starter_ai_provider',
			new Client(
				(string) ( defined( 'ANTHROPIC_API_KEY' ) ? ANTHROPIC_API_KEY : get_option( 'starter_ai_api_key', '' ) )
			)
		);

		$response = $provider->messages(
			[
				'model'      => apply_filters( 'starter_ai_model_refine', 'claude-haiku-4-5' ),
				'max_tokens' => 2048,
				'tools'      => [ [
					'name'         => 'emit_block',
					'description'  => 'Emit the refined block attributes.',
					'input_schema' => [
						'type'       => 'object',
						'properties' => [
							'attributes'  => [ 'type' => 'object' ],
							'innerBlocks' => [ 'type' => 'array' ],
						],
						'required'   => [ 'attributes' ],
					],
				] ],
				'tool_choice' => [ 'type' => 'tool', 'name' => 'emit_block' ],
				'messages'    => [
					[
						'role'    => 'user',
						'content' => [
							[
								'type' => 'text',
								'text' =>
									"Refine this block: {$block_name}\n" .
									'Description: ' . ( $spec['description'] ?? '' ) . "\n" .
									'Current attributes: ' . wp_json_encode( $attributes ?: new \stdClass() ) . "\n" .
									( is_array( $inner ) && ! empty( $inner ) ? 'Inner blocks: ' . wp_json_encode( $inner ) . "\n" : '' ) .
									"Instruction: {$instruction}",
							],
						],
					],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = ( new ToolUseParser() )->parse( $response );
		if ( 'emit_block' !== $parsed['tool'] ) {
			return new \WP_Error( 'starter_ai_no_emit', __( 'Model did not emit a block.', 'starter-ai' ), [ 'status' => 502 ] );
		}

		return new \WP_REST_Response(
			[
				'attributes'  => $parsed['input']['attributes']  ?? [],
				'innerBlocks' => $parsed['input']['innerBlocks'] ?? [],
			],
			200
		);
	}
}
