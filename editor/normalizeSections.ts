export type BlockLike = { name: string; attributes?: { className?: string } };

export type SectionPlan =
	| { kind: 'keep'; index: number }
	| { kind: 'wrap'; indices: number[] };

// The theme's section unit is a top-level full-width `core/group` carrying the
// `starter-band` class (plus an `is-style-band-*` background style). Every band
// is a top-level sibling — the canonical landing pattern is a FLAT list of them.
const BAND_CLASS = 'starter-band';
const DEFAULT_BAND_STYLE = 'is-style-band-surface';

// Legacy: an earlier composer wrapped sections in `starter-section` groups (flow
// layout + a CSS child-constraint rule). The theme never authored these — they
// only ever came from our own normalizer. We now treat them as something to
// unwrap/convert so a page converges on the theme's `starter-band` shape.
const SECTION_CLASS = 'starter-section';

// Classes the block editor derives from other attributes at serialize time
// (align, layout support, the block's own base class). They must not be baked
// into the stored `className` attribute or they accumulate across normalize
// passes; strip them so band attrs stay idempotent.
const AUTO_CLASS =
	/^(?:wp-block-group|alignfull|alignwide|is-layout-[\w-]*|wp-block-group-is-layout-[\w-]*)$/;

function classTokens( b: BlockLike ): string[] {
	return typeof b.attributes?.className === 'string'
		? b.attributes.className.split( /\s+/ ).filter( Boolean )
		: [];
}

function isBand( b: BlockLike ): boolean {
	return b.name === 'core/group' && classTokens( b ).includes( BAND_CLASS );
}

/**
 * A legacy `starter-section` wrapper — a `core/group.starter-section` that is
 * NOT itself a band. These get unwrapped (their children lifted to the top
 * level) so bands the old normalizer swallowed resurface as top-level sections.
 */
function isLegacyWrapper( b: BlockLike ): boolean {
	return (
		b.name === 'core/group' &&
		classTokens( b ).includes( SECTION_CLASS ) &&
		! classTokens( b ).includes( BAND_CLASS )
	);
}

/**
 * Partition a flat top-level block list into a deterministic section plan.
 *
 * Two kinds of self-delimiting boundary:
 *  - `core/separator`: a consumed boundary (dropped); flushes the pending run.
 *  - an already-correct band (`core/group.starter-band`): its own complete
 *    section — flushes the pending run, then is kept as-is.
 *
 * Any maximal run of other blocks between boundaries becomes one wrapped band.
 * Adjacent correct bands are each kept, never collapsed into one parent wrap,
 * which makes the canonical flat-band page idempotent.
 */
export function planSections( blocks: BlockLike[] ): SectionPlan[] {
	const out: SectionPlan[] = [];
	let segment: number[] = [];

	// The segment only ever holds non-band, non-separator blocks, so a flush is
	// always a wrap.
	const flush = () => {
		if ( segment.length === 0 ) return;
		out.push( { kind: 'wrap', indices: segment.slice() } );
		segment = [];
	};

	blocks.forEach( ( blk, i ) => {
		if ( blk.name === 'core/separator' ) {
			flush();
		} else if ( isBand( blk ) ) {
			flush();
			out.push( { kind: 'keep', index: i } );
		} else {
			segment.push( i );
		}
	} );
	flush();
	return out;
}

type RootBlock = {
	clientId: string;
	name: string;
	attributes: any;
	innerBlocks: any[];
};

export type NormalizeDeps = {
	getBlocks: () => RootBlock[];
	replaceBlocks: ( clientIds: string[], blocks: any[] ) => void;
};

export type CreateBlock = (
	name: string,
	attributes: any,
	innerBlocks: any[]
) => any;

/**
 * Recursively rebuild a block via `create` so every node (and child) gets a
 * FRESH clientId. Reusing live block objects keeps their original clientIds,
 * which are also in replaceBlocks' removal list — producing a cyclic
 * parent/order map and an infinite-recursion editor crash.
 */
