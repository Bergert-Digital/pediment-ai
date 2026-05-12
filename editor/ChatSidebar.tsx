import { PluginSidebar as PluginSidebarFromEditor } from '@wordpress/editor';
import { PluginSidebar as PluginSidebarFromEditPost } from '@wordpress/edit-post';
import { useSelect, dispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import useConversation from './hooks/useConversation';
import useChatTurn from './hooks/useChatTurn';
import useSelectedBlockContext from './hooks/useSelectedBlockContext';
import MessageList from './chat/MessageList';
import Composer from './chat/Composer';
import SelectionChip from './chat/SelectionChip';
import QuickActions from './chat/QuickActions';

const PluginSidebar = PluginSidebarFromEditor ?? PluginSidebarFromEditPost;

export const SIDEBAR_NAME = 'starter-ai/chat';

export default function ChatSidebar() {
  const postId = useSelect((s) => (s('core/editor') as any).getCurrentPostId(), []) as number | null;
  const { conv, error: convError, reload, clear } = useConversation(postId);
  const { streaming, error: turnError, start, stop } = useChatTurn();
  const selected = useSelectedBlockContext();

  useEffect(() => {
    (async () => {
      try {
        const { seen } = await apiFetch<{ seen: boolean }>({ path: '/starter-ai/v1/chat/seen', method: 'GET' });
        if (!seen) {
          const open = (dispatch('core/editor') as any).openGeneralSidebar
            ?? (dispatch('core/edit-post') as any).openGeneralSidebar;
          open?.('starter-ai/chat');
          await apiFetch({ path: '/starter-ai/v1/chat/seen', method: 'POST' });
        }
      } catch {
        // first-run nudge is best-effort
      }
    })();
  }, []);

  const sendWithSelection = (text: string) => {
    if (!conv || !postId) return;
    start({
      conversationId: conv.id,
      postId,
      message: text,
      selectedBlock: selected,
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
      {selected && (
        <>
          <SelectionChip block={selected} />
          <QuickActions block={selected} onAction={sendWithSelection} busy={!!streaming} />
        </>
      )}
      <Composer onSubmit={sendWithSelection} onStop={stop} busy={!!streaming} />
    </PluginSidebar>
  );
}
