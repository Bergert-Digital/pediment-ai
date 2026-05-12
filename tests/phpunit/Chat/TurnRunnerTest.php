<?php
namespace StarterAi\Tests\Chat;

use StarterAi\Chat\ConversationStore;
use StarterAi\Chat\PromptBuilder;
use StarterAi\Chat\Tools;
use StarterAi\Chat\TurnRunner;
use StarterAi\Chat\VirtualTree;
use StarterAi\BlockTree\Validator;
use StarterAi\Mock\MockProvider;

class TurnRunnerTest extends \WP_UnitTestCase {
	private ConversationStore $store;
	private Tools $tools;
	private PromptBuilder $prompts;
	private MockProvider $provider;

	public function setUp(): void {
		parent::setUp();
		\starter_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}starter_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}starter_ai_chat_messages" );

		$schema         = [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ];
		$this->store    = new ConversationStore();
		$this->tools    = new Tools( $schema, new Validator( $schema ) );
		$this->prompts  = new PromptBuilder( $schema );
		$this->provider = new MockProvider( STARTER_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
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

		$broken = new class implements \StarterAi\Anthropic\ProviderInterface {
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
}
