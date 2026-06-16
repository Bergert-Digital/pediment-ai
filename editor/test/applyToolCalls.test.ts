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

	it( 'moves a top-level section forward to the position the model intended (after target)', () => {
		// Page: hero + three content sections. The model asks to move section A
		// to *after* section B — the server VirtualTree resolves this to
		// [hero, B, A, C]. The editor must land on the same order.
		const { api, root } = makeStore( [
			group( 'hero', [ leaf( 'h0', 'core/heading' ) ] ),
			group( 'A', [ leaf( 'a0', 'core/heading' ) ] ),
			group( 'B', [ leaf( 'b0', 'core/heading' ) ] ),
			group( 'C', [ leaf( 'c0', 'core/heading' ) ] ),
		] );

		applyToolCallsToEditor( api, [
			{
				tool: 'move_block',
				input: {
					client_id: 'A',
					target_client_id: 'B',
					position: 'after',
				},
			},
		] );

		expect( root.map( ( n ) => n.clientId ) ).toEqual( [
			'hero',
			'B',
			'A',
			'C',
		] );
	} );

	it( 'moves a top-level section backward (up) to the position the model intended (before target)', () => {
		const { api, root } = makeStore( [
			group( 'hero', [ leaf( 'h0', 'core/heading' ) ] ),
			group( 'A', [ leaf( 'a0', 'core/heading' ) ] ),
			group( 'B', [ leaf( 'b0', 'core/heading' ) ] ),
			group( 'C', [ leaf( 'c0', 'core/heading' ) ] ),
		] );

		applyToolCallsToEditor( api, [
			{
				tool: 'move_block',
				input: {
					client_id: 'C',
					target_client_id: 'A',
					position: 'before',
				},
			},
		] );

		expect( root.map( ( n ) => n.clientId ) ).toEqual( [
			'hero',
			'C',
			'A',
			'B',
		] );
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

describe( 'applyToolCallsToEditor — legacy list repair', () => {
	it( 'converts a core/list with a legacy values string into list-item children', () => {
		const { api, root } = makeStore( [] );

		applyToolCallsToEditor( api, [
			{
				tool: 'insert_block',
				input: {
					position: 'end',
					after_client_id: null,
					block: {
						name: 'core/list',
						attributes: {
							ordered: false,
							values: '<li>First <strong>point</strong></li><li>Second point</li>',
						},
						innerBlocks: [],
					},
				},
				output: { client_id: 'srv-1' },
			},
		] );

		expect( root ).toHaveLength( 1 );
		const list = root[ 0 ];
		expect( list.name ).toBe( 'core/list' );
		expect( list.attributes.values ).toBeUndefined();
		expect( list.innerBlocks.map( ( n ) => n.name ) ).toEqual( [
			'core/list-item',
			'core/list-item',
		] );
		expect( list.innerBlocks[ 0 ].attributes.content ).toBe(
			'First <strong>point</strong>'
		);
		expect( list.innerBlocks[ 1 ].attributes.content ).toBe(
			'Second point'
		);
	} );

	it( 'leaves a properly-formed list (list-item innerBlocks) untouched', () => {
		const { api, root } = makeStore( [] );

		applyToolCallsToEditor( api, [
			{
				tool: 'insert_block',
				input: {
					position: 'end',
					after_client_id: null,
					block: {
						name: 'core/list',
						attributes: { ordered: false },
						innerBlocks: [
							{
								name: 'core/list-item',
								attributes: { content: 'Already good' },
							},
						],
					},
				},
				output: { client_id: 'srv-1' },
			},
		] );

		expect( root[ 0 ].innerBlocks ).toHaveLength( 1 );
		expect( root[ 0 ].innerBlocks[ 0 ].attributes.content ).toBe(
			'Already good'
		);
	} );
} );
