import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditor } from '@wordpress/editor';
import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditPost } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import ChatPanel from './chat/ChatPanel';

const PluginDocumentSettingPanel =
  PluginDocumentSettingPanelFromEditor ?? PluginDocumentSettingPanelFromEditPost;

export default function DocumentPanel() {
  return (
    <PluginDocumentSettingPanel
      name="starter-ai-chat"
      title={__('AI Chat', 'starter-ai')}
      className="starter-ai__panel"
    >
      <ChatPanel />
    </PluginDocumentSettingPanel>
  );
}
