# AI Chat Sidebar — Design Spec

**Status:** Design approved, ready for planning
**Date:** 2026-05-12
**Author:** Jonas Bergert (with brainstorming partner)

## Goal

Replace the current modal-driven AI flows (Compose, Edit, block-level Refine) with a single conversational chat surface in a Gutenberg `PluginSidebar`. The chat supports streaming-feeling text responses, surgical block mutations as the assistant works, and is portable to any WordPress host (no SSE required at the edge).

The objective is to give users a roomier, persistent AI surface that handles document-level composition, document-level editing, *and* block-level refinement in one place — and behaves like the chat UIs they already know.

## Non-goals (v1)

- Live SSE streaming through nginx/Apache (we poll a server-side accumulator instead — host-portable)
- Multiple conversations per post / conversation list UI
- Conversation summarization for long threads
- Image attachments / multimodal input
- Diff preview before applying block changes (rejected during brainstorming in favor of atomic apply + single-step Gutenberg undo)
- Cross-post context ("see how I wrote that other article")
- Streaming tool-call previews ("about to insert a heading…" before the turn completes)
- Voice input
- Per-user system-prompt customization
- Replace-tree compose tool (model emits sequences of `insert_block` instead)

## Architecture

```
editor/
  ChatSidebar.tsx              ← top-level PluginSidebar container
  chat/
    MessageList.tsx            ← scrollable message rendering, tool-call summaries
    SelectionChip.tsx          ← shows currently-selected block context
    QuickActions.tsx           ← contextual one-click action row
    Composer.tsx               ← input textarea, send/stop, keyboard handling
  hooks/
    useChatTurn.ts             ← POST turn, poll, apply mutations atomically
    useSelectedBlockContext.ts ← bridges core/block-editor selection → chat

src/
  Chat/
    ConversationStore.php      ← CRUD on conversations & messages
    TurnRunner.php             ← orchestrates Anthropic streaming + DB writes + abort polling
  Rest/
    ChatController.php         ← single REST controller for /chat/*
  Anthropic/
    Client.php                 ← extended with stream_messages()
  Schema/
    tables.php                 ← adds the two new tables
```

## Surface & interaction model

### Sidebar registration

- New `PluginSidebar` registered as `pediment-ai/chat` via `@wordpress/editor` (with `@wordpress/edit-post` fallback for WP <6.6 — mirroring the pattern in `editor/DocumentPanel.tsx`).
- Custom icon in the editor header. Pinnable. Default-closed; auto-opens once on first activation so users discover it.
- The existing `PluginDocumentSettingPanel` shrinks to a single "Open AI chat" button that calls `dispatch('core/editor').openGeneralSidebar('pediment-ai/chat')`. Provides a familiar entry point during transition. Removable later.

### Sidebar layout (top → bottom)

1. **Header** — title "AI", "New conversation" button, kebab menu (Clear history, Settings).
2. **Message list** — scrollable. User messages right-aligned, assistant messages left-aligned. Tool-call results render inline as compact summaries ("Updated 1 paragraph", "Inserted heading + 2 paragraphs"). Clickable to expand and see which `clientId`s were touched.
3. **Selection chip** — visible only when a block is selected in the canvas. Shows block type + truncated content (~60 chars) + ×. Clearing the chip disconnects chat from selection.
4. **Quick-action row** — visible only when the chip is. Buttons contextual to block type:
   - Paragraph: `Shorten` `Expand` `Rewrite` `Fix grammar` `Change tone…`
   - Heading: `Shorten` `Rewrite` `Change level…`
   - List: `Add item` `Reorder` `Convert to paragraphs`
   - Image: `Generate alt text` `Caption`
   - Generic fallback: `Improve` `Rewrite`

   Each button auto-sends a templated user message (visible in the thread) like "Shorten the selected paragraph." The model receives the selected block's `clientId` as context and defaults its tool calls to that block.
