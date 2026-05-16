import {
	planSections,
	normalizeSections,
	type BlockLike,
} from '../normalizeSections';

const b = ( name: string, className?: string ): BlockLike => ( {
	name,
	attributes: className ? { className } : {},
} );

describe( 'planSections', () => {
	it( 'wraps a separator-delimited run into one section, separators dropped', () => {
		const blocks = [
			b( 'starter/hero' ),
			b( 'core/separator' ),
			b( 'core/heading' ),
			b( 'core/paragraph' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'wrap', indices: [ 0 ] },
			{ kind: 'wrap', indices: [ 2, 3 ] },
		] );
	} );

	it( 'keeps an existing starter-section group untouched (idempotent)', () => {
		const blocks = [
			b( 'core/group', 'starter-section' ),
			b( 'core/separator' ),
			b( 'starter/faq' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'keep', index: 0 },
			{ kind: 'wrap', indices: [ 2 ] },
		] );
	} );

	it( 'no separators, no groups → single section', () => {
		expect(
			planSections( [ b( 'core/heading' ), b( 'core/paragraph' ) ] )
		).toEqual( [ { kind: 'wrap', indices: [ 0, 1 ] } ] );
	} );

	it( 'all already sections → unchanged', () => {
		const blocks = [
			b( 'core/group', 'starter-section' ),
			b( 'core/group', 'x starter-section y' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'keep', index: 0 },
			{ kind: 'keep', index: 1 },
		] );
	} );

	it( 'section groups are self-delimiting boundaries: hero + adjacent groups, no separators (repro)', () => {
		// Model followed the prompt: each section wrapped in a group, no separators.
		const blocks = [
			b( 'starter/hero' ),
			b( 'core/group', 'starter-section' ),
			b( 'core/group', 'starter-section' ),
			b( 'core/group', 'starter-section' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'wrap', indices: [ 0 ] },
			{ kind: 'keep', index: 1 },
			{ kind: 'keep', index: 2 },
			{ kind: 'keep', index: 3 },
		] );
	} );

	it( 'a section group ends a preceding ungrouped run without a separator', () => {
		const blocks = [
			b( 'core/heading' ),
			b( 'core/paragraph' ),
			b( 'core/group', 'starter-section' ),
			b( 'starter/cta' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'wrap', indices: [ 0, 1 ] },
			{ kind: 'keep', index: 2 },
			{ kind: 'wrap', indices: [ 3 ] },
		] );
	} );

	it( 'ignores empty segments from leading/consecutive/trailing separators', () => {
		const blocks = [
			b( 'core/separator' ),
			b( 'starter/hero' ),
			b( 'core/separator' ),
			b( 'core/separator' ),
			b( 'starter/cta' ),
			b( 'core/separator' ),
		];
		expect( planSections( blocks ) ).toEqual( [
			{ kind: 'wrap', indices: [ 1 ] },
			{ kind: 'wrap', indices: [ 4 ] },
		] );
	} );
} );

