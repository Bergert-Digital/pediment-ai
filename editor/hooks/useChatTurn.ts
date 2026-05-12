import apiFetch from '@wordpress/api-fetch';
import { useCallback } from '@wordpress/element';
import { select as wpSelect, useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME, ensureChatStoreRegistered, type ChatMessage, type Conversation } from '../chat/store';
import applyToolCalls from '../applyToolCalls';

ensureChatStoreRegistered();

const POLL_MS = 300;

// Module-level — shared across every component that mounts useChatTurn.
let pollTimer: number | null = null;
let aborted = false;

type StartArgs = {
  conversationId: number;
  postId: number;
  message: string;
  selectedBlock: { clientId: string; name: string; attributes: any; innerBlocks: any[] } | null;
};

export default function useChatTurn() {
  const streaming = useSelect((s) => (s(STORE_NAME) as any).getStreaming() as ChatMessage | null, []);
  const error     = useSelect((s) => (s(STORE_NAME) as any).getError()     as string | null,      []);
  const { setStreaming, clearStreaming, setError, setConversation } = useDispatch(STORE_NAME) as any;

  const stop = useCallback(() => {
    if (streaming) {
      apiFetch({ path: `/starter-ai/v1/chat/turns/${streaming.id}`, method: 'DELETE' }).catch(() => {});
      aborted = true;
    }
    if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
    clearStreaming();
  }, [streaming, clearStreaming]);

  const start = useCallback(async (args: StartArgs) => {
    setError(null);
    aborted = false;
    const blockTree = blocksToTree((wpSelect('core/block-editor') as any).getBlocks());
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
        if (aborted) return;
        setStreaming({ ...t, id: turnId });
        if (t.status !== 'streaming') {
          if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
          clearStreaming();
          if (t.status === 'complete' && Array.isArray(t.tool_calls)) {
            applyToolCalls(t.tool_calls);
          }
          // Re-fetch the conversation so persisted messages show up in MessageList.
          try {
            const conv = await apiFetch<Conversation>({
              path: `/starter-ai/v1/chat/conversations?post_id=${args.postId}`,
              method: 'GET',
            });
            setConversation(conv);
          } catch {
            // best-effort; the next mount will reload
          }
        }
      } catch (e: any) {
        if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
        clearStreaming();
        setError(e?.message ?? 'Polling failed');
      }
    };
    await tick();
    pollTimer = window.setInterval(tick, POLL_MS);
  }, [setStreaming, clearStreaming, setError, setConversation]);

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
