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
		$lines[] = 'You can read the live web. When the user gives a URL or asks you to base the page on a specific website, call web_fetch to retrieve it. If the conversation already includes a URL\'s content as server-side reference content, use that directly and do NOT call web_fetch for that URL. When they name a page or site without a URL, call web_search to find it, then web_fetch the best result. Ground the blocks you build in the fetched content — mirror its structure, copy, and tone instead of inventing placeholder text. You may only fetch URLs that appear in the conversation or in search results.';
		$lines[] = '';
		$lines[] = 'Page structure: compose a page as a FLAT sequence of distinct top-level sections — the theme calls these "bands". Wrap each section\'s blocks in a full-width core/group band: attributes {"align":"full","className":"starter-band is-style-band-surface","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}}. Every band is a TOP-LEVEL sibling — never nest one band inside another, and never wrap the whole page in a single outer group; that breaks section ordering. The constrained layout spans the full width while centering inner content at the theme\'s content width. Vary the band background per section for visual rhythm via its style: "is-style-band-surface" (white, the default), "is-style-band-elevated" (subtly tinted), or "is-style-band-navy" (dark). For blocks that should fill the wider band (multi-column grids, stat or feature rows, wide media), set "align":"wide" so they pick up the theme\'s wide width. Do not assume fixed pixel widths — rely on the active theme\'s content/wide sizes. Do not emit a flat list of top-level paragraphs or headings — group them into their band.';
		$lines[] = '';
		$lines[] = 'Self-contained section blocks — pediment/hero, pediment/cta, pediment/faq, pediment/testimonial-grid, pediment/stat-grid — each ARE a full section on their own. Give each its own top-level band that contains ONLY that one block, set to "align":"wide". Never tuck one of these at the end of another section\'s band (for example a pediment/cta after a heading-and-list services band) — that buries a standalone section inside an unrelated one. A band that holds one of these holds nothing else.';
		$lines[] = '';
		$lines[] = 'Testimonials: for a customer-quote / "what clients say" / Kundenstimmen section, emit one pediment/testimonial-grid (align "wide") containing pediment/testimonial children (quote + authorName + authorRole), not a stack of pediment/pull-quote blocks. Use pediment/pull-quote only for a single standalone highlighted quote.';
		$lines[] = '';
		$lines[] = 'Stats: for a key-figures / "numbers & facts" / Zahlen & Fakten section, emit one pediment/stat-grid (align "wide") containing pediment/stat children (value + label + optional context), so the figures sit side by side. Never emit bare pediment/stat blocks stacked on their own or wrapped in core/columns.';
		$lines[] = '';
		$lines[] = 'Lists: a bulleted or numbered list is ONE core/list block with each item as a core/list-item in its innerBlocks; the item text goes in the list-item\'s `content` attribute (inline HTML like <strong> is fine). Set the list\'s `ordered` attribute to true for a numbered list. Do NOT put the items in a `values` attribute or as raw <li>/<ul> HTML on the list — that legacy shape renders as an empty list. A core/list with no list-item children is rejected. Example, exactly this shape: {"name":"core/list","attributes":{"ordered":false},"innerBlocks":[{"name":"core/list-item","attributes":{"content":"First point"}},{"name":"core/list-item","attributes":{"content":"Second point"}}]}.';
		$lines[] = '';
		$lines[] = 'Text beside an image: for a section that pairs a block of copy with an image side by side (the classic "text left, image right" / media-and-text layout), emit one pediment/media-text (align "wide"). Put the heading, paragraphs and any list in its innerBlocks (core/heading, core/paragraph, core/list — same as prose), and set its `mediaPosition` attribute to "left" or "right" to choose which side the image sits on (default "right"). The user supplies the actual image afterward, so you do not need a media id.';
		$lines[] = '';
		$lines[] = 'Available blocks (use these — do not invent block names). A line tagged [contains: …] is a container: build it in ONE insert_block call with each child placed in block.innerBlocks (every child is {name, attributes}). You cannot add children to a container after it exists — there is no insert-into-parent operation. A line tagged [child of: …] may only appear nested inside that parent; never insert it on its own (it is rejected).';
		foreach ( $this->blockSchema as $name => $info ) {
			$description = isset( $info['description'] ) ? (string) $info['description'] : '';
			$line        = '' !== $description ? "- {$name} — {$description}" : "- {$name}";

			$children = isset( $info['allowedChildBlocks'] ) && is_array( $info['allowedChildBlocks'] )
				? array_values( array_unique( $info['allowedChildBlocks'] ) )
				: [];
			if ( ! empty( $children ) ) {
				$line .= ' [contains: ' . implode( ', ', $children ) . ' — nest these in innerBlocks]';
			}

			$parents = isset( $info['requiresParent'] ) && is_array( $info['requiresParent'] )
				? array_values( $info['requiresParent'] )
				: [];
			if ( ! empty( $parents ) ) {
				$line .= ' [child of: ' . implode( ', ', $parents ) . ' — never insert on its own]';
			}

			$lines[] = $line;
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
