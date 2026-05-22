import apiFetch from '@wordpress/api-fetch';
import { useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME, ensureChatStoreRegistered, type Conversation, type ChatMessage } from '../chat/store';

ensureChatStoreRegistered();

export type { ChatMessage, Conversation };

let inflight: Promise<void> | null = null;
let lastLoadedPostId: number | null = null;

export default function useConversation(postId: number | null) {
  const conv = useSelect((s) => (s(STORE_NAME) as any).getConversation() as Conversation | null, []);
  const { setConversation, setError } = useDispatch(STORE_NAME) as any;

  const load = useCallback(async () => {
    if (!postId) return;
    if (inflight && lastLoadedPostId === postId) return inflight;
    lastLoadedPostId = postId;
    inflight = (async () => {
      try {
        const data = await apiFetch<Conversation>({
          path: `/pediment-ai/v1/chat/conversations?post_id=${postId}`,
          method: 'GET',
        });
        setConversation(data);
      } catch (e: any) {
        setError(e?.message ?? 'Failed to load conversation');
      } finally {
        inflight = null;
      }
    })();
    return inflight;
  }, [postId, setConversation, setError]);

  useEffect(() => {
    if (!conv || conv.post_id !== postId) load();
  }, [postId, conv, load]);

  const clear = useCallback(async () => {
    if (!conv) return;
    await apiFetch({ path: `/pediment-ai/v1/chat/conversations/${conv.id}`, method: 'DELETE' });
    lastLoadedPostId = null;
    await load();
  }, [conv, load]);

  return { conv, reload: load, clear };
}
