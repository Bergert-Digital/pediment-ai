<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\TurnRunner;
use PedimentAi\Chat\VirtualTree;
use PedimentAi\BlockTree\Validator;
use PedimentAi\Mock\MockProvider;

class TurnRunnerTest extends \WP_UnitTestCase {
	private ConversationStore $store;
	private Tools $tools;
	private PromptBuilder $prompts;
	private MockProvider $provider;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );

		$schema         = [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ];
		$this->store    = new ConversationStore();
		$this->tools    = new Tools( $schema, new Validator( $schema ) );
		$this->prompts  = new PromptBuilder( $schema );
		$this->provider = new MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
	}

	public function test_run_records_text_delta_and_one_tool_call(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $this->provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [ [ 'role' => 'user', 'content' => 'Add a paragraph that says hi' ] ],
			selectedId:    null,
			currentUserMsg: 'Add a paragraph that says hi'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		$this->assertStringContainsString( 'Adding a paragraph', $msg['content'] );
		$this->assertCount( 1, $msg['tool_calls'] );
		$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );
	}

	public function test_run_records_failure_on_provider_error(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );

		$broken = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public function messages( array $args ) { return new \WP_Error( 'down', 'Down' ); }
			public function stream_messages( array $args ) { return new \WP_Error( 'down', 'Down' ); }
		};
		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $broken, 'mock' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [],
			selectedId:    null,
			currentUserMsg: 'go'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'error', $msg['status'] );
		$this->assertSame( 'down',  $msg['error']['code'] );
	}

	public function test_run_stops_immediately_when_turn_marked_aborted(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->abort( $turn_id );

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $this->provider, 'mock' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [],
			selectedId:    null,
			currentUserMsg: 'Add a paragraph that says hi'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'aborted', $msg['status'] );
		// No tool calls recorded because we bailed before processing events.
		$this->assertSame( [], $msg['tool_calls'] );
	}

	/**
	 * Provider that emits a tool_use round for the first $toolRounds calls,
	 * then a final text + end_turn. Records the args of the last call.
	 */
	private function fakeProvider( int $toolRounds ) {
		return new class( $toolRounds ) implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls      = 0;
			public array $lastArgs = [];
			public function __construct( private int $toolRounds ) {}
			public function messages( array $args ) {
				return new \WP_Error( 'unused', 'unused' );
			}
			public function stream_messages( array $args ) {
				$this->calls++;
				$this->lastArgs = $args;
				$emitTool       = $this->calls <= $this->toolRounds;
				$n              = $this->calls;
				return ( static function () use ( $emitTool, $n ) {
					if ( $emitTool ) {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tu_' . $n, 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"position":"end","block":{"name":"core/paragraph","attributes":{"content":"hi"}}}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'tool_use' ] ];
					} else {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Done.' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
					}
				} )();
			}
		};
	}

	public function test_completes_multi_round_turn_exceeding_old_cap(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );
		$provider = $this->fakeProvider( 12 ); // 12 tool-use rounds, then end_turn.

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'build a landing page'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'], 'A 12-round turn must complete, not hit the iteration cap' );
		$this->assertCount( 12, $msg['tool_calls'] );
	}

	public function test_max_iterations_filter_lowers_cap(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );
		$provider = $this->fakeProvider( 5 );

		add_filter( 'pediment_ai_max_iterations', static fn() => 2 );
		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'm' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'go'
		);
		remove_all_filters( 'pediment_ai_max_iterations' );

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'error', $msg['status'] );
		$this->assertSame( 'iteration_limit', $msg['error']['code'] );
		$this->assertCount( 2, $msg['tool_calls'] );
	}

	public function test_max_tokens_filter_is_honored(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );
		$provider = $this->fakeProvider( 0 ); // immediate end_turn.

		add_filter( 'pediment_ai_max_tokens', static fn() => 12345 );
		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'm' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'hi'
		);
		remove_all_filters( 'pediment_ai_max_tokens' );

		$this->assertSame( 12345, $provider->lastArgs['max_tokens'] );
	}

	public function test_truncated_tool_use_is_dropped_and_loop_continues(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Call 1: text + one complete tool_use + one truncated tool_use (start/stop,
		// no input deltas) + stop_reason=max_tokens. Call 2: reject if any tool_use
		// sent back has non-object input (reproduces the real Anthropic 400), else end.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls = 0;
			public function messages( array $args ) {
				return new \WP_Error( 'unused', 'unused' );
			}
			public function stream_messages( array $args ) {
				$this->calls++;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Building' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tA', 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"position":"end","block":{"name":"core/paragraph","attributes":{"content":"hi"}}}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tB', 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'max_tokens' ] ];
					} )();
				}
				foreach ( (array) ( $args['messages'] ?? [] ) as $m ) {
					if ( 'assistant' !== ( $m['role'] ?? '' ) ) {
						continue;
					}
					foreach ( (array) ( $m['content'] ?? [] ) as $b ) {
						if ( 'tool_use' === ( $b['type'] ?? '' ) ) {
							$in = $b['input'] ?? null;
							if ( ! is_array( $in ) || [] === $in ) {
								return new \WP_Error( 'pediment_ai_anthropic_400', 'tool_use.input: Input should be an object' );
							}
						}
					}
				}
				return ( static function () {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Done' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'm' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'build a landing page'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'], 'Truncated tool_use must be dropped, not sent back as []' );
		$this->assertSame( 2, $provider->calls, 'Loop must continue after dropping the truncated tool_use' );
		$this->assertCount( 1, $msg['tool_calls'], 'Only the complete tool_use is applied/recorded' );
		$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );
	}

	public function test_fully_truncated_turn_fails_cleanly_without_sending_empty_message(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Only a truncated tool_use, no text, no complete calls → nothing valid to send.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls = 0;
			public function messages( array $args ) {
				return new \WP_Error( 'unused', 'unused' );
			}
			public function stream_messages( array $args ) {
				$this->calls++;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tX', 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'max_tokens' ] ];
					} )();
				}
				return new \WP_Error( 'should_not_be_called', 'empty assistant message was sent back' );
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'm' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'go'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 1, $provider->calls, 'Must not send an empty assistant message back' );
		$this->assertSame( 'error', $msg['status'] );
		$this->assertSame( 'response_truncated', $msg['error']['code'] );
	}
}
