<?php
/**
 * Builds the system prompt and context messages for a chat turn.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PromptBuilder {
	/**
	 * @param array<string,array<string,mixed>> $blockSchema
	 */
	public function __construct( private readonly array $blockSchema ) {}

	public function systemPrompt(): string {
		$lines   = [];
		$lines[] = 'You are an AI assistant inside the WordPress block editor. The user is composing or editing a post and is chatting with you in a sidebar.';
		$lines[] = 'When the user asks you to change the post, call the appropriate tool: insert_block, update_block, delete_block, move_block. Use read_block to fetch the full content of a block whose content is shown truncated in your initial context.';
		$lines[] = 'Mutation tool calls are applied at the end of your turn — you do not see the post change between calls. The synthetic tool_result you receive for inserts contains the new client_id; use it for subsequent calls in the same turn that reference the inserted block.';
		$lines[] = 'Write naturally and concisely in your prose. Do not over-explain. Do not apologize. If you are not changing the post, simply answer the question.';
		$lines[] = '';
		$lines[] = 'Page structure: compose a page as a sequence of distinct sections. Wrap each section\'s blocks in a core/group with attributes {"tagName":"section","className":"starter-section"}. Do not emit a flat list of top-level paragraphs or headings — group them into their section. If you do not wrap a section in a group, place a core/separator between sections.';
		$lines[] = '';
		$lines[] = 'Available blocks (use these — do not invent block names):';
		foreach ( $this->blockSchema as $name => $info ) {
			$description = isset( $info['description'] ) ? (string) $info['description'] : '';
			$lines[]     = '' !== $description ? "- {$name} — {$description}" : "- {$name}";
		}
		$prompt = implode( "\n", $lines );

		/**
		 * Filter the system prompt used by the AI plugin for chat turns.
		 *
		 * Runs on every chat turn; the result is not cached.
		 *
		 * @param string                            $prompt      Composed system prompt.
		 * @param array<string,array<string,mixed>> $blockSchema The block schema available to this turn.
		 */
		return (string) apply_filters( 'pediment_ai_system_prompt', $prompt, $this->blockSchema );
	}

	/**
	 * Returns a single user-content text part representing the current tree + selection.
	 */
	public function contextMessage( VirtualTree $tree, ?string $selectedClientId ): string {
		$payload = [
			'block_tree'     => $tree->skeleton( $selectedClientId, 3 ),
			'selected_block' => null === $selectedClientId ? null : $tree->find( $selectedClientId ),
		];
		return "Current post state:\n" . wp_json_encode( $payload );
	}

	/**
	 * Convert stored history rows into Anthropic Messages format. Keeps the last $maxTurns
	 * messages (each row is one message; pairs are user+assistant).
	 *
	 * @param array<int,array<string,mixed>> $history
	 * @return array<int,array<string,mixed>>
	 */
	public function historyToMessages( array $history, int $maxTurns = 20 ): array {
		$sliced = array_slice( $history, max( 0, count( $history ) - $maxTurns ) );
		$out    = [];
		foreach ( $sliced as $msg ) {
			$role = (string) ( $msg['role'] ?? '' );
			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}
			// Anthropic rejects empty text content blocks. An assistant message with no
			// prose (only tool calls) has content=''; skip it in history — the tool calls'
			// effects are already reflected in the current block tree we send each turn.
			$content = trim( (string) ( $msg['content'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}
			$out[] = [
				'role'    => $role,
				'content' => [ [ 'type' => 'text', 'text' => $content ] ],
			];
		}
		return $out;
	}
}
