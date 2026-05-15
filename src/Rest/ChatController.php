<?php
/**
 * REST routes under /starter-ai/v1/chat/*.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Rest;

use StarterAi\Anthropic\Client;
use StarterAi\Anthropic\SchemaBuilder;
use StarterAi\BlockTree\Validator;
use StarterAi\Chat\ConversationStore;
use StarterAi\Chat\PromptBuilder;
use StarterAi\Chat\Tools;
use StarterAi\Chat\TurnRunner;
use StarterAi\Chat\VirtualTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ChatController {
	public const NS = 'starter-ai/v1';

	public function register(): void {
		register_rest_route( self::NS, '/chat/conversations', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'permGetConv' ],
			'callback'            => [ $this, 'getConversation' ],
			'args'                => [ 'post_id' => [ 'type' => 'integer', 'required' => true ] ],
		] );
		register_rest_route( self::NS, '/chat/conversations/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => [ $this, 'permTouchConv' ],
			'callback'            => [ $this, 'clearConversation' ],
		] );
		register_rest_route( self::NS, '/chat/turns', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'permPostTurn' ],
			'callback'            => [ $this, 'startTurn' ],
		] );
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'permTouchTurn' ],
			'callback'            => [ $this, 'getTurn' ],
		] );
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => [ $this, 'permTouchTurn' ],
			'callback'            => [ $this, 'abortTurn' ],
		] );
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)/run', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'permRunTurn' ],
			'callback'            => [ $this, 'runTurn' ],
		] );
	}

	// --- Permission callbacks ---

	public function permGetConv( \WP_REST_Request $r ): bool {
		$post_id = (int) $r->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function permTouchConv( \WP_REST_Request $r ): bool {
		$conv = ( new ConversationStore() )->findById( (int) $r->get_param( 'id' ) );
		return $conv && current_user_can( 'edit_post', $conv['post_id'] ) && (int) $conv['user_id'] === get_current_user_id();
	}

	public function permPostTurn( \WP_REST_Request $r ): bool {
		$post_id = (int) $r->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function permTouchTurn( \WP_REST_Request $r ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.post_id, c.user_id FROM {$wpdb->prefix}starter_ai_chat_messages m
			 JOIN {$wpdb->prefix}starter_ai_chat_conversations c ON c.id = m.conversation_id
			 WHERE m.id = %d",
			(int) $r->get_param( 'id' )
		), ARRAY_A );
		return $row && current_user_can( 'edit_post', (int) $row['post_id'] ) && (int) $row['user_id'] === get_current_user_id();
	}

	public function permRunTurn( \WP_REST_Request $r ): bool {
		$turn_id = (int) $r->get_param( 'id' );
		$token   = (string) $r->get_header( 'X-Starter-Ai-Token' );
		return '' !== $token && ( new \StarterAi\Chat\TurnDispatcher() )->verifyToken( $turn_id, $token );
	}

	// --- Handlers ---

	public function getConversation( \WP_REST_Request $r ): \WP_REST_Response {
		$conv = ( new ConversationStore() )->getOrCreate( (int) $r->get_param( 'post_id' ), get_current_user_id() );
		return new \WP_REST_Response( $conv, 200 );
	}

	public function clearConversation( \WP_REST_Request $r ): \WP_REST_Response {
		( new ConversationStore() )->clear( (int) $r->get_param( 'id' ) );
		return new \WP_REST_Response( null, 204 );
	}

	public function startTurn( \WP_REST_Request $r ) {
		$post_id         = (int) $r->get_param( 'post_id' );
		$conversation_id = (int) $r->get_param( 'conversation_id' );
		$message         = trim( (string) $r->get_param( 'message' ) );
		$selected        = $r->get_param( 'selected_block' );
		if ( '' === $message ) {
			return new \WP_Error( 'starter_ai_invalid', __( 'Message is required.', 'starter-ai' ), [ 'status' => 400 ] );
		}

		$limits = (array) get_option( 'starter_ai_rate_limits', \StarterAi\Usage\RateLimiter::DEFAULTS );
		if ( ! ( new \StarterAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), 'compose' ) ) {
			return new \WP_Error( 'starter_ai_rate_limited', __( 'Rate limit reached.', 'starter-ai' ), [ 'status' => 429 ] );
		}

		$store   = new ConversationStore();
		$store->appendUserMessage( $conversation_id, $message );
		$turn_id = $store->startAssistantTurn( $conversation_id );

		// Build context for TurnRunner.
		$tree_source = is_array( $r->get_param( 'block_tree' ) ) ? $r->get_param( 'block_tree' ) : [];
		$tree        = new VirtualTree( $tree_source );

		$dispatcher = new \StarterAi\Chat\TurnDispatcher();
		/**
		 * Dispatch mode: 'auto' (non-blocking loopback; streams) or 'inline'
		 * (run synchronously before responding; no streaming, but needs no
		 * loopback). Default 'auto'.
		 *
		 * @param string $mode
		 */
		$mode = (string) apply_filters( 'starter_ai_dispatch_mode', 'auto' );

		if ( 'inline' === $mode ) {
			$this->processTurn( $turn_id, $conversation_id, $tree, $selected, $message );
			return new \WP_REST_Response( [ 'turn_id' => $turn_id ], 202 );
		}

		$dispatcher->stashInput( $turn_id, [
			'conversation_id' => $conversation_id,
			'message'         => $message,
			'selected_block'  => $selected,
			'block_tree'      => is_array( $r->get_param( 'block_tree' ) ) ? $r->get_param( 'block_tree' ) : [],
		] );
		$dispatcher->dispatch( $turn_id, $dispatcher->mintToken( $turn_id ) );

		return new \WP_REST_Response( [ 'turn_id' => $turn_id ], 202 );
	}

	public function getTurn( \WP_REST_Request $r ): \WP_REST_Response {
		$msg = ( new ConversationStore() )->getMessage( (int) $r->get_param( 'id' ) );
		if ( ! $msg ) {
			return new \WP_REST_Response( [ 'status' => 'error', 'error' => [ 'code' => 'not_found', 'message' => 'Turn not found' ] ], 404 );
		}
		return new \WP_REST_Response( [
			'status'     => $msg['status'],
			'content'    => $msg['content'],
			'tool_calls' => $msg['tool_calls'],
			'error'      => $msg['error'],
		], 200 );
	}

	public function abortTurn( \WP_REST_Request $r ): \WP_REST_Response {
		( new ConversationStore() )->abort( (int) $r->get_param( 'id' ) );
		return new \WP_REST_Response( null, 204 );
	}

	public function runTurn( \WP_REST_Request $r ): \WP_REST_Response {
		$turn_id = (int) $r->get_param( 'id' );
		$token = (string) $r->get_header( 'X-Starter-Ai-Token' );
		if ( ! ( new \StarterAi\Chat\TurnDispatcher() )->consumeToken( $turn_id, $token ) ) {
			return new \WP_REST_Response( null, 403 );
		}
		$store   = new ConversationStore();
		$msg     = $store->getMessage( $turn_id );

		// Idempotency: only a freshly-started assistant turn may be run.
		if ( ! $msg || 'streaming' !== $msg['status'] ) {
			return new \WP_REST_Response( null, 204 );
		}

		$input = ( new \StarterAi\Chat\TurnDispatcher() )->takeInput( $turn_id );
		if ( null === $input ) {
			$store->fail( $turn_id, 'dispatch_lost', 'Turn inputs expired before the runner started.' );
			return new \WP_REST_Response( null, 204 );
		}

		ignore_user_abort( true );
		$tree = new VirtualTree( is_array( $input['block_tree'] ?? null ) ? $input['block_tree'] : [] );
		$this->processTurn(
			$turn_id,
			(int) $input['conversation_id'],
			$tree,
			$input['selected_block'] ?? null,
			(string) $input['message']
		);
		return new \WP_REST_Response( null, 204 );
	}

	// --- Internal ---

	/**
	 * @param array<string,mixed>|null $selected
	 */
	public function processTurn( int $turn_id, int $conversation_id, VirtualTree $tree, $selected, string $message ): void {
		$store = new ConversationStore();
		$conv  = $store->findById( $conversation_id );
		// Build history (everything except the just-inserted user+assistant pair).
		$history = array_slice( $conv['messages'], 0, -2 );

		$schema   = ( new SchemaBuilder() )->build();
		$tools    = new Tools( $schema['blocks'], new Validator( $schema['blocks'] ) );
		$prompts  = new PromptBuilder( $schema['blocks'] );
		$provider = apply_filters(
			'starter_ai_provider',
			new Client( ( new \StarterAi\Settings\OptionsStore() )->getApiKey() )
		);
		$model    = (string) apply_filters( 'starter_ai_model_compose', 'claude-sonnet-4-6' );

		$selectedId = is_array( $selected ) && isset( $selected['clientId'] ) ? (string) $selected['clientId'] : null;

		( new TurnRunner( $store, $tools, $prompts, $provider, $model ) )->run(
			turn_id:        $turn_id,
			tree:           $tree,
			history:        $history,
			selectedId:     $selectedId,
			currentUserMsg: $message
		);
	}
}
