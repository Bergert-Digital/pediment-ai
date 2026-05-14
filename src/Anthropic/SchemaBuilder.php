<?php
/**
 * Discovers the block schema at runtime and caches it in a transient.
 *
 * @package StarterAi
 */

declare(strict_types=1);

namespace StarterAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the runtime block schema by inspecting WP_Block_Type_Registry.
 */
final class SchemaBuilder {
	public const TRANSIENT_KEY  = 'starter_ai_schema';
	private const TRANSIENT_TTL = HOUR_IN_SECONDS;

	private const CORE_ALLOWLIST = [
		'core/paragraph' => [
			'description'       => 'A paragraph of body text.',
			'attributes'        => [ 'content' => [ 'type' => 'string' ] ],
			'allowsInnerBlocks' => false,
		],
		'core/heading' => [
			'description'       => 'A heading.',
			'attributes'        => [
				'content' => [ 'type' => 'string' ],
				'level'   => [ 'type' => 'number', 'default' => 2 ],
			],
			'allowsInnerBlocks' => false,
		],
		'core/list' => [
			'description'        => 'A bulleted or ordered list. Contains core/list-item children.',
			'attributes'         => [ 'ordered' => [ 'type' => 'boolean', 'default' => false ] ],
			'allowsInnerBlocks'  => true,
			'allowedChildBlocks' => [ 'core/list-item' ],
		],
		'core/list-item' => [
			'description'       => 'A single list item.',
			'attributes'        => [ 'content' => [ 'type' => 'string' ] ],
			'allowsInnerBlocks' => false,
		],
		'core/image' => [
			'description'       => 'A standalone image.',
			'attributes'        => [
				'id'  => [ 'type' => 'number' ],
				'url' => [ 'type' => 'string' ],
				'alt' => [ 'type' => 'string' ],
			],
			'allowsInnerBlocks' => false,
		],
		'core/separator' => [
			'description'       => 'A horizontal separator.',
			'attributes'        => [],
			'allowsInnerBlocks' => false,
		],
	];

	/**
	 * Build the schema, using the cached version if available.
	 *
	 * @param bool $forceFresh Skip the transient cache.
	 * @return array{blocks:array<string,array<string,mixed>>}
	 */
	public function build( bool $forceFresh = false ): array {
		if ( ! $forceFresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$blocks = self::CORE_ALLOWLIST;

		/**
		 * Filter the block namespaces that the AI plugin discovers.
		 *
		 * Evaluated only on cache misses. Call SchemaBuilder::invalidate() after
		 * registering this filter at runtime to force re-discovery.
		 *
		 * @param array<int,string> $namespaces Namespace prefixes (without trailing slash).
		 */
		$namespaces = (array) apply_filters( 'starter_ai_block_namespaces', array( 'starter', 'client' ) );
		$pattern    = '#^(' . implode( '|', array_map( 'preg_quote', $namespaces ) ) . ')/#';

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( $registry->get_all_registered() as $name => $type ) {
			if ( ! preg_match( $pattern, (string) $name ) ) {
				continue;
			}

			$description = isset( $type->description ) ? (string) $type->description : '';
			$attributes  = isset( $type->attributes ) && is_array( $type->attributes ) ? $type->attributes : [];

			if ( '' === $description ) {
				continue;
			}

			$parent       = isset( $type->parent ) && is_array( $type->parent ) ? $type->parent : [];
			$allows_inner = ! empty( $type->supports['__experimentalLayout'] )
				|| ! empty( $type->supports['inserter'] )
				|| $this->guessAllowsInnerBlocks( (string) $name );

			$blocks[ $name ] = [
				'description'       => $description,
				'attributes'        => $attributes,
				'allowsInnerBlocks' => (bool) $allows_inner,
			];

			if ( ! empty( $parent ) ) {
				$blocks[ $name ]['onlyAllowedAsChildOf'] = $parent;
			}
		}

		foreach ( $blocks as $name => $info ) {
			if ( empty( $info['onlyAllowedAsChildOf'] ) ) {
				continue;
			}
			foreach ( (array) $info['onlyAllowedAsChildOf'] as $parent ) {
				if ( isset( $blocks[ $parent ] ) ) {
					$blocks[ $parent ]['allowedChildBlocks'][] = $name;
					$blocks[ $parent ]['allowsInnerBlocks']    = true;
				}
			}
			unset( $blocks[ $name ]['onlyAllowedAsChildOf'] );
		}

		$schema = [ 'blocks' => $blocks ];
		set_transient( self::TRANSIENT_KEY, $schema, self::TRANSIENT_TTL );
		return $schema;
	}

	/**
	 * Clear the cached schema.
	 */
	public static function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Heuristic for blocks that allow inner blocks but don't declare it via supports.
	 */
	private function guessAllowsInnerBlocks( string $name ): bool {
		return in_array( $name, [ 'starter/faq', 'starter/prose' ], true );
	}
}
