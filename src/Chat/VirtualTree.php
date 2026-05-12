<?php
/**
 * In-memory mutable block tree used during a chat turn.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the tree of blocks for one turn. Tracks clientIds; generates new ones for inserts.
 * Inner-block mutations are addressable by clientId — the methods recurse.
 */
final class VirtualTree {
	/** @var array<int,array<string,mixed>> */
	private array $tree;

	private int $counter = 0;

	/**
	 * @param array<int,array<string,mixed>> $initial Blocks as { clientId, name, attributes, innerBlocks }.
	 */
	public function __construct( array $initial ) {
		$this->tree = $this->normalize( $initial );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function toArray(): array {
		return $this->tree;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( string $clientId ): ?array {
		$located = $this->locateInTree( $this->tree, $clientId );
		return $located !== null ? $located['node'] : null;
	}

	/**
	 * Insert a block. $afterClientId may be null when $position is "start" or "end".
	 *
	 * @param array{name:string, attributes:array<string,mixed>, innerBlocks?:array<int,array<string,mixed>>} $block
	 */
	public function insert( ?string $afterClientId, string $position, array $block ): string {
		$cid        = $this->mintClientId();
		$normalized = $this->normalizeNode( $block, $cid );

		if ( 'start' === $position ) {
			array_unshift( $this->tree, $normalized );
			return $cid;
		}

		if ( 'end' === $position || null === $afterClientId ) {
			$this->tree[] = $normalized;
			return $cid;
		}

		$located = $this->locateInTree( $this->tree, $afterClientId );
		if ( null === $located ) {
			$this->tree[] = $normalized;
			return $cid;
		}

		$insertAt = 'before' === $position ? $located['index'] : $located['index'] + 1;
		$this->spliceIntoSiblings( $located['parentPath'], $insertAt, 0, [ $normalized ] );
		return $cid;
	}

	/**
	 * @param array<string,mixed>|null $attrs
	 * @param string|null              $content Convenience: written into $attrs['content'].
	 */
	public function update( string $clientId, ?array $attrs, ?string $content ): bool {
		$located = $this->locateInTree( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}

		$this->mutateNode(
			$located['parentPath'],
			$located['index'],
			function ( array $node ) use ( $attrs, $content ): array {
				if ( is_array( $attrs ) ) {
					$node['attributes'] = array_merge( $node['attributes'], $attrs );
				}
				if ( null !== $content ) {
					$node['attributes']['content'] = $content;
				}
				return $node;
			}
		);
		return true;
	}

	public function delete( string $clientId ): bool {
		$located = $this->locateInTree( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}
		$this->spliceIntoSiblings( $located['parentPath'], $located['index'], 1, [] );
		return true;
	}

	public function move( string $clientId, string $targetClientId, string $position ): bool {
		$located = $this->locateInTree( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}

		// Snapshot the node, then remove it.
		$node = $located['node'];
		$this->spliceIntoSiblings( $located['parentPath'], $located['index'], 1, [] );

		// Re-locate the target after the removal (indices may have shifted).
		$targetLoc = $this->locateInTree( $this->tree, $targetClientId );
		if ( null === $targetLoc ) {
			// Target gone — re-insert at root end as a safe fallback.
			$this->tree[] = $node;
			return false;
		}

		$insertAt = 'before' === $position ? $targetLoc['index'] : $targetLoc['index'] + 1;
		$this->spliceIntoSiblings( $targetLoc['parentPath'], $insertAt, 0, [ $node ] );
		return true;
	}

	/**
	 * Returns a skeleton representation suitable for sending to the model.
	 * Blocks within $window positions of $focusClientId (top-level distance) get full content;
	 * others get truncated content and `truncated: true`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function skeleton( ?string $focusClientId, int $window = 3 ): array {
		$focusIndex = null;
		if ( null !== $focusClientId ) {
			foreach ( $this->tree as $i => $node ) {
				if ( $node['clientId'] === $focusClientId ) {
					$focusIndex = $i;
					break;
				}
			}
		}

		$out = [];
		foreach ( $this->tree as $i => $node ) {
			$near  = ( null === $focusIndex ) || abs( $i - $focusIndex ) < $window;
			$out[] = $this->skeletonNode( $node, $near );
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $node
	 * @return array<string,mixed>
	 */
	private function skeletonNode( array $node, bool $full ): array {
		$content = (string) ( $node['attributes']['content'] ?? '' );
		$entry   = [
			'clientId'   => $node['clientId'],
			'name'       => $node['name'],
			'attributes' => $node['attributes'],
		];

		if ( ! $full && strlen( $content ) > 120 ) {
			$entry['attributes']            = $node['attributes'];
			$entry['attributes']['content'] = substr( $content, 0, 120 );
			$entry['truncated']             = true;
		}

		if ( ! empty( $node['innerBlocks'] ) ) {
			$entry['innerBlocks'] = array_map(
				fn( $child ) => $this->skeletonNode( $child, $full ),
				$node['innerBlocks']
			);
		}

		return $entry;
	}

	/**
	 * @param array<int,array<string,mixed>> $tree
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( array $tree ): array {
		return array_values(
			array_map(
				fn( $n ) => $this->normalizeNode( $n, $n['clientId'] ?? $this->mintClientId() ),
				$tree
			)
		);
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array<string,mixed>
	 */
	private function normalizeNode( array $node, string $clientId ): array {
		return [
			'clientId'    => $clientId,
			'name'        => (string) ( $node['name'] ?? '' ),
			'attributes'  => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
			'innerBlocks' => array_values(
				array_map(
					fn( $child ) => $this->normalizeNode( $child, $child['clientId'] ?? $this->mintClientId() ),
					is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : []
				)
			),
		];
	}

	private function mintClientId(): string {
		$this->counter++;
		return 'srv-' . bin2hex( random_bytes( 4 ) ) . '-' . $this->counter;
	}

	/**
	 * Locate a node by clientId without using PHP reference returns (which are error-prone).
	 *
	 * Returns an array with:
	 *   - 'node':       the matched node (copy)
	 *   - 'index':      its index within its parent siblings array
	 *   - 'parentPath': list of indices from $this->tree root down to the siblings array
	 *                   (empty list means the siblings array IS $this->tree)
	 *
	 * @param array<int,array<string,mixed>> $tree
	 * @param list<int>                      $pathSoFar
	 * @return array{node:array<string,mixed>, index:int, parentPath:list<int>}|null
	 */
	private function locateInTree( array $tree, string $clientId, array $pathSoFar = [] ): ?array {
		foreach ( $tree as $i => $node ) {
			if ( $node['clientId'] === $clientId ) {
				return [ 'node' => $node, 'index' => $i, 'parentPath' => $pathSoFar ];
			}
			if ( ! empty( $node['innerBlocks'] ) ) {
				$childPath   = array_merge( $pathSoFar, [ $i ] );
				$found       = $this->locateInTree( $node['innerBlocks'], $clientId, $childPath );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Navigate $this->tree via $parentPath to reach a siblings array, then apply array_splice.
	 *
	 * @param list<int>                      $parentPath
	 * @param array<int,array<string,mixed>> $replacement
	 */
	private function spliceIntoSiblings( array $parentPath, int $offset, int $length, array $replacement ): void {
		if ( empty( $parentPath ) ) {
			array_splice( $this->tree, $offset, $length, $replacement );
			$this->tree = array_values( $this->tree );
			return;
		}

		// Walk down to the parent node and mutate its innerBlocks.
		$this->mutateAtPath( $this->tree, $parentPath, $offset, $length, $replacement );
	}

	/**
	 * Recursively walk $parentPath, then splice at the final level's innerBlocks.
	 *
	 * @param array<int,array<string,mixed>> $tree       (by reference, mutated in place)
	 * @param list<int>                      $path
	 * @param array<int,array<string,mixed>> $replacement
	 */
	private function mutateAtPath( array &$tree, array $path, int $offset, int $length, array $replacement ): void {
		$head = array_shift( $path );
		if ( empty( $path ) ) {
			// $head is the index of the node whose innerBlocks we are splicing.
			array_splice( $tree[ $head ]['innerBlocks'], $offset, $length, $replacement );
			$tree[ $head ]['innerBlocks'] = array_values( $tree[ $head ]['innerBlocks'] );
		} else {
			$this->mutateAtPath( $tree[ $head ]['innerBlocks'], $path, $offset, $length, $replacement );
		}
	}

	/**
	 * Apply a callback to mutate a single node in place.
	 *
	 * @param list<int>                                  $parentPath
	 * @param callable(array<string,mixed>):array<string,mixed> $callback
	 */
	private function mutateNode( array $parentPath, int $index, callable $callback ): void {
		if ( empty( $parentPath ) ) {
			$this->tree[ $index ] = $callback( $this->tree[ $index ] );
			return;
		}
		$this->mutateNodeAtPath( $this->tree, $parentPath, $index, $callback );
	}

	/**
	 * @param array<int,array<string,mixed>>                     $tree (by reference)
	 * @param list<int>                                          $path
	 * @param callable(array<string,mixed>):array<string,mixed> $callback
	 */
	private function mutateNodeAtPath( array &$tree, array $path, int $index, callable $callback ): void {
		$head = array_shift( $path );
		if ( empty( $path ) ) {
			$tree[ $head ]['innerBlocks'][ $index ] = $callback( $tree[ $head ]['innerBlocks'][ $index ] );
		} else {
			$this->mutateNodeAtPath( $tree[ $head ]['innerBlocks'], $path, $index, $callback );
		}
	}
}
