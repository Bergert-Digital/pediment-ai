import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect, useCallback } from '@wordpress/element';

export type ChatMessage = {
  id: number;
  role: 'user' | 'assistant' | 'tool_result';
  status: 'streaming' | 'complete' | 'error' | 'aborted';
  content: string;
  tool_calls: any[];
  error: { code: string; message: string } | null;
  created_at: string;
};

export type Conversation = {
  id: number;
  post_id: number;
  user_id: number;
  messages: ChatMessage[];
};

export default function useConversation(postId: number | null) {
  const [conv, setConv] = useState<Conversation | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!postId) return;
    try {
      const data = await apiFetch<Conversation>({
        path: `/starter-ai/v1/chat/conversations?post_id=${postId}`,
        method: 'GET',
      });
      setConv(data);
    } catch (e: any) {
      setError(e?.message ?? 'Failed to load conversation');
    }
  }, [postId]);

  useEffect(() => { load(); }, [load]);

  const clear = useCallback(async () => {
    if (!conv) return;
    await apiFetch({ path: `/starter-ai/v1/chat/conversations/${conv.id}`, method: 'DELETE' });
    await load();
  }, [conv, load]);

  return { conv, error, reload: load, clear, setConv };
}
