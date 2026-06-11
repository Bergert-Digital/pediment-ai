<?php
/**
 * Tool schema definitions and tool-call dispatcher for chat.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

use PedimentAi\BlockTree\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tools {
	/**
	 * @param array<string,array<string,mixed>> $blockSchema Map of blockName => spec.
	 */
	public function __construct(
		private readonly array $blockSchema,
		private readonly Validator $validator
	) {}

	/**
	 * Anthropic tool definitions sent in the Messages request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions(): array {
		$blockSchema = [
			'type'       => 'object',
			'properties' => [
				'name'        => [ 'type' => 'string', 'enum' => array_keys( $this->blockSchema ) ],
				'attributes'  => [ 'type' => 'object' ],
				'innerBlocks' => [
					'type'        => 'array',
					'description' => 'Nested child blocks for a container block, each {name, attributes, innerBlocks?}. Populate this when inserting a container so its children are created together — there is no way to add children to a container after it exists.',
				],
			],
			'required'   => [ 'name', 'attributes' ],
		];

		// Per-block attribute requirements expressed as JSON Schema if/then conditions,
		// so Anthropic's tool-input validator rejects insert_block calls that omit a
		// required attribute (e.g. pediment/section-head without `headline`).
		$conditionals = [];
		foreach ( $this->blockSchema as $blockName => $info ) {
			$requiredAttrs = isset( $info['requiredAttributes'] ) && is_array( $info['requiredAttributes'] )
				? $info['requiredAttributes']
				: [];
			if ( empty( $requiredAttrs ) ) {
				continue;
			}
			$conditionals[] = [
				'if'   => [ 'properties' => [ 'name' => [ 'const' => $blockName ] ] ],
				'then' => [ 'properties' => [ 'attributes' => [ 'type' => 'object', 'required' => $requiredAttrs ] ] ],
			];
		}
		if ( ! empty( $conditionals ) ) {
			$blockSchema['allOf'] = $conditionals;
		}

		$blockTools = [
			[
				'name'         => 'insert_block',
				'description'  => 'Insert a new block into the post. Use after_client_id+position to place it; use position=end+after_client_id=null to append.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'after_client_id' => [ 'type' => [ 'string', 'null' ] ],
						'position'        => [ 'type' => 'string', 'enum' => [ 'before', 'after', 'start', 'end' ] ],
						'block'           => $blockSchema,
					],
					'required'   => [ 'position', 'block' ],
				],
			],
			[
				'name'         => 'update_block',
				'description'  => 'Update attributes (and/or content) of an existing block. Pass only the keys you want to change.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'client_id' => [ 'type' => 'string' ],
						'attrs'     => [ 'type' => 'object' ],
						'content'   => [ 'type' => 'string' ],
					],
					'required'   => [ 'client_id' ],
				],
			],
			[
				'name'         => 'delete_block',
				'description'  => 'Delete a block by clientId.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [ 'client_id' => [ 'type' => 'string' ] ],
					'required'   => [ 'client_id' ],
				],
			],
			[
				'name'         => 'move_block',
				'description'  => 'Move a block before or after another block.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'client_id'        => [ 'type' => 'string' ],
						'target_client_id' => [ 'type' => 'string' ],
						'position'         => [ 'type' => 'string', 'enum' => [ 'before', 'after' ] ],
					],
					'required'   => [ 'client_id', 'target_client_id', 'position' ],
				],
			],
			[
				'name'         => 'read_block',
				'description'  => 'Read the full contents of a block whose content was truncated in the initial context.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [ 'client_id' => [ 'type' => 'string' ] ],
					'required'   => [ 'client_id' ],
				],
			],
		];

		// Anthropic-hosted server tools: let the model read the live web so it can
		// build a page from a reference URL or look one up by name. These execute
		// on Anthropic's side — no input_schema, no client-side dispatch. web_fetch
		// may only retrieve URLs already present in the conversation (or surfaced by
		// web_search), which is exactly the "base this page on <url>" flow.
		$webTools = [
			[
				'type'     => 'web_search_20260209',
				'name'     => 'web_search',
				'max_uses' => 5,
			],
			[
				'type'     => 'web_fetch_20260209',
				'name'     => 'web_fetch',
				'max_uses' => 5,
			],
		];

		/**
		 * Filter the Anthropic server-side web tools offered to the model.
		 *
		 * Return an empty array to switch off web access entirely, or add
		 * `allowed_domains` / `blocked_domains` to bound what web_fetch may retrieve.
		 * web_fetch can pull arbitrary user-supplied URLs into context, so restrict
		 * it when the editor handles untrusted input alongside sensitive data.
		 *
		 * @param array<int,array<string,mixed>> $webTools Default web tool definitions.
		 */
		$webTools = (array) apply_filters( 'pediment_ai_web_tools', $webTools );

		return array_merge( $blockTools, $webTools );
	}

	/**
	 * JSON escape bodies the model sometimes emits with the leading backslash dropped —
	 * e.g. it transcribes "&" from a fetched page as literal "u0026". Each maps the
	 * orphaned body back to the character it was meant to be. Limited to the HTML-significant
	 * set produced by JSON_HEX_* so ordinary prose ("u1234", "Ubuntu") is never touched.
	 */
	private const ORPHAN_ESCAPES = [
		'u0026' => '&',
		'u003c' => '<',
		'u003e' => '>',
		'u0022' => '"',
		'u0027' => "'",
	];

	/**
	 * Recursively repair orphaned JSON unicode-escape bodies in every string the model
	 * supplied (block content and attribute values, nested children included), before the
	 * text lands in the tree. clientIds and positions are hex/enum and never match.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function repairOrphanEscapes( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return preg_replace_callback(
				'/u00(?:26|3[ce]|22|27)/i',
				static fn( array $m ): string => self::ORPHAN_ESCAPES[ strtolower( $m[0] ) ],
				$value
			);
		}
		if ( is_array( $value ) ) {
			return array_map( [ $this, 'repairOrphanEscapes' ], $value );
		}
		return $value;
	}

	/**
	 * Apply a tool call to the virtual tree and return the synthetic tool_result payload.
	 *
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	public function apply( VirtualTree $tree, string $tool, array $input ): array {
		$input = $this->repairOrphanEscapes( $input );
		switch ( $tool ) {
			case 'insert_block':
				return $this->applyInsert( $tree, $input );
			case 'update_block':
				return $this->applyUpdate( $tree, $input );
			case 'delete_block':
				return $this->applyDelete( $tree, $input );
			case 'move_block':
				return $this->applyMove( $tree, $input );
			case 'read_block':
				return $this->applyRead( $tree, $input );
		}
		return [ 'content' => "Unknown tool: {$tool}", 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyInsert( VirtualTree $tree, array $input ): array {
		$block = is_array( $input['block'] ?? null ) ? $input['block'] : [];
		$errors = $this->validator->validateNode( [
			'name'        => (string) ( $block['name'] ?? '' ),
			'attributes'  => is_array( $block['attributes']  ?? null ) ? $block['attributes']  : [],
			'innerBlocks' => is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [],
		] );
		if ( ! empty( $errors ) ) {
			return [ 'content' => 'Validation failed: ' . implode( '; ', $errors ), 'is_error' => true ];
		}
		$cid = $tree->insert(
			isset( $input['after_client_id'] ) && is_string( $input['after_client_id'] ) ? $input['after_client_id'] : null,
			(string) ( $input['position'] ?? 'end' ),
			$block
		);
		return [ 'content' => [ 'ok' => true, 'client_id' => $cid ] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyUpdate( VirtualTree $tree, array $input ): array {
		$cid = (string) ( $input['client_id'] ?? '' );
		if ( '' === $cid || null === $tree->find( $cid ) ) {
			return [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
		}
		$attrs   = is_array( $input['attrs'] ?? null ) ? $input['attrs'] : null;
		$content = isset( $input['content'] ) ? (string) $input['content'] : null;
		// Validate the merged node.
		$node      = $tree->find( $cid );
		$mergedAtt = is_array( $attrs ) ? array_merge( $node['attributes'], $attrs ) : $node['attributes'];
		if ( null !== $content ) {
			$mergedAtt['content'] = $content;
		}
		$errors = $this->validator->validateNode( [
			'name'        => $node['name'],
			'attributes'  => $mergedAtt,
			'innerBlocks' => $node['innerBlocks'],
		] );
		if ( ! empty( $errors ) ) {
			return [ 'content' => 'Validation failed: ' . implode( '; ', $errors ), 'is_error' => true ];
		}
		$tree->update( $cid, $attrs, $content );
		return [ 'content' => [ 'ok' => true ] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyDelete( VirtualTree $tree, array $input ): array {
		$cid = (string) ( $input['client_id'] ?? '' );
		if ( '' === $cid ) {
			return [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
		}
		return $tree->delete( $cid )
			? [ 'content' => [ 'ok' => true ] ]
			: [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyMove( VirtualTree $tree, array $input ): array {
		$cid    = (string) ( $input['client_id']        ?? '' );
		$target = (string) ( $input['target_client_id'] ?? '' );
		if ( '' === $cid || '' === $target ) {
			return [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
		}
		$ok = $tree->move( $cid, $target, (string) ( $input['position'] ?? 'after' ) );
		return $ok
			? [ 'content' => [ 'ok' => true ] ]
			: [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyRead( VirtualTree $tree, array $input ): array {
		$cid  = (string) ( $input['client_id'] ?? '' );
		$node = $tree->find( $cid );
		return null === $node
			? [ 'content' => "Block not found: {$cid}", 'is_error' => true ]
			: [ 'content' => [ 'name' => $node['name'], 'attributes' => $node['attributes'], 'innerBlocks' => $node['innerBlocks'] ] ];
	}
}
