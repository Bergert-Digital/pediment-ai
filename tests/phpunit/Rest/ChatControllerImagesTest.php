<?php
/**
 * Tests for ChatController::startTurn image handling (normalizeImages + validation boundary).
 *
 * Uses inline dispatch mode with an anonymous stub provider to avoid loopback HTTP.
 *
 * @package PedimentAi\Tests\Rest
 */

namespace PedimentAi\Tests\Rest;

use PedimentAi\Anthropic\ProviderInterface;
use PedimentAi\Chat\ConversationStore;
use PedimentAi\Rest\ChatController;

class ChatControllerImagesTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;
	private int $post_id;
	private int $conv_id;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		( new ChatController() )->register();

		$user_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$this->post_id = self::factory()->post->create( [ 'post_author' => $user_id, 'post_status' => 'draft' ] );
		$this->conv_id = ( new ConversationStore() )->getOrCreate( $this->post_id, $user_id )['id'];

		// Use inline dispatch so no loopback HTTP is needed.
		add_filter( 'pediment_ai_dispatch_mode', fn() => 'inline' );

		// Stub provider: yields a minimal end_turn delta so the runner completes.
		add_filter( 'pediment_ai_provider', fn() => new class implements ProviderInterface {
			public function messages( array $args ) { return []; }
			public function stream_messages( array $args ) {
				return ( static function () {
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		} );

		// Ensure rate limits are permissive.
		update_option( 'pediment_ai_rate_limits', [ 'compose' => 999, 'edit' => 999, 'refine' => 999 ] );
	}

	public function tearDown(): void {
		remove_all_filters( 'pediment_ai_dispatch_mode' );
		remove_all_filters( 'pediment_ai_provider' );
		delete_option( 'pediment_ai_rate_limits' );
		parent::tearDown();
	}

	// --- helpers ---

	private function startTurn( array $params ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'conversation_id', $this->conv_id );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	private function makeImage( string $media_type = 'image/png', string $data = 'AAAB' ): array {
		return [ 'media_type' => $media_type, 'data' => $data ];
	}

	// --- tests ---

	/**
	 * Empty message AND no images → 400.
	 */
	public function test_empty_message_and_no_images_returns_400(): void {
		$res = $this->startTurn( [ 'message' => '', 'images' => [] ] );
		$this->assertSame( 400, $res->get_status() );
	}

	/**
	 * Empty message with one valid image → 202, and the user message has exactly one attachment.
	 */
	public function test_empty_message_with_valid_image_accepted(): void {
		$res = $this->startTurn( [
			'message' => '',
			'images'  => [ $this->makeImage( 'image/png' ) ],
		] );
		$this->assertSame( 202, $res->get_status() );

		$conv = ( new ConversationStore() )->findById( $this->conv_id );
		$userMsgs = array_values( array_filter( $conv['messages'], fn( $m ) => 'user' === $m['role'] ) );
		$this->assertCount( 1, $userMsgs, 'one user message should be persisted' );
		$userMsgId = $userMsgs[0]['id'];

		$atts = ( new ConversationStore() )->getAttachments( $userMsgId );
		$this->assertCount( 1, $atts, 'exactly one attachment persisted' );
		$this->assertSame( 'image/png', $atts[0]['media_type'] );
	}

	/**
	 * normalizeImages filtering:
	 * - strips disallowed MIME types (application/pdf)
	 * - caps at 5
	 */
	public function test_normalize_images_strips_invalid_types_and_caps_at_five(): void {
		// Build a mix: 1 invalid + 6 valid = 7 total. After filtering: 6 valid, capped at 5.
		$images = [ $this->makeImage( 'application/pdf', 'XXXX' ) ];
		for ( $i = 1; $i <= 6; $i++ ) {
			$images[] = $this->makeImage( 'image/png', 'AAAB' . $i );
		}

		$res = $this->startTurn( [
			'message' => '',
			'images'  => $images,
		] );
		$this->assertSame( 202, $res->get_status() );

		$conv = ( new ConversationStore() )->findById( $this->conv_id );
		$userMsgs = array_values( array_filter( $conv['messages'], fn( $m ) => 'user' === $m['role'] ) );
		$this->assertCount( 1, $userMsgs );
		$userMsgId = $userMsgs[0]['id'];

		$atts = ( new ConversationStore() )->getAttachments( $userMsgId );
		$this->assertCount( 5, $atts, 'capped at 5 after stripping invalid type' );

		$types = array_column( $atts, 'media_type' );
		foreach ( $types as $type ) {
			$this->assertSame( 'image/png', $type, 'no application/pdf should have slipped through' );
		}
	}
}
