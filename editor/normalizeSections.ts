export type BlockLike = { name: string; attributes?: { className?: string } };

export type SectionPlan =
	| { kind: 'keep'; index: number }
	| { kind: 'wrap'; indices: number[] };

const SECTION_CLASS = 'starter-section';

function isSectionGroup( b: BlockLike ): boolean {
	return (
		b.name === 'core/group' &&
		typeof b.attributes?.className === 'string' &&
		b.attributes.className.split( /\s+/ ).includes( SECTION_CLASS )
	);
}

/**
 * Partition a flat top-level block list into a deterministic section plan.
 *
 * Two kinds of self-delimiting boundary, both consumed/standalone:
 *  - `core/separator`: a consumed boundary (dropped); flushes the pending run.
 *  - an already-correct section group (`core/group.starter-section`): its own
 *    complete section — flushes the pending run, then is kept as-is.
 *
 * Any maximal run of other blocks between boundaries becomes one wrapped
 * section. This makes the prompt's preferred path (model wraps each section in
 * a group and emits NO separators) idempotent: adjacent correct groups are
 * each kept, never collapsed into a single parent wrap.
 */
export function planSections( blocks: BlockLike[] ): SectionPlan[] {
	const out: SectionPlan[] = [];
	let segment: number[] = [];

	// The segment only ever holds non-group, non-separator blocks, so a flush
	// is always a wrap.
	const flush = () => {
		if ( segment.length === 0 ) return;
		out.push( { kind: 'wrap', indices: segment.slice() } );
		segment = [];
	};

	blocks.forEach( ( blk, i ) => {
		if ( blk.name === 'core/separator' ) {
			flush();
		} else if ( isSectionGroup( blk ) ) {
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
 * Canonical section-group attributes. `align: 'full'` is the guarantee that
 * the section escapes the constrained post-content content-width cap (a flow
 * `core/group` alone stays clamped to `contentSize`); the theme's
 * `section.starter-section > :where(:not(.alignfull):not(.alignwide))` rule
 * then re-constrains inner content. `base` (a model group's attributes) is
 * preserved so backgrounds/spacing survive, but the section-shape keys are
 * always enforced — the model schema can't express `align` and per spec the
 * normalizer, not the LLM, is the guarantee.
 */
function sectionAttributes( base?: any ): any {
	const tokens =
		typeof base?.className === 'string'
			? base.className.split( /\s+/ ).filter( Boolean )
			: [];
	if ( ! tokens.includes( SECTION_CLASS ) ) tokens.push( SECTION_CLASS );
	return {
		...( base ?? {} ),
		tagName: 'section',
		align: 'full',
		className: tokens.join( ' ' ),
		layout: { type: 'default' },
	};
}

/**
 * Deterministically rewrite the editor root into full-width section groups.
 * Idempotent in structure; kept model groups have their section-shape attrs
 * enforced (notably `align: 'full'`) while extra attrs and children survive.
 */
export function normalizeSections(
	deps: NormalizeDeps,
	create: CreateBlock
): void {
	const root = deps.getBlocks();
	if ( root.length === 0 ) return;

	const plan = planSections( root );

	const next = plan.map( ( p ) => {
		if ( p.kind === 'keep' ) {
			const g = root[ p.index ];
			return create(
				'core/group',
				sectionAttributes( g.attributes ),
				( g.innerBlocks ?? [] ).map( ( c ) => cloneBlock( c, create ) )
			);
		}
		return create(
			'core/group',
			sectionAttributes(),
			p.indices.map( ( i ) => cloneBlock( root[ i ], create ) )
		);
	} );

	deps.replaceBlocks(
		root.map( ( b ) => b.clientId ),
		next
	);
}
