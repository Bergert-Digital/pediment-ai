<?php
/**
 * Serializes a block tree into Gutenberg block markup.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts the plugin's internal tree shape into Gutenberg block markup.
 */
final class Serializer {
	/**
	 * Serialize a JSON tree into Gutenberg block markup.
	 *
	 * @param array<int, array{name:string, attributes:array, innerBlocks:array}> $tree Block tree.
	 * @return string
	 */
	public function serialize( array $tree ): string {
		if ( [] === $tree ) {
			return '';
		}
		$wp_blocks = array_map( [ $this, 'toWpBlock' ], $tree );
		return serialize_blocks( $wp_blocks );
	}

	/**
	 * @param array{name:string, attributes:array, innerBlocks:array} $node Block node.
	 * @return array<string,mixed> WP parsed-block shape.
	 */
	private function toWpBlock( array $node ): array {
		$inner_blocks  = array_map( [ $this, 'toWpBlock' ], $node['innerBlocks'] ?? [] );
		$inner_content = [] === $inner_blocks ? [] : array_fill( 0, count( $inner_blocks ), null );
		return [
			'blockName'    => $node['name'],
			'attrs'        => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => $inner_content,
		];
	}
}