5. **Composer** — multi-line textarea, Enter to send, Shift+Enter for newline, Send button. While a turn is streaming: input disabled, a "Stop" button replaces Send and aborts the turn (`DELETE /chat/turns/{id}`).

### Selection awareness

- Sidebar subscribes to `core/block-editor`'s `getSelectedBlockClientId()` via `useSelect`.
- The selected block's `clientId` plus serialized content is attached to each outgoing turn as `context.selected_block`.
- System prompt instructs the model: "The user currently has block `<id>` selected. Default tool calls to this block unless the message says otherwise."

## Tool-use model

### Context sent each turn

- **System prompt** — identity, capabilities, block-mutation tool conventions, output rules (no apologies, no over-explaining, return tool calls when changing the post).
- **Block tree context** — current block tree, serialized compactly with `clientId`, block name (`core/paragraph` etc.), and content.
  - Full content for blocks within ~3 of the selected block (or the whole tree if no selection).
  - For more distant blocks, skeleton only: `clientId`, block name, first ~120 chars of content, marked `truncated: true`.
  - The model can call `read_block(client_id)` to fetch the full content of a truncated block (one extra round trip in the worst case).
- **Conversation history** — last 20 turns. Older turns dropped silently. No summarization in v1.
- **Selection chip** — `context.selected_block: { clientId, name, content }` sent in full when present.

### Tools exposed to the model

```
insert_block(after_client_id: string|null, position: "after"|"before"|"start"|"end", block: BlockSpec)
  -> { client_id }
update_block(client_id: string, attrs?: object, content?: string)
  -> { ok: true }
delete_block(client_id: string)
  -> { ok: true }
move_block(client_id: string, target_client_id: string, position: "before"|"after")
  -> { ok: true }
read_block(client_id: string)
  -> { name, attrs, content }
```

- `BlockSpec` reuses the existing `src/BlockTree/Validator.php` schema. The validator runs on every `insert_block` / `update_block` call's payload (not the whole tree) before the mutation is recorded.
- The turn is an **iterative Anthropic loop, not a single streaming call.** TurnRunner streams the Anthropic response; if `stop_reason` is `tool_use`, the server applies each tool call server-side (validation + virtual-tree bookkeeping), generates synthetic `tool_result` blocks (`{ ok: true, client_id }` for inserts, `{ name, attrs, content }` for `read_block`, `{ error: ... }` on failure), appends them to the message list, and starts a new streaming call. This repeats until `stop_reason` is `end_turn`. Across all iterations, mutation tool calls accumulate in the assistant message row's `tool_calls` JSON column.
- The server maintains a **virtual block tree** for the duration of the turn — applying inserts/updates/deletes/moves in memory as the model calls them — so later tool calls can reference clientIds emitted earlier in the same turn. The client receives the final accumulated tool_calls list and applies them all to the real canvas in one Gutenberg transaction (single Cmd-Z reverts the whole turn).
- `read_block(client_id)` returns content from the virtual tree if available (preferred), otherwise from the context tree sent at turn start. Mutation tool_results return `{ ok: true, client_id? }`.
- If the model emits a tool call referencing a `clientId` that doesn't exist in the virtual tree (e.g., the user manually deleted the block between turns), the server returns a synthetic `tool_result` with `is_error: true` and message "Block not found." The model can correct on the next loop iteration. Same for validator failures.

### Why no `replace_tree` tool

