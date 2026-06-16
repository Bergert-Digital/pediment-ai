<?php
/**
 * Orchestrates the Anthropic iterative tool-use loop for one chat turn.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

use PedimentAi\Anthropic\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TurnRunner {
	private const MAX_ITERATIONS = 20;
	private const MAX_TOKENS     = 16384;

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

		/**
		 * Filter the maximum number of agentic tool-use round-trips per turn.
		 *
		 * A multi-section page build needs one round-trip per batch of block
		 * mutations; the default headroom covers a full landing page.
		 *
		 * @param int $max Default self::MAX_ITERATIONS.
		 */
		$max_iterations = (int) apply_filters( 'pediment_ai_max_iterations', self::MAX_ITERATIONS );

		/**
		 * Filter the per-call max output tokens sent to the model.
		 *
		 * Too low truncates a batched set of block mutations mid-turn, wasting
		 * the iteration budget. Keep within the configured model's output ceiling.
		 *
		 * @param int $max Default self::MAX_TOKENS.
		 */
		$max_tokens = (int) apply_filters( 'pediment_ai_max_tokens', self::MAX_TOKENS );

		// Tracks whether any narration has been streamed into the stored message
		// yet — across the whole turn, not just this round. Used to bridge the
		// boundary between successive text blocks with a blank line so a round's
		// closing sentence and the next round's opening sentence don't render
		// glued together ("…failed.Let me…").
		$streamed_text = false;

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			if ( $this->store->isAborted( $turn_id ) ) {
				return;
			}

			$result = $this->provider->stream_messages( [
				'model'      => $this->model,
				'max_tokens' => $max_tokens,
				'system'     => $this->prompts->systemPrompt(),
				'tools'      => $this->tools->definitions(),
				'messages'   => $messages,
			] );

			if ( is_wp_error( $result ) ) {
				$this->store->fail( $turn_id, $result->get_error_code(), $result->get_error_message() );
				return;
			}

			$assistantContent  = [];
			$toolResults       = [];
			$stop_reason       = null;
			$current_tu        = null;
			$current_server_tu = null; // Anthropic-hosted tool call (web_search/web_fetch); echoed back, never applied client-side.
			$current_result    = null; // Server tool result block; arrives whole and is echoed back verbatim to keep fetched content in context.
			$current_text      = null; // Aggregated text per content_block index; null when no text block is open.

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
					} elseif ( 'server_tool_use' === $block_type ) {
						// web_search / web_fetch — input streams like a tool_use, but the
						// call runs on Anthropic's side, so we never dispatch it.
						$current_server_tu = [
							'type'  => 'server_tool_use',
							'id'    => (string) ( $event['content_block']['id']   ?? '' ),
							'name'  => (string) ( $event['content_block']['name'] ?? '' ),
							'input' => '',
						];
					} elseif ( str_ends_with( $block_type, '_tool_result' ) ) {
						// Any server-side tool result: web_search/web_fetch, plus the
						// code_execution result that web_fetch's dynamic filtering emits to
						// trim a fetched page before it hits context. These arrive fully
						// formed in content_block_start (no deltas); stash whole so every
						// server_tool_use is echoed back with its matching result —
						// Anthropic 400s on a server_tool_use that has no result block.
						$current_result = is_array( $event['content_block'] ?? null ) ? $event['content_block'] : null;
					} elseif ( 'text' === $block_type ) {
						$current_text = '';
					}
					continue;
				}
				if ( 'content_block_delta' === $type ) {
					$delta = $event['delta'] ?? [];
					if ( 'text_delta' === ( $delta['type'] ?? '' ) ) {
						$text = (string) ( $delta['text'] ?? '' );
						if ( '' !== $text ) {
							// First non-empty chunk of a fresh text block while narration
							// already streamed earlier this turn (a prior round, or a text
							// block before a tool_use in this round): prepend a blank line so
							// the boundary renders as a paragraph break, not a run-on. The
							// model's own within-block newlines are left untouched.
							// '' === $current_text marks the block's first delta (set to ''
							// at content_block_start, non-empty once any text has landed).
							if ( $streamed_text && '' === (string) $current_text ) {
								$this->store->appendAssistantDelta( $turn_id, "\n\n" );
							}
							$this->store->appendAssistantDelta( $turn_id, $text );
							$streamed_text = true;
						}
						// Aggregate per-block, not per-delta — Anthropic rejects empty text blocks
						// and also dislikes many tiny adjacent text blocks when we echo them back.
						if ( null === $current_text ) {
							$current_text = '';
						}
						$current_text .= $text;
					} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) ) {
						if ( null !== $current_tu ) {
							$current_tu['input'] .= (string) ( $delta['partial_json'] ?? '' );
						} elseif ( null !== $current_server_tu ) {
							$current_server_tu['input'] .= (string) ( $delta['partial_json'] ?? '' );
						}
					}
					continue;
				}
				if ( 'content_block_stop' === $type ) {
					if ( null !== $current_tu ) {
						$tu_input = json_decode( $current_tu['input'], true );
						// A tool_use whose input did not fully arrive (e.g. the model
						// hit max_tokens mid-call) decodes to null/[]. Sending it back
						// would serialize as JSON [] and Anthropic rejects it
						// ("tool_use.input: Input should be an object"). Drop the
						// incomplete call entirely — no apply, no assistant block, no
						// orphan tool_result — and let the model re-issue it next round.
						if ( ! is_array( $tu_input ) || [] === $tu_input ) {
							$current_tu = null;
							continue;
						}
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
					} elseif ( null !== $current_server_tu ) {
						// Echo the server tool call back verbatim — no client-side apply, no
						// tool_result of our own (Anthropic runs it and returns the result
						// as its own block). input must serialize as an object, never [].
						$stu_input          = json_decode( $current_server_tu['input'], true );
						$assistantContent[] = [
							'type'  => 'server_tool_use',
							'id'    => $current_server_tu['id'],
							'name'  => $current_server_tu['name'],
							'input' => is_array( $stu_input ) && [] !== $stu_input ? $stu_input : new \stdClass(),
						];
						$current_server_tu = null;
					} elseif ( null !== $current_result ) {
						// Echo the fetch/search result block verbatim so the retrieved page
						// stays in context across the remaining loop iterations.
						$assistantContent[] = $current_result;
						$current_result     = null;
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

			// 'pause_turn' means Anthropic's server-side tool loop (web_search /
			// web_fetch) hit its internal iteration limit mid-execution. The
			// assistant content ends in a server_tool_use; re-send it verbatim with
			// NO new user message and the API resumes the paused tool automatically.
			// Injecting a user message here would derail that resume.
			if ( 'pause_turn' === $stop_reason ) {
				if ( [] === $assistantContent ) {
					$this->store->fail(
						$turn_id,
						'response_truncated',
						'The response paused before anything could be applied. Ask me to continue.'
					);
					return;
				}
				$messages[] = [ 'role' => 'assistant', 'content' => $assistantContent ];
				// Defensive: a stray client tool_result must not be orphaned.
				if ( [] !== $toolResults ) {
					$messages[] = [ 'role' => 'user', 'content' => $toolResults ];
				}
				continue;
			}

			// Nothing usable survived this round (e.g. the only block was a
			// truncated tool_use). Sending an empty assistant message back is
			// rejected by Anthropic ("text content blocks must be non-empty");
			// end the turn cleanly with an actionable message instead.
			if ( [] === $assistantContent ) {
				$this->store->fail(
					$turn_id,
					'response_truncated',
					'The response was cut off before anything could be applied. Ask me to continue.'
				);
				return;
			}

			// Continue the loop: append assistant turn, plus tool_results when present.
			// A round that only ran server tools (web_fetch) yields assistant content
			// but no client tool_result — appending an empty user message 400s.
			$messages[] = [ 'role' => 'assistant', 'content' => $assistantContent ];
			if ( [] !== $toolResults ) {
				$messages[] = [ 'role' => 'user', 'content' => $toolResults ];
			}
		}

		// Iteration cap.
		$this->store->fail( $turn_id, 'iteration_limit', 'Reached maximum tool-use iterations.' );
	}
}
