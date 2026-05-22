import { Button } from '@wordpress/components';
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Composer({ onSubmit, onStop, busy }: { onSubmit: (text: string) => void; onStop: () => void; busy: boolean }) {
  const [value, setValue] = useState('');
  const ref = useRef<HTMLTextAreaElement>(null);

  const submit = () => {
    const trimmed = value.trim();
    if (!trimmed || busy) return;
    onSubmit(trimmed);
    setValue('');
  };

  return (
    <div className="pediment-ai-chat__composer">
      <textarea
        ref={ref}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
        }}
        placeholder={__('Ask the AI to write or edit…', 'pediment-ai')}
        rows={3}
        disabled={busy}
      />
      <div className="pediment-ai-chat__composer-actions">
        {busy
          ? <Button variant="secondary" onClick={onStop}>{__('Stop', 'pediment-ai')}</Button>
          : <Button variant="primary"   onClick={submit} disabled={!value.trim()}>{__('Send', 'pediment-ai')}</Button>}
      </div>
    </div>
  );
}
