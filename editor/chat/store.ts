import { createReduxStore, register } from '@wordpress/data';

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

type ChatState = {
  conversation: Conversation | null;
  streaming: ChatMessage | null;
  error: string | null;
};

const initialState: ChatState = { conversation: null, streaming: null, error: null };

type Action =
  | { type: 'SET_CONVERSATION'; conversation: Conversation | null }
  | { type: 'SET_STREAMING'; streaming: ChatMessage | null }
  | { type: 'CLEAR_STREAMING' }
  | { type: 'SET_ERROR'; error: string | null };

const reducer = (state: ChatState = initialState, action: Action): ChatState => {
  switch (action.type) {
    case 'SET_CONVERSATION': return { ...state, conversation: action.conversation };
    case 'SET_STREAMING':    return { ...state, streaming: action.streaming };
    case 'CLEAR_STREAMING':  return { ...state, streaming: null };
    case 'SET_ERROR':        return { ...state, error: action.error };
    default:                 return state;
  }
};

const actions = {
  setConversation: (conversation: Conversation | null): Action => ({ type: 'SET_CONVERSATION', conversation }),
  setStreaming:    (streaming: ChatMessage | null): Action    => ({ type: 'SET_STREAMING', streaming }),
  clearStreaming:  (): Action                                 => ({ type: 'CLEAR_STREAMING' }),
  setError:        (error: string | null): Action             => ({ type: 'SET_ERROR', error }),
};

const selectors = {
  getConversation: (state: ChatState) => state.conversation,
  getStreaming:    (state: ChatState) => state.streaming,
  getError:        (state: ChatState) => state.error,
};

export const STORE_NAME = 'starter-ai/chat';

export const chatStore = createReduxStore(STORE_NAME, { reducer, actions, selectors });

let registered = false;
export function ensureChatStoreRegistered(): void {
  if (registered) return;
  register(chatStore);
  registered = true;
}
