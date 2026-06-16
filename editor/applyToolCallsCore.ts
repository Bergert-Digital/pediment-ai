export type ToolCall = {
	tool: string;
	input: any;
	output?: any;
	is_error?: boolean;
};

/**
 * The slice of the Gutenberg block-editor store applyToolCalls depends on.
 * Injected so the apply logic can be unit-tested against a plain in-memory
 * tree, and so it carries no `@wordpress/*` imports of its own.
 */
export type EditorApi = {
	/** Child clientIds of `rootClientId` ('' = the canvas root). */
	getBlockOrder: ( rootClientId?: string ) => string[];
	/** clientId of a block's parent; '' if it is a root block, null if unknown. */
	getBlockRootClientId: ( clientId: string ) => string | null;
	createBlock: ( name: string, attributes: any, innerBlocks: any[] ) => any;
	insertBlock: (
		block: any,
		index: number,
		rootClientId: string | undefined,
		updateSelection: boolean
	) => void;
	updateBlockAttributes: ( clientId: string, attrs: any ) => void;
	removeBlock: ( clientId: string ) => void;
	moveBlockToPosition: (
		clientId: string,
		fromRootClientId: string,
		toRootClientId: string,
		index: number
	) => void;
	/** Run after all calls are applied (section normalization). */
	normalize: () => void;
};

/**
 * Turn a legacy list `values` HTML string ("<li>a</li><li>b</li>") into core/list-item
 * specs. Mirrors the server-side Tools::listItemsFromLegacyHtml — the client replays the
 * raw tool input, so the same repair has to run here for the editor to render real items.
 * @param html
 */
function listItemsFromLegacyHtml( html: string ): any[] {
	const trimmed = ( html ?? '' ).trim();
	if ( ! trimmed ) {
		return [];
	}
	const items: any[] = [];
	const re = /<li\b[^>]*>([\s\S]*?)<\/li>/gi;
	let m: RegExpExecArray | null;
	while ( ( m = re.exec( trimmed ) ) !== null ) {
		const content = m[ 1 ].trim();
		if ( content ) {
			items.push( {
				name: 'core/list-item',
				attributes: { content },
			} );
		}
	}
	if ( items.length === 0 ) {
		const text = trimmed.replace( /<[^>]*>/g, '' ).trim();
		if ( text ) {
			items.push( { name: 'core/list-item', attributes: { content: text } } );
		}
	}
	return items;
}

/**
 * A core/list whose items arrived as a legacy `values`/`content` HTML string with no
 * innerBlocks renders empty. Rewrite it to the modern core/list-item innerBlocks shape.
 * @param spec
 */
function normalizeLegacyList( spec: any ): any {
	if (
		spec?.name !== 'core/list' ||
		( spec.innerBlocks && spec.innerBlocks.length )
	) {
		return spec;
	}
	const attrs = { ...( spec.attributes ?? {} ) };
	let legacy = '';
	for ( const key of [ 'values', 'content' ] ) {
		if ( typeof attrs[ key ] === 'string' && attrs[ key ].trim() ) {
			legacy = attrs[ key ];
			delete attrs[ key ];
			break;
		}
	}
	const items = listItemsFromLegacyHtml( legacy );
	if ( ! items.length ) {
		return spec;
	}
	return { ...spec, attributes: attrs, innerBlocks: items };
}

function createBlockFromSpec( api: EditorApi, spec: any ): any {
	const normalized = normalizeLegacyList( spec );
	const inner = ( normalized.innerBlocks ?? [] ).map( ( s: any ) =>
		createBlockFromSpec( api, s )
	);
	return api.createBlock(
		normalized.name,
		normalized.attributes ?? {},
		inner
	);
}

/**
 * Apply a turn's tool calls to the editor. Server-emitted clientIds (prefixed
 * "srv-") are mapped to freshly-minted Gutenberg clientIds.
 * @param api
 * @param calls
 */
export function applyToolCallsToEditor(
	api: EditorApi,
	calls: ToolCall[]
): void {
	if ( ! calls?.length ) {
		return;
	}

	const idMap: Record< string, string > = {};
	const resolve = ( id?: string | null ): string | null =>
		id === null || id === undefined ? null : idMap[ id ] ?? id;

	for ( const c of calls ) {
		if ( c.is_error ) {
			continue;
		}
		switch ( c.tool ) {
			case 'insert_block': {
				const block = createBlockFromSpec( api, c.input.block );
				const target = resolve( c.input.after_client_id );
				if ( c.input.position === 'start' ) {
					api.insertBlock( block, 0, undefined, false );
				} else if ( c.input.position === 'end' || ! target ) {
					api.insertBlock(
						block,
						api.getBlockOrder().length,
						undefined,
						false
					);
				} else {
					// Resolve the target's real parent/index so a NESTED target
					// places the block inside that parent (mirroring the server
					// VirtualTree) instead of collapsing to root index 0 (the
					// "lands on top" bug). Unknown target → append at root end,
					// matching the server's fallback.
					const root = api.getBlockRootClientId( target ) ?? '';
					const siblings = api.getBlockOrder( root );
					const at = siblings.indexOf( target );
					if ( at === -1 ) {
						api.insertBlock(
							block,
							api.getBlockOrder().length,
							undefined,
							false
						);
					} else {
						const index =
							at + ( c.input.position === 'after' ? 1 : 0 );
						api.insertBlock(
							block,
							index,
							root || undefined,
							false
						);
					}
				}
				if ( c.output && c.output.client_id ) {
					idMap[ c.output.client_id ] = block.clientId;
				}
				break;
			}
			case 'update_block': {
				const id = resolve( c.input.client_id );
				if ( ! id ) {
					break;
				}
				const attrs = { ...( c.input.attrs ?? {} ) };
				if ( typeof c.input.content === 'string' ) {
					attrs.content = c.input.content;
				}
				api.updateBlockAttributes( id, attrs );
				break;
			}
			case 'delete_block': {
				const id = resolve( c.input.client_id );
				if ( id ) {
					api.removeBlock( id );
				}
				break;
			}
			case 'move_block': {
				const id = resolve( c.input.client_id );
				const target = resolve( c.input.target_client_id );
				if ( ! id || ! target ) {
					break;
				}
				// Move relative to the target's actual parent. The source must
				// be addressed within ITS own parent too, otherwise WP cannot
				// locate a nested block from the root and the move silently
				// no-ops ("can't move sections" bug).
				const fromRoot = api.getBlockRootClientId( id ) ?? '';
				const toRoot = api.getBlockRootClientId( target ) ?? '';
				const siblings = api.getBlockOrder( toRoot );
				const targetIndex = siblings.indexOf( target );
				if ( targetIndex === -1 ) {
					break;
				}
				let newIndex =
					c.input.position === 'before'
						? targetIndex
						: targetIndex + 1;
				// moveBlockToPosition removes the block before inserting, so
				// within ONE parent the destination index is relative to the
				// list AFTER removal. When the block currently sits before its
				// target, that removal shifts every later sibling (the target
				// included) down one — without this correction a forward move
				// overshoots by one and the section lands past where the model
				// intended (the "lands in the wrong order" bug). This mirrors
				// the server VirtualTree, which removes first, then re-locates
				// the target.
				if ( fromRoot === toRoot ) {
					const fromIndex = siblings.indexOf( id );
					if ( fromIndex !== -1 && fromIndex < newIndex ) {
						newIndex -= 1;
					}
				}
				api.moveBlockToPosition( id, fromRoot, toRoot, newIndex );
				break;
			}
		}
	}

	api.normalize();
}
