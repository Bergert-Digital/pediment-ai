<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\VirtualTree;

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

	public function test_system_prompt_instructs_section_grouping(): void {
		$pb = new \PedimentAi\Chat\PromptBuilder( [ 'core/group' => [ 'description' => 'A section container.' ] ] );
		$prompt = $pb->systemPrompt();
		$this->assertStringContainsString( 'starter-band', $prompt );
		$this->assertStringContainsString( 'core/group', $prompt );
	}

	public function test_system_prompt_explains_container_composition_and_hints(): void {
		$pb     = new PromptBuilder( [
			'pediment/testimonial-grid' => [
				'description'        => 'A grid of testimonials.',
				'attributes'         => [],
				'allowsInnerBlocks'  => true,
				'allowedChildBlocks' => [ 'pediment/testimonial' ],
			],
			'pediment/testimonial' => [
				'description'    => 'A testimonial card.',
				'attributes'     => [ 'quote' => [ 'type' => 'string' ] ],
				'requiresParent' => [ 'pediment/testimonial-grid' ],
			],
		] );
		$prompt = $pb->systemPrompt();

		// A general rule on how to build a container: one insert_block, children nested.
		$this->assertStringContainsString( 'innerBlocks', $prompt );

		// Structural hints rendered on each block line so the model knows the relationship.
		$this->assertStringContainsString( 'contains: pediment/testimonial', $prompt );
		$this->assertStringContainsString( 'child of: pediment/testimonial-grid', $prompt );
	}

	public function test_system_prompt_prescribes_theme_respecting_layout(): void {
		$pb     = new \PedimentAi\Chat\PromptBuilder( [ 'core/group' => [ 'description' => 'A section container.' ] ] );
		$prompt = $pb->systemPrompt();
		// Sections must use the theme's constrained layout and lean on the theme's
		// own width settings — never force flow, never hard-code pixel widths.
		$this->assertStringContainsString( '"layout":{"type":"constrained"}', $prompt );
		$this->assertStringContainsString( '"align":"wide"', $prompt );
		$this->assertStringNotContainsString( '"type":"default"', $prompt );
	}

	public function test_system_prompt_is_filterable(): void {
		$cb = static function ( $prompt, $schema ) {
			return $prompt . "\n\nAcme brand voice: confident and concise.";
		};
		add_filter( 'pediment_ai_system_prompt', $cb, 10, 2 );

		$builder = new PromptBuilder( [
			'pediment/hero' => [
				'description'       => 'A hero block.',
				'attributes'        => [],
				'allowsInnerBlocks' => false,
			],
		] );

		$prompt = $builder->systemPrompt();

		$this->assertStringContainsString( 'Acme brand voice: confident and concise.', $prompt );
		$this->assertStringContainsString( 'pediment/hero', $prompt, 'Original prompt content must still be present.' );

		remove_filter( 'pediment_ai_system_prompt', $cb, 10 );
	}
}
