<?php
namespace StarterAi\Tests\Chat;

use StarterAi\Chat\PromptBuilder;
use StarterAi\Chat\VirtualTree;

class PromptBuilderTest extends \WP_UnitTestCase {
	public function test_system_prompt_lists_block_names_and_tool_conventions(): void {
		$pb = new PromptBuilder( [
			'core/paragraph' => [ 'description' => 'A paragraph.', 'attributes' => [], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'description' => 'A heading.',   'attributes' => [], 'allowsInnerBlocks' => false ],
		] );
		$sys = $pb->systemPrompt();
		$this->assertStringContainsString( 'core/paragraph', $sys );
		$this->assertStringContainsString( 'core/heading',   $sys );
		$this->assertStringContainsString( 'insert_block',   $sys );
	}

	public function test_context_message_includes_selection_chip(): void {
		$pb = new PromptBuilder( [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ] );
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Selected.' ], 'innerBlocks' => [] ],
		] );
		$msg = $pb->contextMessage( $tree, 'a' );
		$this->assertStringContainsString( '"selected_block"', $msg );
		$this->assertStringContainsString( '"clientId":"a"',   $msg );
	}

	public function test_history_slice_keeps_last_n_turns(): void {
		$pb = new PromptBuilder( [] );
		$history = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$history[] = [ 'role' => 'user',      'content' => "u{$i}", 'tool_calls' => [] ];
			$history[] = [ 'role' => 'assistant', 'content' => "a{$i}", 'tool_calls' => [] ];
		}
		$sliced = $pb->historyToMessages( $history, 20 );
		$this->assertCount( 20, $sliced );
		$this->assertSame( 'u20', $sliced[0]['content'][0]['text'] );
	}

	public function test_history_skips_messages_with_empty_content(): void {
		$pb     = new PromptBuilder( [] );
		$sliced = $pb->historyToMessages( [
			[ 'role' => 'user',      'content' => 'first prompt',   'tool_calls' => [] ],
			[ 'role' => 'assistant', 'content' => '',                'tool_calls' => [ [ 'tool' => 'insert_block' ] ] ],
			[ 'role' => 'user',      'content' => 'second prompt',   'tool_calls' => [] ],
			[ 'role' => 'assistant', 'content' => "   \n  ",          'tool_calls' => [] ],
		] );
		$this->assertCount( 2, $sliced );
		$this->assertSame( 'first prompt',  $sliced[0]['content'][0]['text'] );
		$this->assertSame( 'second prompt', $sliced[1]['content'][0]['text'] );
	}
}
