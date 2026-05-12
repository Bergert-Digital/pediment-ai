import { PluginSidebar as PluginSidebarFromEditor } from '@wordpress/editor';
import { PluginSidebar as PluginSidebarFromEditPost } from '@wordpress/edit-post';
import { useSelect } from '@wordpress/data';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import useConversation from './hooks/useConversation';
import useChatTurn from './hooks/useChatTurn';
import MessageList from './chat/MessageList';
import Composer from './chat/Composer';

const PluginSidebar = PluginSidebarFromEditor ?? PluginSidebarFromEditPost;

export const SIDEBAR_NAME = 'starter-ai/chat';

export default function ChatSidebar() {
  const postId = useSelect((s) => (s('core/editor') as any).getCurrentPostId(), []) as number | null;
  const { conv, error: convError, reload, clear } = useConversation(postId);
  const { streaming, error: turnError, start, stop } = useChatTurn();

  const send = (text: string) => {
    if (!conv || !postId) return;
    start({
      conversationId: conv.id,
      postId,
      message: text,
      selectedBlock: null,
      onComplete: () => reload(),
    });
  };

  return (
    <PluginSidebar name="chat" title={__('AI Chat', 'starter-ai')} icon="format-chat" className="starter-ai-chat">
      <div className="starter-ai-chat__header">
        <span className="starter-ai-chat__title">{__('AI Chat', 'starter-ai')}</span>
        <Button variant="tertiary" size="small" onClick={clear}>{__('Clear', 'starter-ai')}</Button>
      </div>
      <MessageList messages={conv?.messages ?? []} streaming={streaming} />
      {(convError || turnError) && <div className="starter-ai-chat__error">{convError ?? turnError}</div>}
      <Composer onSubmit={send} onStop={stop} busy={!!streaming} />
    </PluginSidebar>
  );
}
