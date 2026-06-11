<?php
/**
 * Validates a block tree against the runtime block schema.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\BlockTree;

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
	public function validate( array $tree, string $path = '', int $depth = 0 ): array {
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

			// A parent-locked child block (requiresParent) may only appear nested inside
			// one of its parents — which means it must be at depth > 0. At the top level
			// there is no parent (the insert tools cannot place a block inside an existing
			// container), so a top-level child is an orphan the editor cannot render. Reject
			// it and tell the model to insert the parent with this block nested in innerBlocks.
			if ( 0 === $depth && ! empty( $spec['requiresParent'] ) ) {
				$parents  = (array) $spec['requiresParent'];
				$errors[] = sprintf(
					'%s: block "%s" can only be used inside %s — insert that container in a single insert_block call with "%s" nested in its innerBlocks, not on its own',
					$here,
					$name,
					implode( ' or ', $parents ),
					$name
				);
				// Still validate this node's own inner blocks below so the model gets all
				// errors at once, but the orphan error above is the actionable one.
			}

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
				$errors = array_merge( $errors, $this->validate( $inner, $here . '.innerBlocks', $depth + 1 ) );
			}
		}
		return $errors;
	}

	/**
	 * Validate a single block node (used per tool call in chat).
	 *
	 * @param array<string,mixed> $node
	 * @return string[] Errors; empty means valid.
	 */
	public function validateNode( array $node ): array {
		return $this->validate( [ $node ] );
	}
}
