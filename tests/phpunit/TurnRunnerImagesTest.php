<?php
namespace PedimentAi\Tests;

use PedimentAi\Anthropic\ProviderInterface;
use PedimentAi\BlockTree\Validator;
use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\PageFetcherInterface;
use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\TurnRunner;
use PedimentAi\Chat\VirtualTree;

class TurnRunnerImagesTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		pediment_ai_install_tables();
	}

	public function test_run_prepends_image_content_blocks(): void {
		$store = new ConversationStore();
		$conv  = $store->getOrCreate( 10, 1 );
		$store->appendUserMessage( $conv['id'], 'build this', [] );
		$turn  = $store->startAssistantTurn( $conv['id'] );

		$provider = new class implements ProviderInterface {
			public array $lastArgs = [];
			public function messages( array $args ) { return []; }
			public function stream_messages( array $args ) {
				$this->lastArgs = $args;
				return ( static function () {
					yield [ 'type' => 'message_delta', 'delta' => [ 'stop_reason' => 'end_turn' ] ];
				} )();
			}
		};
		$pageFetcher = new class implements PageFetcherInterface {
			public function fetch( string $url ): ?string { return null; }
		};

		$runner = new TurnRunner(
			$store,
			new Tools( [], new Validator( [] ) ),
			new PromptBuilder( [] ),
			$provider,
			'claude-sonnet-4-6',
			$pageFetcher
		);

		$runner->run(
			turn_id:        $turn,
			tree:           new VirtualTree( [] ),
			history:        [],
			selectedId:     null,
			currentUserMsg: 'build this',
			images:         [ [ 'media_type' => 'image/png', 'data' => 'AAAB' ] ]
		);

		$messages = $provider->lastArgs['messages'];
		$userMsg  = end( $messages );
		$imageBlocks = array_values( array_filter(
			$userMsg['content'],
			static fn( $b ) => ( $b['type'] ?? '' ) === 'image'
		) );

		$this->assertCount( 1, $imageBlocks );
		$this->assertSame( 'base64', $imageBlocks[0]['source']['type'] );
		$this->assertSame( 'image/png', $imageBlocks[0]['source']['media_type'] );
		$this->assertSame( 'AAAB', $imageBlocks[0]['source']['data'] );
		// Image block must lead the user turn (array_merge puts images first).
		$this->assertSame( 'image', $userMsg['content'][0]['type'] );
	}
}
