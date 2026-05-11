<?php
/**
 * Validates a block tree against the runtime block schema.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates block-name presence, container/child relationships, and attributes shape.
 */
final class Validator {
	/**
	 * @param array<string, array<string,mixed>> $schema blockName => { attributes, allowsInnerBlocks, allowedChildBlocks? }.
	 */
	public function __construct( private readonly array $schema ) {}

	/**
	 * @param array<int, array<string,mixed>> $tree Block tree.
	 * @param string                          $path Path prefix for error messages.
	 * @return string[] Errors; empty means valid.
	 */
	public function validate( array $tree, string $path = '' ): array {
		$errors = [];
		foreach ( $tree as $i => $node ) {
			$here = $path . '[' . $i . ']';

			$name = isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : '';
			if ( '' === $name ) {
				$errors[] = $here . ': missing block name';
				continue;
			}

			if ( ! isset( $this->schema[ $name ] ) ) {
				$errors[] = sprintf( '%s: unknown block "%s"', $here, $name );
				continue;
			}

			if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
				$errors[] = $here . ': attributes must be an object';
			}

			$spec   = $this->schema[ $name ];
			$inner  = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ? $node['innerBlocks'] : [];
			$allows = ! empty( $spec['allowsInnerBlocks'] );

			if ( ! empty( $inner ) && ! $allows ) {
				$errors[] = sprintf( '%s: block "%s" does not allow inner blocks', $here, $name );
				continue;
			}

			if ( ! empty( $spec['allowedChildBlocks'] ) ) {
				foreach ( $inner as $j => $child ) {
					$cname = isset( $child['name'] ) ? (string) $child['name'] : '';
					if ( '' !== $cname && ! in_array( $cname, (array) $spec['allowedChildBlocks'], true ) ) {
						$errors[] = sprintf( '%s.innerBlocks[%d]: "%s" not in allowedChildBlocks of "%s"', $here, $j, $cname, $name );
					}
				}
			}

			if ( ! empty( $inner ) ) {
				$errors = array_merge( $errors, $this->validate( $inner, $here . '.innerBlocks' ) );
			}
		}
		return $errors;
	}
}
