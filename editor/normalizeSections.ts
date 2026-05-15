export type BlockLike = { name: string; attributes?: { className?: string } };

export type SectionPlan =
  | { kind: 'keep'; index: number }
  | { kind: 'wrap'; indices: number[] };

const SECTION_CLASS = 'starter-section';

function isSectionGroup(b: BlockLike): boolean {
  return (
    b.name === 'core/group' &&
    typeof b.attributes?.className === 'string' &&
    b.attributes.className.split(/\s+/).includes(SECTION_CLASS)
  );
}

/**
 * Partition a flat top-level block list into a deterministic section plan.
 * Runs split on core/separator (separators are dropped). A segment that is
 * exactly one already-correct section group is kept as-is (idempotent).
 */
export function planSections(blocks: BlockLike[]): SectionPlan[] {
  const out: SectionPlan[] = [];
  let segment: number[] = [];

  const flush = () => {
    if (segment.length === 0) return;
    if (segment.every((i) => isSectionGroup(blocks[i]))) {
      segment.forEach((i) => out.push({ kind: 'keep', index: i }));
    } else {
      out.push({ kind: 'wrap', indices: segment.slice() });
    }
    segment = [];
  };

  blocks.forEach((blk, i) => {
    if (blk.name === 'core/separator') {
      flush();
    } else {
      segment.push(i);
    }
  });
  flush();
  return out;
}

type RootBlock = { clientId: string; name: string; attributes: any; innerBlocks: any[] };

export type NormalizeDeps = {
  getBlocks: () => RootBlock[];
  replaceBlocks: (clientIds: string[], blocks: any[]) => void;
};

export type CreateBlock = (name: string, attributes: any, innerBlocks: any[]) => any;

/**
 * Deterministically rewrite the editor root into section groups.
 * Idempotent: already-correct section groups are reused unchanged.
 */
export function normalizeSections(deps: NormalizeDeps, create: CreateBlock): void {
  const root = deps.getBlocks();
  if (root.length === 0) return;

  const plan = planSections(root);

  const next = plan.map((p) =>
    p.kind === 'keep'
      ? root[p.index]
      : create(
          'core/group',
          { tagName: 'section', className: SECTION_CLASS, layout: { type: 'default' } },
          p.indices.map((i) => root[i])
        )
  );

  deps.replaceBlocks(
    root.map((b) => b.clientId),
    next
  );
}
