import { PluginSidebar as PluginSidebarFromEditor } from '@wordpress/editor';
import { PluginSidebar as PluginSidebarFromEditPost } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';

// WP <6.6 only exposes PluginSidebar on @wordpress/edit-post; WP 6.6+ moved it to @wordpress/editor.
const PluginSidebar = PluginSidebarFromEditor ?? PluginSidebarFromEditPost;

export const SIDEBAR_NAME = 'starter-ai/chat';

export default function ChatSidebar() {
  return (
    <PluginSidebar
      name="chat"
      title={__('AI Chat', 'starter-ai')}
      icon="format-chat"
      className="starter-ai-chat"
    >
      <div className="starter-ai-chat__body">
        <p>{__('Chat surface coming online…', 'starter-ai')}</p>
      </div>
    </PluginSidebar>
  );
}
