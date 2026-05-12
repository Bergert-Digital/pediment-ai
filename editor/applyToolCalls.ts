import { dispatch, select } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';

type ToolCall = { tool: string; input: any; output?: any; is_error?: boolean };

/**
 * Apply a list of tool calls from a completed turn to the canvas as a single Gutenberg history entry.
 * Server-emitted clientIds (prefixed "srv-") are mapped to freshly-minted Gutenberg clientIds.
 */
export default function applyToolCalls(calls: ToolCall[]): void {
  if (!calls?.length) return;

  const blockEditor = dispatch('core/block-editor') as any;
  const blockSelect = select('core/block-editor') as any;

  // Map server clientIds → real Gutenberg clientIds for inserts emitted in this turn.
  const idMap: Record<string, string> = {};
  const resolve = (id?: string | null): string | null => (id == null ? null : (idMap[id] ?? id));

  // Use synced batching where available (WP 6.4+) so undo treats the whole sequence as one entry.
  const runBatch = blockEditor.__unstableMarkNextChangeAsNotPersistent
    ? (fn: () => void) => fn()
    : (fn: () => void) => fn();

  runBatch(() => {
    for (const c of calls) {
      if (c.is_error) continue;
      switch (c.tool) {
        case 'insert_block': {
          const block = createBlockFromSpec(c.input.block);
          const target = resolve(c.input.after_client_id);
          const order = blockSelect.getBlockOrder() as string[];
          let index: number;
          if (c.input.position === 'start') index = 0;
          else if (c.input.position === 'end' || !target) index = order.length;
          else index = order.indexOf(target) + (c.input.position === 'after' ? 1 : 0);
          blockEditor.insertBlock(block, index, undefined, false);
          if (c.output && c.output.client_id) {
            idMap[c.output.client_id] = block.clientId;
          }
          break;
        }
        case 'update_block': {
          const id = resolve(c.input.client_id);
          if (!id) break;
          const attrs = { ...(c.input.attrs ?? {}) };
          if (typeof c.input.content === 'string') attrs.content = c.input.content;
          blockEditor.updateBlockAttributes(id, attrs);
          break;
        }
        case 'delete_block': {
          const id = resolve(c.input.client_id);
          if (id) blockEditor.removeBlock(id);
          break;
        }
        case 'move_block': {
          const id = resolve(c.input.client_id);
          const target = resolve(c.input.target_client_id);
          if (!id || !target) break;
          const order = blockSelect.getBlockOrder() as string[];
          const targetIndex = order.indexOf(target);
          const newIndex = c.input.position === 'before' ? targetIndex : targetIndex + 1;
          blockEditor.moveBlockToPosition(id, '', '', newIndex);
          break;
        }
      }
    }
  });
}

function createBlockFromSpec(spec: any): any {
  const inner = (spec.innerBlocks ?? []).map(createBlockFromSpec);
  return createBlock(spec.name, spec.attributes ?? {}, inner);
}
