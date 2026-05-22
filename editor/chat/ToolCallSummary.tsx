import { useState } from '@wordpress/element';

export default function ToolCallSummary({ calls }: { calls: any[] }) {
  const [open, setOpen] = useState(false);
  if (!calls?.length) return null;
  const counts: Record<string, number> = {};
  for (const c of calls) { counts[c.tool] = (counts[c.tool] ?? 0) + 1; }
  const label = Object.entries(counts).map(([t, n]) => `${humanize(t, n)}`).join(', ');
  return (
    <div className="pediment-ai-chat__tools">
      <button type="button" className="pediment-ai-chat__tools-toggle" onClick={() => setOpen(!open)}>
        {open ? '▾ ' : '▸ '}{label}
      </button>
      {open && (
        <pre className="pediment-ai-chat__tools-detail">{JSON.stringify(calls, null, 2)}</pre>
      )}
    </div>
  );
}

function humanize(tool: string, n: number): string {
  const noun = tool.replace(/_/g, ' ');
  return n === 1 ? `1 ${noun}` : `${n} ${noun}s`;
}
