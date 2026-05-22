import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { SelectedBlock } from '../hooks/useSelectedBlockContext';

const PRESETS: Record<string, { label: string; instruction: string }[]> = {
  'core/paragraph': [
    { label: 'Shorten',     instruction: 'Shorten the selected paragraph.' },
    { label: 'Expand',      instruction: 'Expand the selected paragraph with more detail.' },
    { label: 'Rewrite',     instruction: 'Rewrite the selected paragraph in different words.' },
    { label: 'Fix grammar', instruction: 'Fix any grammar or typos in the selected paragraph.' },
  ],
  'core/heading': [
    { label: 'Shorten',  instruction: 'Shorten the selected heading.' },
    { label: 'Rewrite',  instruction: 'Rewrite the selected heading with a different angle.' },
  ],
  'core/list': [
    { label: 'Add item',   instruction: 'Add another item to the selected list.' },
    { label: 'Reorder',    instruction: 'Reorder the items in the selected list more logically.' },
  ],
  'core/image': [
    { label: 'Alt text', instruction: 'Generate alt text for the selected image.' },
    { label: 'Caption',  instruction: 'Write a short caption for the selected image.' },
  ],
};
const FALLBACK = [
  { label: 'Improve', instruction: 'Improve the selected block.' },
  { label: 'Rewrite', instruction: 'Rewrite the selected block in different words.' },
];

export default function QuickActions({ block, onAction, busy }: { block: SelectedBlock; onAction: (instruction: string) => void; busy: boolean }) {
  const actions = PRESETS[block.name] ?? FALLBACK;
  return (
    <div className="pediment-ai-chat__quick">
      {actions.map((a) => (
        <Button key={a.label} variant="secondary" size="small" onClick={() => onAction(a.instruction)} disabled={busy}>
          {__(a.label, 'pediment-ai')}
        </Button>
      ))}
    </div>
  );
}
