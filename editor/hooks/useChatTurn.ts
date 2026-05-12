import apiFetch from '@wordpress/api-fetch';
import { useState, useRef, useCallback } from '@wordpress/element';
import { select } from '@wordpress/data';
import type { ChatMessage } from './useConversation';
import applyToolCalls from '../applyToolCalls';

const POLL_MS = 300;

type StartArgs = {
  conversationId: number;
  postId: number;
  message: string;
  selectedBlock: { clientId: string; name: string; attributes: any; innerBlocks: any[] } | null;
  onComplete: (msg: ChatMessage) => void;
};

export default function useChatTurn() {
  const [streaming, setStreaming] = useState<ChatMessage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<number | null>(null);
  const abortedRef = useRef(false);

  const stop = useCallback(() => {
    if (streaming) {
      apiFetch({ path: `/starter-ai/v1/chat/turns/${streaming.id}`, method: 'DELETE' }).catch(() => {});
      abortedRef.current = true;
    }
    if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
    setStreaming(null);
  }, [streaming]);

  const start = useCallback(async (args: StartArgs) => {
    setError(null);
    abortedRef.current = false;
    const blockTree = blocksToTree((select('core/block-editor') as any).getBlocks());
    let turnId: number;
    try {
      const r = await apiFetch<{ turn_id: number }>({
        path: '/starter-ai/v1/chat/turns',
        method: 'POST',
        data: {
          conversation_id: args.conversationId,
          post_id:         args.postId,
          message:         args.message,
          selected_block:  args.selectedBlock,
          block_tree:      blockTree,
        },
      });
      turnId = r.turn_id;
    } catch (e: any) {
      setError(e?.message ?? 'Failed to start turn');
      return;
    }

    const tick = async () => {
      try {
        const t = await apiFetch<ChatMessage>({ path: `/starter-ai/v1/chat/turns/${turnId}`, method: 'GET' });
        if (abortedRef.current) return;
        setStreaming({ ...t, id: turnId });
        if (t.status !== 'streaming') {
          if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
          setStreaming(null);
          if (t.status === 'complete' && Array.isArray(t.tool_calls)) {
            applyToolCalls(t.tool_calls);
          }
          args.onComplete({ ...t, id: turnId });
        }
      } catch (e: any) {
        if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
        setStreaming(null);
        setError(e?.message ?? 'Polling failed');
      }
    };
    await tick();
    timer.current = window.setInterval(tick, POLL_MS);
  }, []);

  return { streaming, error, start, stop };
}

function blocksToTree(blocks: any[]): any[] {
  return blocks.map((b) => ({
    clientId:    b.clientId,
    name:        b.name,
    attributes:  b.attributes ?? {},
    innerBlocks: blocksToTree(b.innerBlocks ?? []),
  }));
}
