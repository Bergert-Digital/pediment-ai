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
				'innerBlocks' => [ 'type' => 'array' ],
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

		return [
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
	}

	/**
	 * Apply a tool call to the virtual tree and return the synthetic tool_result payload.
	 *
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	public function apply( VirtualTree $tree, string $tool, array $input ): array {
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
