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
	/** Most URLs to retrieve server-side from a single user message. */
	private const MAX_PREFETCH = 3;

	private readonly PageFetcherInterface $pageFetcher;

	public function __construct(
		private readonly ConversationStore $store,
		private readonly Tools $tools,
		private readonly PromptBuilder $prompts,
		private readonly ProviderInterface $provider,
		private readonly string $model,
		?PageFetcherInterface $pageFetcher = null
	) {
		$this->pageFetcher = $pageFetcher ?? new PageFetcher();
	}

	/**
	 * @param array<int,array<string,mixed>> $history Prior conversation messages (role+content).
	 */
	public function run(
		int $turn_id,
		VirtualTree $tree,
		array $history,
		?string $selectedId,
		string $currentUserMsg,
		array $images = []
	): void {
		if ( $this->store->isAborted( $turn_id ) ) {
			return;
		}

		$messages = $this->prompts->historyToMessages( $history, 20 );

		// Retrieve any URLs in the user's message from this host, up front. Anthropic's
		// hosted web_fetch is blocked from some origins this server can reach — deep
		// pages on certain sites return url_not_accessible — and the model then burns
		// the turn flailing between web_search calls (and may build from a translated
		// or tangential page it *can* reach). Handing it the real content first, with
		// an instruction not to re-fetch, makes "build this page from <url>" reliable.
		// A reactive net for URLs the model discovers later lives further down.
		$prefetched = $this->prefetchUrls( $currentUserMsg );

		// The most recent user message — built as blocks so tree context (and any
		// prefetched reference content) sit alongside the user's text.
		$userContent = [ [ 'type' => 'text', 'text' => $this->prompts->contextMessage( $tree, $selectedId ) ] ];

		// Image attachments lead the turn — Anthropic recommends images before text.
		if ( [] !== $images ) {
			$imageBlocks = [];
			foreach ( $images as $img ) {
				$imageBlocks[] = [
					'type'   => 'image',
					'source' => [
						'type'       => 'base64',
						'media_type' => (string) ( $img['media_type'] ?? '' ),
						'data'       => (string) ( $img['data'] ?? '' ),
					],
				];
			}
			$userContent = array_merge( $imageBlocks, $userContent );
		}

		if ( [] !== $prefetched ) {
			$sections = [];
			foreach ( $prefetched as $url => $content ) {
				$sections[] = "URL: {$url}\n\n{$content}";
			}
			$userContent[] = [
				'type' => 'text',
				'text' => 'The content below was fetched server-side from the URL(s) in the request. Use it directly to build the page — mirror its structure, copy, and tone. Do NOT call web_fetch on these URLs; you already have their content.' . "\n\n" . implode( "\n\n---\n\n", $sections ),
			];
		}
		$userContent[] = [ 'type' => 'text', 'text' => $currentUserMsg ];
		$messages[]    = [ 'role' => 'user', 'content' => $userContent ];

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

		// URLs we have already retried server-side after a hosted web_fetch failure,
		// so the fallback fires at most once per URL and cannot loop. Seeded with the
		// URLs already provided up front, so the reactive net never re-fetches them.
		$fallbackAttempted = array_keys( $prefetched );

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
			$fetchUrlById      = []; // server_tool_use id => url, for web_fetch calls this round.
			$failedFetchUrls   = []; // URLs whose web_fetch result errored this round.
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
						// Remember which URL each web_fetch call targeted so a failed result
						// (matched by tool_use_id below) can be retried server-side.
						if ( 'web_fetch' === $current_server_tu['name'] && is_array( $stu_input ) && isset( $stu_input['url'] ) ) {
							$fetchUrlById[ $current_server_tu['id'] ] = (string) $stu_input['url'];
						}
						$current_server_tu = null;
					} elseif ( null !== $current_result ) {
						// Server-side fetch/search can fail (e.g. web_fetch returns
						// `url_not_accessible` when Anthropic's egress cannot retrieve the
						// origin — distinct from anything reachable via wp_remote_get on this
						// host). The model only paraphrases that in prose, so record the raw
						// error_code; otherwise the failure is undiagnosable after the fact.
						$server_err = self::serverToolResultError( $current_result );
						if ( null !== $server_err ) {
							$this->store->recordToolCall( $turn_id, [
								'tool'     => (string) ( $current_result['type'] ?? 'server_tool' ),
								'input'    => null,
								'output'   => [ 'error_code' => $server_err ],
								'is_error' => true,
							] );
							$tuid = (string) ( $current_result['tool_use_id'] ?? '' );
							if ( isset( $fetchUrlById[ $tuid ] ) ) {
								$failedFetchUrls[] = $fetchUrlById[ $tuid ];
							}
						}
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

			// Hosted web_fetch could not reach one or more URLs its egress is blocked
			// from. This host often can — retrieve each page server-side and feed its
			// text back so the model grounds the build in real content instead of
			// giving up. At most once per URL (see $fallbackAttempted). Skipped on
			// pause_turn, where a synthetic message would derail the tool's resume.
			if ( 'pause_turn' !== $stop_reason && [] !== $assistantContent ) {
				$recover  = array_values( array_diff( array_unique( $failedFetchUrls ), $fallbackAttempted ) );
				$injected = [];
				foreach ( $recover as $url ) {
					$fallbackAttempted[] = $url;
					$content             = $this->pageFetcher->fetch( $url );
					if ( null !== $content ) {
						$injected[] = "Content fetched from {$url}:\n\n{$content}";
					}
				}
				if ( [] !== $injected ) {
					$this->store->recordToolCall( $turn_id, [
						'tool'     => 'web_fetch_fallback',
						'input'    => [ 'urls' => $recover ],
						'output'   => [ 'recovered' => count( $injected ) ],
						'is_error' => false,
					] );
					$messages[]    = [ 'role' => 'assistant', 'content' => $assistantContent ];
					$userContent   = $toolResults;
					$userContent[] = [
						'type' => 'text',
						'text' => 'The web_fetch tool could not retrieve the requested page, but I fetched its content for you server-side. Use it to build the page — mirror its structure, copy, and tone.' . "\n\n" . implode( "\n\n---\n\n", $injected ),
					];
					$messages[] = [ 'role' => 'user', 'content' => $userContent ];
					continue;
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

	/**
	 * Extracts the error_code from a server-side tool result block, if it failed.
	 *
	 * Server-side web_search / web_fetch results arrive as a `*_tool_result` block whose
	 * `content` is either the payload (array of results, or a fetched-page object)
	 * or, on failure, a `{type: "..._tool_result_error", error_code: "..."}` object.
	 * Successful results carry no `error_code`; the code_execution result emitted by
	 * web_fetch's dynamic filtering reports failure via `return_code`, not here.
	 *
	 * @param array<string,mixed> $block A `*_tool_result` content block.
	 * @return string|null The error_code, or null when the block did not error.
	 */
	/**
	 * Retrieves up to self::MAX_PREFETCH URLs found in the user's message, server-side.
	 *
	 * @param string $message The user's message text.
	 * @return array<string,string> Map of URL => readable page text; only successful fetches.
	 */
	private function prefetchUrls( string $message ): array {
		$out = [];
		foreach ( self::extractUrls( $message ) as $url ) {
			$content = $this->pageFetcher->fetch( $url );
			if ( null !== $content ) {
				$out[ $url ] = $content;
			}
		}
		return $out;
	}

	/**
	 * Extracts up to self::MAX_PREFETCH distinct http(s) URLs from free text.
	 *
	 * @param string $text Arbitrary user text.
	 * @return string[] Distinct URLs, trailing sentence punctuation trimmed.
	 */
	private static function extractUrls( string $text ): array {
		if ( ! preg_match_all( '#https?://[^\s<>"\'\)]+#i', $text, $matches ) ) {
			return [];
		}
		$urls = [];
		foreach ( $matches[0] as $url ) {
			$url = rtrim( $url, '.,;:!?' );
			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
			if ( count( $urls ) >= self::MAX_PREFETCH ) {
				break;
			}
		}
		return $urls;
	}

	private static function serverToolResultError( array $block ): ?string {
		$content = $block['content'] ?? null;
		if ( is_array( $content ) && isset( $content['error_code'] ) && is_string( $content['error_code'] ) ) {
			return $content['error_code'];
		}
		return null;
	}
}
