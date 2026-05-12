<?php
/**
 * Orchestrates the Anthropic iterative tool-use loop for one chat turn.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Chat;

use StarterAi\Anthropic\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TurnRunner {
	private const MAX_ITERATIONS = 8;
	private const MAX_TOKENS     = 4096;

	public function __construct(
		private readonly ConversationStore $store,
		private readonly Tools $tools,
		private readonly PromptBuilder $prompts,
		private readonly ProviderInterface $provider,
		private readonly string $model
	) {}

	/**
	 * @param array<int,array<string,mixed>> $history Prior conversation messages (role+content).
	 */
	public function run(
		int $turn_id,
		VirtualTree $tree,
		array $history,
		?string $selectedId,
		string $currentUserMsg
	): void {
		if ( $this->store->isAborted( $turn_id ) ) {
			return;
		}

		$messages = $this->prompts->historyToMessages( $history, 20 );
		// The most recent user message — separately, so we can prepend tree context to it.
		$messages[] = [
			'role'    => 'user',
			'content' => [
				[ 'type' => 'text', 'text' => $this->prompts->contextMessage( $tree, $selectedId ) ],
				[ 'type' => 'text', 'text' => $currentUserMsg ],
			],
		];

		for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
			if ( $this->store->isAborted( $turn_id ) ) {
				return;
			}

			$result = $this->provider->stream_messages( [
				'model'      => $this->model,
				'max_tokens' => self::MAX_TOKENS,
				'system'     => $this->prompts->systemPrompt(),
				'tools'      => $this->tools->definitions(),
				'messages'   => $messages,
			] );

			if ( is_wp_error( $result ) ) {
				$this->store->fail( $turn_id, $result->get_error_code(), $result->get_error_message() );
				return;
			}

			$assistantContent = [];
			$toolResults      = [];
			$stop_reason      = null;
			$current_tu       = null;
			$current_text     = null; // aggregated text per content_block index, null when no text block is open

			foreach ( $result as $event ) {
				if ( $this->store->isAborted( $turn_id ) ) {
					return;
				}
				$type = (string) ( $event['type'] ?? '' );

				if ( 'content_block_start' === $type ) {
					$block_type = (string) ( $event['content_block']['type'] ?? '' );
					if ( 'tool_use' === $block_type ) {
						$current_tu = [
							'type'  => 'tool_use',
							'id'    => (string) ( $event['content_block']['id']   ?? '' ),
							'name'  => (string) ( $event['content_block']['name'] ?? '' ),
							'input' => '',
						];
					} elseif ( 'text' === $block_type ) {
						$current_text = '';
					}
					continue;
				}
				if ( 'content_block_delta' === $type ) {
					$delta = $event['delta'] ?? [];
					if ( 'text_delta' === ( $delta['type'] ?? '' ) ) {
						$text = (string) ( $delta['text'] ?? '' );
						$this->store->appendAssistantDelta( $turn_id, $text );
						// Aggregate per-block, not per-delta — Anthropic rejects empty text blocks
						// and also dislikes many tiny adjacent text blocks when we echo them back.
						if ( null === $current_text ) {
							$current_text = '';
						}
						$current_text .= $text;
					} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) && null !== $current_tu ) {
						$current_tu['input'] .= (string) ( $delta['partial_json'] ?? '' );
					}
					continue;
				}
				if ( 'content_block_stop' === $type ) {
					if ( null !== $current_tu ) {
						$tu_input    = json_decode( $current_tu['input'], true );
						$tu_input    = is_array( $tu_input ) ? $tu_input : [];
						$tool_result = $this->tools->apply( $tree, $current_tu['name'], $tu_input );
						$this->store->recordToolCall( $turn_id, [
							'tool'     => $current_tu['name'],
							'input'    => $tu_input,
							'output'   => $tool_result['content'] ?? null,
							'is_error' => ! empty( $tool_result['is_error'] ),
						] );
						$assistantContent[] = [
							'type'  => 'tool_use',
							'id'    => $current_tu['id'],
							'name'  => $current_tu['name'],
							'input' => $tu_input,
						];
						$toolResults[] = [
							'type'        => 'tool_result',
							'tool_use_id' => $current_tu['id'],
							'content'     => is_string( $tool_result['content'] ) ? $tool_result['content'] : wp_json_encode( $tool_result['content'] ),
							'is_error'    => ! empty( $tool_result['is_error'] ),
						];
						$current_tu = null;
					} elseif ( null !== $current_text ) {
						// Only emit the text block if it has content — empty blocks get rejected.
						if ( '' !== $current_text ) {
							$assistantContent[] = [ 'type' => 'text', 'text' => $current_text ];
						}
						$current_text = null;
					}
					continue;
				}
				if ( 'message_delta' === $type ) {
					$stop_reason = (string) ( $event['delta']['stop_reason'] ?? '' );
				}
			}

			if ( 'end_turn' === $stop_reason || '' === $stop_reason || null === $stop_reason ) {
				$this->store->complete( $turn_id );
				return;
			}

			// Continue the loop: append assistant turn + tool_results, then call again.
			$messages[] = [ 'role' => 'assistant', 'content' => $assistantContent ];
			$messages[] = [ 'role' => 'user',      'content' => $toolResults ];
		}

		// Iteration cap.
		$this->store->fail( $turn_id, 'iteration_limit', 'Reached maximum tool-use iterations.' );
	}
}
