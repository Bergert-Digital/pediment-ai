<?php
/**
 * Extracts emit_page / emit_block tool calls and web_fetch URLs from an Anthropic response.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Anthropic Messages API response content blocks.
 */
final class ToolUseParser {
	/**
	 * @param array<string,mixed> $response Full Anthropic Messages response body.
	 * @return array{tool: ?string, input: array<string,mixed>, urls_fetched: string[]}
	 */
	public function parse( array $response ): array {
		$tool         = null;
		$input        = [];
		$urls_fetched = [];

		$content = $response['content'] ?? [];
		if ( ! is_array( $content ) ) {
			return [ 'tool' => null, 'input' => [], 'urls_fetched' => [] ];
		}

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = (string) ( $block['type'] ?? '' );

			if ( 'tool_use' === $type && in_array( $block['name'] ?? '', [ 'emit_page', 'emit_block' ], true ) ) {
				$tool  = (string) $block['name'];
				$input = is_array( $block['input'] ?? null ) ? $block['input'] : [];
				continue;
			}

			if ( 'server_tool_use' === $type && ( $block['name'] ?? '' ) === 'web_fetch' ) {
				$url = (string) ( $block['input']['url'] ?? '' );
				if ( '' !== $url ) {
					$urls_fetched[] = $url;
				}
			}
		}

		return [ 'tool' => $tool, 'input' => $input, 'urls_fetched' => $urls_fetched ];
	}
}