describe( 'normalizeSections applier', () => {
	it( 'replaces root with section groups and drops separators', () => {
		const root = [
			{
				clientId: 'h',
				name: 'starter/hero',
				attributes: {},
				innerBlocks: [],
			},
			{
				clientId: 's',
				name: 'core/separator',
				attributes: {},
				innerBlocks: [],
			},
			{
				clientId: 'g',
				name: 'core/heading',
				attributes: {},
				innerBlocks: [],
			},
			{
				clientId: 'p',
				name: 'core/paragraph',
				attributes: {},
				innerBlocks: [],
			},
		];
		const replaceBlocks = jest.fn();
		const created: any[] = [];
		normalizeSections(
			{ getBlocks: () => root, replaceBlocks },
			( name, attrs, inner ) => {
				const blk = {
					name,
					attributes: attrs,
					innerBlocks: inner,
					clientId: 'new-' + created.length,
				};
				created.push( blk );
				return blk;
			}
		);
		expect( replaceBlocks ).toHaveBeenCalledTimes( 1 );
		const [ ids, blocks ] = replaceBlocks.mock.calls[ 0 ];
		expect( ids ).toEqual( [ 'h', 's', 'g', 'p' ] );
		expect( blocks ).toHaveLength( 2 );
		expect( blocks[ 0 ] ).toMatchObject( {
			name: 'core/group',
			attributes: {
				tagName: 'section',
				align: 'full',
				className: 'starter-section',
				layout: { type: 'default' },
			},
		} );
		expect( blocks[ 0 ].innerBlocks.map( ( x: any ) => x.name ) ).toEqual( [
			'starter/hero',
		] );
		expect( blocks[ 1 ].innerBlocks.map( ( x: any ) => x.name ) ).toEqual( [
			'core/heading',
			'core/paragraph',
		] );
	} );

	it( 'enforces full-width section attrs on kept model groups (align:full guarantee)', () => {
		// Model emits a group with starter-section but NO align (schema can't
		// express it); a constrained post-content would clamp it to 720px.
		const root = [
			{
				clientId: 'a',
				name: 'core/group',
				attributes: { className: 'starter-section' },
				innerBlocks: [],
			},
			{
				clientId: 'b',
				name: 'core/group',
				attributes: { className: 'starter-section' },
				innerBlocks: [],
			},
		];
		const replaceBlocks = jest.fn();
		normalizeSections(
			{ getBlocks: () => root, replaceBlocks },
			( n, a, i ) => ( { name: n, attributes: a, innerBlocks: i } )
		);
		const [ , blocks ] = replaceBlocks.mock.calls[ 0 ];
		expect( blocks ).toHaveLength( root.length );
		blocks.forEach( ( blk: any ) => {
			expect( blk.name ).toEqual( 'core/group' );
			expect( blk.attributes ).toMatchObject( {
				tagName: 'section',
				align: 'full',
				layout: { type: 'default' },
			} );
			expect( blk.attributes.className.split( /\s+/ ) ).toContain(
				'starter-section'
			);
		} );
	} );

	it( 'preserves extra model-group attributes while enforcing the section shape', () => {
		const root = [
			{
				clientId: 'a',
				name: 'core/group',
				attributes: {
					className: 'brand starter-section',
					backgroundColor: 'surface-elevated',
					style: { spacing: { padding: { top: '2rem' } } },
				},
				innerBlocks: [
					{
						clientId: 'x',
						name: 'core/paragraph',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
		];
		const replaceBlocks = jest.fn();
		normalizeSections(
			{ getBlocks: () => root, replaceBlocks },
			( n: string, a: any, i: any ) => ( {
				name: n,
				attributes: a,
				innerBlocks: i,
			} )
		);
		const [ , blocks ] = replaceBlocks.mock.calls[ 0 ];
		expect( blocks[ 0 ].attributes ).toMatchObject( {
			align: 'full',
			tagName: 'section',
			backgroundColor: 'surface-elevated',
			style: { spacing: { padding: { top: '2rem' } } },
			layout: { type: 'default' },
		} );
		expect( blocks[ 0 ].attributes.className.split( /\s+/ ) ).toEqual(
			expect.arrayContaining( [ 'brand', 'starter-section' ] )
		);
		// children preserved
		expect( blocks[ 0 ].innerBlocks.map( ( x: any ) => x.name ) ).toEqual( [
			'core/paragraph',
		] );
	} );

	it( 'clones wrapped children so no new block reuses an original (removed) clientId', () => {
		const root = [
			{
				clientId: 'h',
				name: 'starter/hero',
				attributes: {},
				innerBlocks: [],
			},
			{
				clientId: 's',
				name: 'core/separator',
				attributes: {},
				innerBlocks: [],
			},
			{
				clientId: 'g',
				name: 'core/heading',
				attributes: {},
				innerBlocks: [],
			},
		];
		const replaceBlocks = jest.fn();
		let n = 0;
		normalizeSections(
			{ getBlocks: () => root, replaceBlocks },
			( name, attrs, inner ) => ( {
				name,
				attributes: attrs,
				innerBlocks: inner,
				clientId: 'fresh-' + n++,
			} )
		);
		const [ removedIds, next ] = replaceBlocks.mock.calls[ 0 ];
		const collect = ( b: any ): string[] => [
			b.clientId,
			...( b.innerBlocks ?? [] ).flatMap( collect ),
		];
		const newIds = next.flatMap( collect );
		// No clientId in the new tree may collide with a removed top-level id.
		for ( const id of newIds ) expect( removedIds ).not.toContain( id );
	} );
} );
