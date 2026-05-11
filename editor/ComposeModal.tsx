import { Modal, Button, TextareaControl, SelectControl, RadioControl, Spinner } from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { postCompose } from './hooks/useApiClient';
import useJobPolling from './hooks/useJobPolling';
import SourcePills from './SourcePills';

export default function ComposeModal({ onClose }: { onClose: () => void }) {
  const [prompt,    setPrompt]    = useState('');
  const [pageType,  setPageType]  = useState('landing');
  const [tone,      setTone]      = useState('');
  const [target,    setTarget]    = useState<'replace' | 'insert'>('replace');
  const [jobId,     setJobId]     = useState<number | null>(null);
  const [submitErr, setSubmitErr] = useState<string | null>(null);

  const { status, urls, progressNote, result, error } = useJobPolling(jobId);
  const appliedRef = useRef(false);

  useEffect(() => {
    if (!result || appliedRef.current) return;
    appliedRef.current = true;
    const parsed = parse(serializeTree(result.blocks));
    if (target === 'replace') {
      (dispatch('core/block-editor') as any).resetBlocks(parsed);
    } else {
      (dispatch('core/block-editor') as any).insertBlocks(parsed);
    }
    onClose();
  }, [result, target, onClose]);

  const submit = async () => {
    setSubmitErr(null);
    try {
      const { job_id } = await postCompose({ prompt, page_type: pageType, tone });
      setJobId(job_id);
    } catch (e: any) {
      setSubmitErr(e?.message ?? 'Request failed');
    }
  };

  if (result) return null;

  return (
    <Modal title={__('Compose with AI', 'starter-ai')} onRequestClose={onClose} className="starter-ai__modal">
      {jobId === null && (
        <>
          <TextareaControl
            label={__('Prompt', 'starter-ai')}
            value={prompt}
            onChange={setPrompt}
            rows={5}
            placeholder={__('Describe the page you want…', 'starter-ai')}
          />
          <SelectControl
            label={__('Page type', 'starter-ai')}
            value={pageType}
            options={[
              { label: 'Landing',  value: 'landing' },
              { label: 'About',    value: 'about' },
              { label: 'Services', value: 'services' },
              { label: 'Contact',  value: 'contact' },
              { label: 'Other',    value: 'other' },
            ]}
            onChange={setPageType}
          />
          <TextareaControl
            label={__('Tone (optional)', 'starter-ai')}
            value={tone}
            onChange={setTone}
            rows={2}
          />
          <RadioControl
            label={__('What to do with the result', 'starter-ai')}
            selected={target}
            options={[
              { label: 'Replace current page',    value: 'replace' },
              { label: 'Insert at cursor',        value: 'insert' },
            ]}
            onChange={(v) => setTarget(v as 'replace' | 'insert')}
          />
          {submitErr && <p className="starter-ai__error">{submitErr}</p>}
          <Button variant="primary" onClick={submit} disabled={!prompt.trim()}>
            {__('Compose', 'starter-ai')}
          </Button>
        </>
      )}

      {jobId !== null && status !== 'complete' && status !== 'error' && (
        <div className="starter-ai__progress">
          <Spinner />
          <span>{progressNote ?? 'Working…'}</span>
        </div>
      )}
      {urls.length > 0 && <SourcePills urls={urls} />}
      {status === 'error' && <p className="starter-ai__error">{error ?? __('Compose failed.', 'starter-ai')}</p>}
    </Modal>
  );
}

function serializeTree(tree: any[]): string {
  return tree.map(serializeOne).join('\n\n');
}
function serializeOne(node: any): string {
  const attrs = Object.keys(node.attributes || {}).length ? ' ' + JSON.stringify(node.attributes) : '';
  const inner = (node.innerBlocks || []).map(serializeOne).join('\n');
  if (!inner) {
    return `<!-- wp:${node.name}${attrs} /-->`;
  }
  return `<!-- wp:${node.name}${attrs} -->\n${inner}\n<!-- /wp:${node.name} -->`;
}
