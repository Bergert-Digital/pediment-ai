import { applyToolCallsToEditor, type EditorApi } from '../applyToolCallsCore';

type Node = {
	clientId: string;
	name: string;
	attributes: any;
	innerBlocks: Node[];
};

/**
 * A small in-memory model of the Gutenberg block store, faithful to the
 * selectors/dispatchers applyToolCalls relies on. Crucially, parent/root
 * addressing is honoured: getBlockOrder is per-parent and moveBlockToPosition
 * only finds a block within the `fromRoot` you pass — exactly like WP. This
 * reproduces the real failure when an operation targets a NESTED block while
 * the apply layer addresses only the root.
 * @param root
 */
function makeStore( root: Node[] ) {
	let counter = 0;

	const childrenOf = ( rootClientId: string ): Node[] | null => {
		if ( rootClientId === '' ) {
			return root;
		}
		let found: Node[] | null = null;
		const walk = ( nodes: Node[] ) => {
			for ( const n of nodes ) {
				if ( n.clientId === rootClientId ) {
					found = n.innerBlocks;
					return;
				}
				walk( n.innerBlocks );
			}
		};
		walk( root );
		return found;
	};

	const parentArrayOf = (
		clientId: string
	): { arr: Node[]; index: number } | null => {
		let hit: { arr: Node[]; index: number } | null = null;
		const walk = ( nodes: Node[] ) => {
			nodes.forEach( ( n, i ) => {
				if ( n.clientId === clientId ) {
					hit = { arr: nodes, index: i };
				} else {
					walk( n.innerBlocks );
				}
			} );
		};
		walk( root );
		return hit;
	};

	const api: EditorApi = {
		getBlockOrder: ( rootClientId = '' ) =>
			( childrenOf( rootClientId ) ?? [] ).map( ( n ) => n.clientId ),
		getBlockRootClientId: ( clientId ) => {
			const loc = parentArrayOf( clientId );
			if ( ! loc ) {
				return null;
			}
			return loc.arr === root ? '' : ownerOf( loc.arr );
		},
		createBlock: ( name, attributes, innerBlocks ) => ( {
			clientId: `new-${ ++counter }`,
			name,
			attributes,
			innerBlocks,
		} ),
		insertBlock: ( block, index, rootClientId ) => {
			const arr = childrenOf( rootClientId ?? '' );
			if ( arr ) {
				arr.splice( index, 0, block as Node );
			}
		},
		updateBlockAttributes: ( id, attrs ) => {
			const loc = parentArrayOf( id );
			if ( loc ) {
				loc.arr[ loc.index ].attributes = {
					...loc.arr[ loc.index ].attributes,
					...attrs,
				};
			}
		},
		removeBlock: ( id ) => {
			const loc = parentArrayOf( id );
			if ( loc ) {
				loc.arr.splice( loc.index, 1 );
			}
		},
		moveBlockToPosition: ( id, fromRoot, toRoot, index ) => {
			const fromArr = childrenOf( fromRoot );
			if ( ! fromArr ) {
				return;
			}
			const at = fromArr.findIndex( ( n ) => n.clientId === id );
			if ( at === -1 ) {
				return;
			} // not found in fromRoot → no-op, like WP
			const [ node ] = fromArr.splice( at, 1 );
			const toArr = childrenOf( toRoot );
			if ( toArr ) {
				toArr.splice( index, 0, node );
			}
		},
		normalize: () => {},
	};

	// Resolve a children-array back to the clientId of the node that owns it.
	function ownerOf( arr: Node[] ): string {
		let owner = '';
		const walk = ( nodes: Node[] ) => {
			for ( const n of nodes ) {
				if ( n.innerBlocks === arr ) {
					owner = n.clientId;
				} else {
					walk( n.innerBlocks );
				}
			}
		};
		walk( root );
		return owner;
	}

	return { api, root };
}

const group = ( clientId: string, innerBlocks: Node[] ): Node => ( {
	clientId,
	name: 'core/group',
	attributes: { className: 'starter-section' },
	innerBlocks,
} );

const leaf = ( clientId: string, name: string ): Node => ( {
	clientId,
	name,
	attributes: {},
	innerBlocks: [],
} );

describe( 'applyToolCallsToEditor — addressing nested targets', () => {
	it( 'inserts after a nested block into that block’s parent, not at the page top', () => {
		const { api, root } = makeStore( [
			group( 'sec1', [
				leaf( 'h1', 'core/heading' ),
				leaf( 'p1', 'core/paragraph' ),
			] ),
		] );

		applyToolCallsToEditor( api, [
			{
				tool: 'insert_block',
				input: {
					after_client_id: 'h1',
					position: 'after',
					block: {
						name: 'core/paragraph',
						attributes: { content: 'new' },
					},
				},
				output: { client_id: 'srv-1' },
			},
		] );

		// The page must still have exactly one top-level section (nothing landed on top).
		expect( root.map( ( n ) => n.clientId ) ).toEqual( [ 'sec1' ] );
		// The new block sits inside sec1, right after h1.
		expect( root[ 0 ].innerBlocks.map( ( n ) => n.name ) ).toEqual( [
			'core/heading',
			'core/paragraph', // inserted
			'core/paragraph', // original p1
		] );
		expect( root[ 0 ].innerBlocks[ 1 ].attributes.content ).toBe( 'new' );
	} );

	it( 'moves a nested block within its parent instead of silently doing nothing', () => {
		const { api, root } = makeStore( [
			group( 'sec1', [
				leaf( 'h1', 'core/heading' ),
				leaf( 'p1', 'core/paragraph' ),
				leaf( 'p2', 'core/paragraph' ),
			] ),
		] );

		applyToolCallsToEditor( api, [
			{
				tool: 'move_block',
				input: {
					client_id: 'p2',
					target_client_id: 'h1',
					position: 'before',
				},
			},
		] );

		expect( root[ 0 ].innerBlocks.map( ( n ) => n.clientId ) ).toEqual( [
			'p2',
			'h1',
			'p1',
		] );
	} );
} );
