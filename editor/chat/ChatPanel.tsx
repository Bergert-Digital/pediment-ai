import { useSelect } from '@wordpress/data';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import useConversation from '../hooks/useConversation';
import useChatTurn from '../hooks/useChatTurn';
import useSelectedBlockContext from '../hooks/useSelectedBlockContext';
import MessageList from './MessageList';
import Composer from './Composer';
import SelectionChip from './SelectionChip';
import QuickActions from './QuickActions';
import { STORE_NAME, type ChatMessage } from './store';

type Props = {
  /** When mounted from the Block inspector, the selected block is implicit; suppress the chip. */
  hideSelectionChip?: boolean;
};

export default function ChatPanel({ hideSelectionChip = false }: Props) {
  const postId = useSelect((s) => (s('core/editor') as any).getCurrentPostId(), []) as number | null;
  const { conv, clear } = useConversation(postId);
  const { streaming, error, start, stop } = useChatTurn();
  const pendingUserMessage = useSelect((s) => (s(STORE_NAME) as any).getPendingUserMessage() as ChatMessage | null, []);
  const selected = useSelectedBlockContext();

  const messages = pendingUserMessage
    ? [...(conv?.messages ?? []), pendingUserMessage]
    : conv?.messages ?? [];

  const send = (text: string) => {
    if (!conv || !postId) return;
    start({
      conversationId: conv.id,
      postId,
      message: text,
      selectedBlock: selected,
    });
  };

  return (
    <div className="pediment-ai-chat">
      <div className="pediment-ai-chat__header">
        <span className="pediment-ai-chat__title">{__('AI Chat', 'pediment-ai')}</span>
        <Button variant="tertiary" size="small" onClick={clear}>{__('Clear', 'pediment-ai')}</Button>
      </div>
      <MessageList messages={messages} streaming={streaming} />
      {error && <div className="pediment-ai-chat__error">{error}</div>}
      {selected && !hideSelectionChip && <SelectionChip block={selected} />}
      {selected && <QuickActions block={selected} onAction={send} busy={!!streaming} />}
      <Composer onSubmit={send} onStop={stop} busy={!!streaming} />
    </div>
  );
}
