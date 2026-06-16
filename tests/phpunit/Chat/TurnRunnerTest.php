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

	public function test_web_tool_blocks_are_echoed_back_and_only_block_tools_apply(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Call 1: text + a server web_fetch + its result block + a client insert_block,
		// stop_reason=tool_use. Call 2: capture args, then end the turn.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls      = 0;
			public array $lastArgs = [];
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				$this->calls++;
				$this->lastArgs = $args;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Reading the site' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_fetch' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"url":"https://berlinerteam.de"}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [
							'type'        => 'web_fetch_tool_result',
							'tool_use_id' => 'srv_1',
							'content'     => [
								'type'    => 'web_fetch_result',
								'url'     => 'https://berlinerteam.de',
								'content' => [ 'type' => 'document', 'source' => [ 'type' => 'text', 'media_type' => 'text/plain', 'data' => 'Berliner Team — we build things' ] ],
							],
						] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tA', 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"position":"end","block":{"name":"core/paragraph","attributes":{"content":"Berliner Team"}}}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'tool_use' ] ];
					} )();
				}
				return ( static function () {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Done' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'build a page based on https://berlinerteam.de'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		// Server tools run on Anthropic's side — only the block tool is applied/recorded.
		$this->assertCount( 1, $msg['tool_calls'] );
		$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );

		// The 2nd request must echo the server call + its result so the fetched page
		// stays in context, alongside the client tool_use.
		$assistant = null;
		foreach ( (array) $provider->lastArgs['messages'] as $m ) {
			if ( 'assistant' === ( $m['role'] ?? '' ) ) {
				$assistant = $m;
			}
		}
		$this->assertNotNull( $assistant );
		$types = array_column( $assistant['content'], 'type' );
		$this->assertContains( 'server_tool_use', $types );
		$this->assertContains( 'web_fetch_tool_result', $types );
		$this->assertContains( 'tool_use', $types );

		foreach ( $assistant['content'] as $b ) {
			if ( 'server_tool_use' === $b['type'] ) {
				$this->assertSame( 'web_fetch', $b['name'] );
				$this->assertSame( 'https://berlinerteam.de', $b['input']['url'] );
			}
		}
	}

	public function test_dynamic_filtering_code_execution_result_is_echoed_back(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// web_fetch_20260209 dynamic filtering: alongside the fetch, the model emits a
		// code_execution server_tool_use + its code_execution_tool_result to trim the
		// page. Both halves of every server tool call must be echoed back — Anthropic
		// 400s on a server_tool_use whose result block is missing. Call 2 reproduces
		// that 400 if any server_tool_use id lacks a matching *_tool_result.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls = 0;
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				$this->calls++;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_fetch', 'name' => 'web_fetch' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"url":"https://berlinerteam.de"}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'web_fetch_tool_result', 'tool_use_id' => 'srv_fetch', 'content' => [ 'type' => 'web_fetch_result', 'url' => 'https://berlinerteam.de' ] ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_code', 'name' => 'code_execution' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"code":"print(1)"}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'code_execution_tool_result', 'tool_use_id' => 'srv_code', 'content' => [ 'type' => 'code_execution_result', 'stdout' => 'filtered' ] ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tA', 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"position":"end","block":{"name":"core/paragraph","attributes":{"content":"hi"}}}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'tool_use' ] ];
					} )();
				}
				// Reproduce the real Anthropic 400: every server_tool_use needs a result.
				$server_ids = [];
				$result_ids = [];
				foreach ( (array) ( $args['messages'] ?? [] ) as $m ) {
					if ( 'assistant' !== ( $m['role'] ?? '' ) ) {
						continue;
					}
					foreach ( (array) ( $m['content'] ?? [] ) as $b ) {
						$type = (string) ( $b['type'] ?? '' );
						if ( 'server_tool_use' === $type ) {
							$server_ids[] = (string) ( $b['id'] ?? '' );
						} elseif ( str_ends_with( $type, '_tool_result' ) ) {
							$result_ids[] = (string) ( $b['tool_use_id'] ?? '' );
						}
					}
				}
				foreach ( $server_ids as $id ) {
					if ( ! in_array( $id, $result_ids, true ) ) {
						return new \WP_Error( 'pediment_ai_anthropic_400', "server_tool_use {$id} was found without a corresponding tool_result block" );
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

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'rebuild this page from https://berlinerteam.de'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'], 'code_execution result must be echoed back, not orphaned' );
		$this->assertSame( 2, $provider->calls );
		$this->assertCount( 1, $msg['tool_calls'] );
	}

	public function test_web_fetch_error_code_is_recorded_for_diagnosis(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Anthropic's server-side web_fetch can fail to retrieve an origin its egress
		// cannot reach (url_not_accessible) even when the page is reachable from this
		// host. The model only paraphrases that in prose; the runner must persist the
		// raw error_code so the failure is diagnosable after the fact.
		// A null-returning fetcher keeps the server-side fallback from firing, so this
		// test isolates the error-recording behavior (and never touches the network).
		$fetcher = new class implements \PedimentAi\Chat\PageFetcherInterface {
			public function fetch( string $url ): ?string { return null; }
		};
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				return ( static function () {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_fetch', 'name' => 'web_fetch' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"url":"https://www.berlinerteam.de/unser-angebot/"}' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'web_fetch_tool_result', 'tool_use_id' => 'srv_fetch', 'content' => [ 'type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible' ] ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'I could not reach that page.' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6', $fetcher );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'rebuild this page from https://www.berlinerteam.de/unser-angebot/'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertCount( 1, $msg['tool_calls'], 'the web_fetch error must be recorded as a tool call' );
		$call = $msg['tool_calls'][0];
		$this->assertTrue( ! empty( $call['is_error'] ) );
		$this->assertSame( 'web_fetch_tool_result', $call['tool'] );
		$this->assertSame( 'url_not_accessible', $call['output']['error_code'] );
	}

	public function test_web_fetch_failure_recovers_via_server_side_fetch(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		$fetcher = new class implements \PedimentAi\Chat\PageFetcherInterface {
			/** @var string[] */
			public array $fetched = [];
			public function fetch( string $url ): ?string {
				$this->fetched[] = $url;
				return "Change Management Beratung\n\nWandel gestalten mit System.";
			}
		};

		// Round 1: hosted web_fetch errors (url_not_accessible) and the model gives up
		// with end_turn. The runner must fetch the page server-side and re-prompt.
		// Round 2: the injected page text must arrive as a user message; the model
		// acknowledges and ends.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls = 0;
			/** @var string[] */
			public array $userTextSeenRound2 = [];
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				$this->calls++;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_fetch', 'name' => 'web_fetch' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"url":"https://www.berlinerteam.de/unser-angebot/"}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'web_fetch_tool_result', 'tool_use_id' => 'srv_fetch', 'content' => [ 'type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible' ] ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'I could not reach that page.' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
					} )();
				}
				foreach ( (array) ( $args['messages'] ?? [] ) as $m ) {
					if ( 'user' !== ( $m['role'] ?? '' ) ) {
						continue;
					}
					foreach ( (array) ( $m['content'] ?? [] ) as $b ) {
						if ( 'text' === ( $b['type'] ?? '' ) ) {
							$this->userTextSeenRound2[] = (string) $b['text'];
						}
					}
				}
				return ( static function () {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Building from the fetched content.' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6', $fetcher );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'rebuild this page from https://www.berlinerteam.de/unser-angebot/'
		);

		// The failed URL was fetched server-side exactly once.
		$this->assertSame( [ 'https://www.berlinerteam.de/unser-angebot/' ], $fetcher->fetched );
		// A second round ran and received the recovered page text.
		$this->assertSame( 2, $provider->calls );
		$joined = implode( "\n", $provider->userTextSeenRound2 );
		$this->assertStringContainsString( 'Content fetched from https://www.berlinerteam.de/unser-angebot/', $joined );
		$this->assertStringContainsString( 'Wandel gestalten mit System.', $joined );

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		$tools = array_column( $msg['tool_calls'], 'tool' );
		$this->assertContains( 'web_fetch_tool_result', $tools, 'the hosted-fetch error must be recorded' );
		$this->assertContains( 'web_fetch_fallback', $tools, 'the server-side recovery must be recorded' );
	}

	public function test_narration_across_rounds_is_separated_by_a_blank_line(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Three rounds, each opening with a sentence of narration before its tool
		// call (rounds 1-2) or before ending (round 3). Without a separator the
		// stored content reads "…content.Let me…" — period glued to the next
		// sentence's capital. Each round's narration is one text block.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls = 0;
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				$this->calls++;
				$n        = $this->calls;
				$narration = [ 1 => 'The direct fetch failed.', 2 => 'Let me fetch the page.', 3 => 'Here is the result.' ][ $n ];
				$emitTool  = $n < 3;
				return ( static function () use ( $narration, $emitTool, $n ) {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => $narration ] ];
					yield [ 'type' => 'content_block_stop' ];
					if ( $emitTool ) {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'tool_use', 'id' => 'tu_' . $n, 'name' => 'insert_block' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"position":"end","block":{"name":"core/paragraph","attributes":{"content":"hi"}}}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'tool_use' ] ];
					} else {
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
					}
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'rebuild this page'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		$this->assertSame(
			"The direct fetch failed.\n\nLet me fetch the page.\n\nHere is the result.",
			$msg['content'],
			'Narration from each tool-use round must be separated by a blank line, not glued together.'
		);
	}

	public function test_pause_turn_resends_assistant_without_a_new_user_message(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );

		// Call 1: a server web_fetch that pauses (stop_reason=pause_turn). Call 2:
		// capture the messages, then finish.
		$provider = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public int $calls               = 0;
			public array $secondCallMessages = [];
			public function messages( array $args ) { return new \WP_Error( 'unused', 'unused' ); }
			public function stream_messages( array $args ) {
				$this->calls++;
				if ( 1 === $this->calls ) {
					return ( static function () {
						yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_fetch' ] ];
						yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'input_json_delta', 'partial_json' => '{"url":"https://berlinerteam.de"}' ] ];
						yield [ 'type' => 'content_block_stop' ];
						yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'pause_turn' ] ];
					} )();
				}
				$this->secondCallMessages = (array) ( $args['messages'] ?? [] );
				return ( static function () {
					yield [ 'type' => 'content_block_start', 'content_block' => [ 'type' => 'text' ] ];
					yield [ 'type' => 'content_block_delta', 'delta' => [ 'type' => 'text_delta', 'text' => 'Resumed and done' ] ];
					yield [ 'type' => 'content_block_stop' ];
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:        $turn_id,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'build from https://berlinerteam.de'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		$this->assertSame( 2, $provider->calls, 'pause_turn must trigger a resume request' );

		// The resume request ends with the assistant turn (the paused server_tool_use),
		// NOT a new user message — that is how Anthropic detects the resume.
		$last  = end( $provider->secondCallMessages );
		$this->assertSame( 'assistant', $last['role'] );
		$types = array_column( $last['content'], 'type' );
		$this->assertContains( 'server_tool_use', $types );
	}
}
