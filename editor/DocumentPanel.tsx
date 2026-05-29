import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditor } from '@wordpress/editor';
import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditPost } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import ChatPanel from './chat/ChatPanel';

const PluginDocumentSettingPanel =
  PluginDocumentSettingPanelFromEditor ?? PluginDocumentSettingPanelFromEditPost;

export default function DocumentPanel() {
  return (
    <PluginDocumentSettingPanel
      name="pediment-ai-chat"
      title={__('AI Chat', 'pediment-ai')}
      className="pediment-ai__panel"
    >
      <ChatPanel />
    </PluginDocumentSettingPanel>
  );
}
