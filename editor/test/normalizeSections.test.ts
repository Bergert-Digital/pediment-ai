import { planSections, type BlockLike } from '../normalizeSections';

const b = (name: string, className?: string): BlockLike => ({
  name,
  attributes: className ? { className } : {},
});

describe('planSections', () => {
  it('wraps a separator-delimited run into one section, separators dropped', () => {
    const blocks = [b('starter/hero'), b('core/separator'), b('core/heading'), b('core/paragraph')];
    expect(planSections(blocks)).toEqual([
      { kind: 'wrap', indices: [0] },
      { kind: 'wrap', indices: [2, 3] },
    ]);
  });

  it('keeps an existing starter-section group untouched (idempotent)', () => {
    const blocks = [b('core/group', 'starter-section'), b('core/separator'), b('starter/faq')];
    expect(planSections(blocks)).toEqual([
      { kind: 'keep', index: 0 },
      { kind: 'wrap', indices: [2] },
    ]);
  });

  it('no separators, no groups → single section', () => {
    expect(planSections([b('core/heading'), b('core/paragraph')])).toEqual([
      { kind: 'wrap', indices: [0, 1] },
    ]);
  });

  it('all already sections → unchanged', () => {
    const blocks = [b('core/group', 'starter-section'), b('core/group', 'x starter-section y')];
    expect(planSections(blocks)).toEqual([
      { kind: 'keep', index: 0 },
      { kind: 'keep', index: 1 },
    ]);
  });

  it('ignores empty segments from leading/consecutive/trailing separators', () => {
    const blocks = [b('core/separator'), b('starter/hero'), b('core/separator'), b('core/separator'), b('starter/cta'), b('core/separator')];
    expect(planSections(blocks)).toEqual([
      { kind: 'wrap', indices: [1] },
      { kind: 'wrap', indices: [4] },
    ]);
  });
});
