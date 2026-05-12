import { useEffect, useRef } from '@wordpress/element';
import ToolCallSummary from './ToolCallSummary';
import type { ChatMessage } from '../hooks/useConversation';

export default function MessageList({ messages, streaming }: { messages: ChatMessage[]; streaming: ChatMessage | null }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => { ref.current?.scrollTo({ top: ref.current.scrollHeight, behavior: 'smooth' }); }, [messages.length, streaming?.content]);

  const display = streaming ? [...messages, streaming] : messages;

  return (
    <div className="starter-ai-chat__messages" ref={ref}>
      {display.map((m) => (
        <div key={m.id} className={`starter-ai-chat__message starter-ai-chat__message--${m.role}`}>
          <div className="starter-ai-chat__bubble">
            {m.content}
            {m.status === 'streaming' && <span className="starter-ai-chat__caret" />}
          </div>
          <ToolCallSummary calls={m.tool_calls} />
          {m.error && <div className="starter-ai-chat__error">{m.error.message}</div>}
        </div>
      ))}
    </div>
  );
}
