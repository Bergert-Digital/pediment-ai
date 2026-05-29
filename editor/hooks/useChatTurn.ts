import apiFetch from '@wordpress/api-fetch';
import { useCallback } from '@wordpress/element';
import { select as wpSelect, useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME, ensureChatStoreRegistered, type ChatMessage, type Conversation } from '../chat/store';
import applyToolCalls from '../applyToolCalls';
import { startPolling } from './pollTurn';

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
  const { setStreaming, clearStreaming, setPendingUserMessage, setError, setConversation } = useDispatch(STORE_NAME) as any;

  const stop = useCallback(() => {
    if (streaming) {
      // streaming.id < 0 means we're still on the optimistic placeholder (POST hasn't returned a real turn id yet).
      if (streaming.id > 0) {
        apiFetch({ path: `/pediment-ai/v1/chat/turns/${streaming.id}`, method: 'DELETE' }).catch(() => {});
      }
      aborted = true;
    }
    if (pollTimer !== null) { window.clearInterval(pollTimer); pollTimer = null; }
    clearStreaming();
    setPendingUserMessage(null);
  }, [streaming, clearStreaming, setPendingUserMessage]);

  const start = useCallback(async (args: StartArgs) => {
    setError(null);
    aborted = false;

    // Optimistic UI: render the user's message and a streaming placeholder synchronously,
    // so the chat updates the instant the user clicks Send rather than after the POST + first poll.
    const now = new Date().toISOString();
    setPendingUserMessage({
      id: -Date.now(),
      role: 'user',
      status: 'complete',
      content: args.message,
      tool_calls: [],
      error: null,
      created_at: now,
    });
    setStreaming({
      id: -1,
      role: 'assistant',
      status: 'streaming',
      content: '',
      tool_calls: [],
      error: null,
      created_at: now,
    });

    const blockTree = blocksToTree((wpSelect('core/block-editor') as any).getBlocks());
    let turnId: number;
    try {
      const r = await apiFetch<{ turn_id: number }>({
        path: '/pediment-ai/v1/chat/turns',
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
      setPendingUserMessage(null);
      clearStreaming();
      return;
    }

    startPolling(turnId, {
      apiFetch: (a) => apiFetch(a as any),
      applyToolCalls,
      onUpdate: (t) => setStreaming({ ...t, id: turnId }),
      onError: (msg) => { clearStreaming(); setError(msg); },
      onTerminal: async () => {
        clearStreaming();
        // Re-fetch the conversation so persisted messages show up in MessageList.
        try {
          const conv = await apiFetch<Conversation>({
            path: `/pediment-ai/v1/chat/conversations?post_id=${args.postId}`,
            method: 'GET',
          });
          setConversation(conv);
        } catch {
          // best-effort; the next mount will reload
        }
      },
      isAborted: () => aborted,
      intervalMs: POLL_MS,
      schedule: (fn, ms) => { pollTimer = window.setInterval(fn, ms); return pollTimer; },
      cancel: (h) => { window.clearInterval(h); if (h === pollTimer) pollTimer = null; },
    });
  }, [setStreaming, clearStreaming, setPendingUserMessage, setError, setConversation]);

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