- Forces the model to think in mutations rather than wholesale rewrites — better behavior on "tweak this" turns.
- Compose-from-blank still works: the model emits a sequence of `insert_block` calls with `after_client_id: null`.
- `BlockTree/Serializer` remains useful (server-side serialization of tool-call args into block markup before they're applied client-side). `BlockTree/Parser` is no longer needed in the hot path and is removed.

## Streaming, transport & turn lifecycle

### Why polling, not SSE

True SSE streaming through WordPress is unreliable in the wild: managed hosts (Kinsta, WP Engine, SiteGround, Pressable) and CDNs (Cloudflare default) put nginx/Apache/edge proxies in front of PHP that buffer responses until completion. `mod_deflate` / gzip compression buffers too. `max_execution_time` (often 30-60s) can kill long streams. No reliable feature-detect.

Instead: **server streams from Anthropic into the DB; client polls the DB row.** Perceptually indistinguishable from SSE at ~300ms intervals.

### Turn lifecycle

1. Client `POST /wp-json/pediment-ai/v1/chat/turns` with `{ conversation_id, post_id, message, selected_block? }`. Server:
   - Loads or creates the conversation (`conversation_id` may be null).
   - Inserts a `user` row in `chat_messages`.
   - Inserts an `assistant` row with `status: streaming`, empty `content`, empty `tool_calls`. Its `id` is the `turn_id`.
   - Starts the Anthropic streaming call. Returns `202 Accepted { turn_id }` *before* the stream finishes, using `fastcgi_finish_request()` to close the HTTP response while the PHP process continues writing stream deltas to the DB.
2. Client polls `GET /chat/turns/{turn_id}` at ~300ms intervals. Response:
   ```json
   {
     "status": "streaming" | "complete" | "error" | "aborted",
     "content": "string",
     "tool_calls": [...],
     "error": { "code": "...", "message": "..." }
   }
   ```
   Client renders `content` into the bubble incrementally. Stops polling when `status !== "streaming"`.
3. On `status: complete`: client applies all `tool_calls` in a single Gutenberg `core/block-editor` batch wrapped as one history entry (one Cmd-Z reverts the turn).
4. On `status: error` or `aborted`: assistant bubble shows the error inline with a "Retry" button that resends the previous user message.

### Abort path

Client `DELETE /chat/turns/{turn_id}` sets `status: aborted` in the DB. The streaming PHP process checks the row's status between Anthropic SSE events and bails out, closing the cURL connection to Anthropic.

### Portability fallback

If `fastcgi_finish_request()` is unavailable, the POST request keeps the PHP worker occupied until the Anthropic stream finishes — at which point the client gets `turn_id` and polls for an already-complete turn. The chat still works, but the first response feels non-streaming. Plugin activation detects this and surfaces an admin notice. Action Scheduler is a candidate v2 fallback but is out of scope for v1.

## Schema

Two new tables, registered via `src/Schema/tables.php` and applied through the existing `dbDelta()` upgrade path. **Conversations are scoped per (post_id, user_id)** — each editor has their own private chat thread on each post. Two editors working on the same post do not see each other's history. `GET /chat/conversations?post_id={n}` returns the conversation owned by the current user.

```sql
CREATE TABLE {prefix}pediment_ai_chat_conversations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id       BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  KEY (post_id),
  KEY (user_id)
);

CREATE TABLE {prefix}pediment_ai_chat_messages (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id   BIGINT UNSIGNED NOT NULL,
  role              ENUM('user','assistant','tool_result') NOT NULL,
  status            ENUM('streaming','complete','error','aborted') NOT NULL DEFAULT 'complete',
  content           LONGTEXT NOT NULL,
  tool_calls        LONGTEXT NULL,   -- JSON
  error             LONGTEXT NULL,   -- JSON
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  KEY (conversation_id, id)
);
```

The polling endpoint is a single-row primary-key lookup on `chat_messages.id` — sub-millisecond. Full `content` is returned every poll in v1 (not deltas); switch to cursor-based slicing if posts grow very long.

## REST surface

All under `/wp-json/pediment-ai/v1/`. One controller: `src/Rest/ChatController.php`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/chat/conversations?post_id={n}` | Fetch (or lazily create) the conversation for this post. Returns `{ id, messages: [...] }`. v1 returns all messages, cap ~200. |
| `POST` | `/chat/turns` | Start a new turn. Body: `{ conversation_id, post_id, message, selected_block? }`. Returns `202 { turn_id }`. |
| `GET` | `/chat/turns/{id}` | Poll a turn's state. Shape above. |
| `DELETE` | `/chat/turns/{id}` | Abort an in-flight turn. |
| `DELETE` | `/chat/conversations/{id}` | Clear history. Hard delete for simplicity. |

All routes require `edit_post` capability on the target `post_id`. Conversation/turn ownership is verified by traversing turn → conversation → post → cap check.

## Demolition list

**Deleted outright:**
- `editor/ComposeModal.tsx`
- `editor/EditModal.tsx`
- `editor/BlockPanel.tsx`
- `editor/RefineActions.tsx`
- `editor/SourcePills.tsx` (verify it's not used elsewhere during planning)
- `editor/hooks/useJobPolling.ts`
- `src/Rest/ComposeController.php`
- `src/Rest/EditController.php`
- `src/Rest/RefineController.php`
- `src/Rest/StatusController.php`
- `src/Jobs/ComposeJob.php`
- `src/Jobs/JobStore.php`
- `src/BlockTree/Parser.php`

**Reshaped:**
- `editor/DocumentPanel.tsx` — shrinks to "Open AI chat" launcher button.
- `editor/index.tsx` — registers the new `PluginSidebar`, removes `BlockPanel` registration.
- `editor/styles.scss` — chat layout styles added, modal styles removed.
- `src/Bootstrap.php` — wires `ChatController` only; old controllers removed.
- `src/Anthropic/Client.php` — adds `stream_messages()` returning an iterable of parsed SSE events.
- `src/Schema/tables.php` — adds the two new tables.

**Unchanged:**
- `src/Anthropic/SchemaBuilder.php`, `ToolUseParser.php`, `ProviderInterface.php`
- `src/BlockTree/Validator.php`, `Serializer.php`
- `src/Settings/*`, `src/Usage/*`
- `src/Mock/MockProvider.php` (gains a `stream_messages` shim)

**New:**
- `src/Chat/ConversationStore.php`, `src/Chat/TurnRunner.php`
- `src/Rest/ChatController.php`
- `editor/ChatSidebar.tsx`
- `editor/chat/MessageList.tsx`, `SelectionChip.tsx`, `QuickActions.tsx`, `Composer.tsx`
- `editor/hooks/useChatTurn.ts`, `useSelectedBlockContext.ts`

## Defensible defaults

- **Model**: keep whatever the current `Anthropic/Client` default is; expose model choice in the existing Settings page only. No in-chat picker in v1.
- **Auth**: `edit_post` capability on the target `post_id` for every route.
- **Rate limiting**: per-turn check via the existing `Usage/RateLimiter`. Token-quota check stays per-token via `Usage/Tracker`.
- **Error UX**: errors render inline in the assistant bubble (red border, error text, Retry button). Anthropic 429 → "Rate limit hit, try again in N seconds" using the `retry-after` header. Validator failures → "I tried to make a change that didn't fit the post structure" + the offending tool call shown collapsed.
- **Telemetry**: existing `Usage/Tracker` records one row per completed assistant message using Anthropic's `usage` event at stream end. No new telemetry tables.

## Open risks

1. **`fastcgi_finish_request()` availability.** Hosts without it lose the streaming feel on the initial POST (rest of the chat still works). Surface an admin notice on activation when missing.
2. **Long block trees blow up the prompt.** Skeleton + `read_block` mitigates, but extremely large posts (>200 blocks) may still feel sluggish in tool round trips. Acceptable for v1; revisit if users complain.
3. **Concurrent turns in the same chat.** Two tabs for the same user editing the same post can start overlapping turns. v1 simply allows it — last write wins on the canvas. Server enforces no race on the DB rows (each turn has its own message row). If this becomes a real problem, add a per-conversation `in_flight_turn_id` lock.
