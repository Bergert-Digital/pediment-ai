import { dispatch, select } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { normalizeSections } from './normalizeSections';
import { applyToolCallsToEditor, type ToolCall } from './applyToolCallsCore';

/**
 * Apply a list of tool calls from a completed turn to the canvas as a single
 * Gutenberg history entry. Server-emitted clientIds (prefixed "srv-") are
 * mapped to freshly-minted Gutenberg clientIds.
 *
 * The placement logic lives in applyToolCallsToEditor (pure, unit-tested); this
 * wrapper binds it to the live block-editor store.
 * @param calls
 */
export default function applyToolCalls( calls: ToolCall[] ): void {
	if ( ! calls?.length ) {
		return;
	}

	const blockEditor = dispatch( 'core/block-editor' ) as any;
	const blockSelect = select( 'core/block-editor' ) as any;

	applyToolCallsToEditor(
		{
			getBlockOrder: ( rootClientId = '' ) =>
				blockSelect.getBlockOrder( rootClientId ) as string[],
			getBlockRootClientId: ( clientId: string ) =>
				blockSelect.getBlockRootClientId( clientId ),
			createBlock: ( name, attributes, innerBlocks ) =>
				createBlock( name, attributes, innerBlocks ),
			insertBlock: ( block, index, rootClientId, updateSelection ) =>
				blockEditor.insertBlock(
					block,
					index,
					rootClientId,
					updateSelection
				),
			updateBlockAttributes: ( clientId, attrs ) =>
				blockEditor.updateBlockAttributes( clientId, attrs ),
			removeBlock: ( clientId ) => blockEditor.removeBlock( clientId ),
			moveBlockToPosition: ( clientId, fromRoot, toRoot, index ) =>
				blockEditor.moveBlockToPosition(
					clientId,
					fromRoot,
					toRoot,
					index
				),
			normalize: () =>
				normalizeSections(
					{
						getBlocks: () => blockSelect.getBlocks() as any[],
						replaceBlocks: ( ids: string[], blocks: any[] ) =>
							blockEditor.replaceBlocks( ids, blocks ),
					},
					createBlock
				),
		},
		calls
	);
}