function cloneBlock( b: RootBlock, create: CreateBlock ): any {
	return create(
		b.name,
		{ ...b.attributes },
		( b.innerBlocks ?? [] ).map( ( c ) => cloneBlock( c, create ) )
	);
}

/**
 * Lift the children of any legacy `starter-section` wrapper up to the top
 * level, recursively. A page that an older normalizer collapsed into one
 * `starter-section` wrapping many bands (the "everything nested in one group,
 * new sections land on top" bug) is healed back into a flat band list. Bands
 * themselves are never descended into — their children are section content.
 */
function flattenLegacyWrappers( blocks: RootBlock[] ): RootBlock[] {
	const out: RootBlock[] = [];
	for ( const b of blocks ) {
		if ( isLegacyWrapper( b ) ) {
			out.push(
				...flattenLegacyWrappers( ( b.innerBlocks ?? [] ) as RootBlock[] )
			);
		} else {
			out.push( b );
		}
	}
	return out;
}

/**
 * Canonical band attributes, matching the theme's `starter-band` section unit
 * (see the `pediment-landing` pattern and `inc/seed.php`): a full-width group
 * using the theme's constrained layout, an `is-style-band-*` background style,
 * and zero block margin. `base` (an existing group's attributes) is preserved
 * so a chosen band style, custom classes, and spacing survive, but the
 * band-shape keys are always enforced. Auto-derived classes and the legacy
 * `starter-section`/`tagName:section` shape are stripped.
 */
function bandAttributes( base?: any ): any {
	const rest = { ...( base ?? {} ) };
	delete rest.tagName; // bands are <div>, not <section>
	delete rest.className;
	delete rest.layout;
	delete rest.style;

	const tokens = ( typeof base?.className === 'string'
		? base.className.split( /\s+/ ).filter( Boolean )
		: []
	).filter( ( t: string ) => t !== SECTION_CLASS && ! AUTO_CLASS.test( t ) );

	if ( ! tokens.includes( BAND_CLASS ) ) tokens.unshift( BAND_CLASS );
	if ( ! tokens.some( ( t: string ) => t.startsWith( 'is-style-band-' ) ) ) {
		tokens.push( DEFAULT_BAND_STYLE );
	}

	const baseStyle =
		base && typeof base.style === 'object' && base.style ? base.style : {};
	const baseSpacing =
		typeof baseStyle.spacing === 'object' && baseStyle.spacing
			? baseStyle.spacing
			: {};

	return {
		...rest,
		align: 'full',
		className: tokens.join( ' ' ),
		style: {
			...baseStyle,
			spacing: { ...baseSpacing, margin: { top: '0', bottom: '0' } },
		},
		layout: { type: 'constrained' },
	};
}

/**
 * Deterministically rewrite the editor root into a flat list of full-width
 * `starter-band` section groups. Legacy `starter-section` wrappers are
 * unwrapped first (healing nested pages), then every top-level run is either
 * kept (an existing band, band-shape attrs enforced) or wrapped into a new
 * band. Idempotent in structure.
 */
export function normalizeSections(
	deps: NormalizeDeps,
	create: CreateBlock
): void {
	const root = deps.getBlocks();
	if ( root.length === 0 ) return;

	const flat = flattenLegacyWrappers( root );
	const plan = planSections( flat );

	const next = plan.map( ( p ) => {
		if ( p.kind === 'keep' ) {
			const g = flat[ p.index ];
			return create(
				'core/group',
				bandAttributes( g.attributes ),
				( g.innerBlocks ?? [] ).map( ( c ) => cloneBlock( c, create ) )
			);
		}
		return create(
			'core/group',
			bandAttributes(),
			p.indices.map( ( i ) => cloneBlock( flat[ i ], create ) )
		);
	} );

	deps.replaceBlocks(
		root.map( ( b ) => b.clientId ),
		next
	);
}
