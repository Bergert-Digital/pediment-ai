<?php
/**
 * Parses Gutenberg block markup into a simple JSON tree.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts WP parsed-block arrays into the plugin's internal tree shape.
 */
final class Parser {
	/**
	 * Parse Gutenberg block markup into a simple JSON tree.
	 *
	 * @param string $content Raw post_content.
	 * @return array<int, array{name:string, attributes:array<string,mixed>, innerBlocks:array}>
	 */
	public function parse( string $content ): array {
		if ( '' === trim( $content ) ) {
			return [];
		}
		return $this->map( parse_blocks( $content ) );
	}

	/**
	 * @param array<int, array<string,mixed>> $blocks WP parsed blocks.
	 * @return array<int, array{name:string, attributes:array, innerBlocks:array}>
	 */
	private function map( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( '' === $name ) {
				continue;
			}
			$out[] = [
				'name'        => $name,
				'attributes'  => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
				'innerBlocks' => $this->map( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [] ),
			];
		}
		return $out;
	}
}
