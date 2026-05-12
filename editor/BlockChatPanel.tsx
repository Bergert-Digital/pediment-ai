import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ChatPanel from './chat/ChatPanel';

const withChat = createHigherOrderComponent((BlockEdit: any) => (props: any) => {
  return (
    <>
      <BlockEdit {...props} />
      <InspectorControls>
        <PanelBody title={__('AI Chat', 'starter-ai')} initialOpen={false} className="starter-ai-chat__inspector">
          <ChatPanel hideSelectionChip />
        </PanelBody>
      </InspectorControls>
    </>
  );
}, 'withStarterAiChat');

let registered = false;
function ensureFilter() {
  if (registered) return;
  addFilter('editor.BlockEdit', 'starter-ai/chat-panel', withChat);
  registered = true;
}

export default function BlockChatPanel() {
  useEffect(() => { ensureFilter(); }, []);
  return null;
}

// Register at import time so the filter is in place before blocks render.
ensureFilter();
