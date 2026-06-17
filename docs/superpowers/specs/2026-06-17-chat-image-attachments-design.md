# Chat image attachments — design

**Date:** 2026-06-17
**Status:** Approved (pending implementation plan)
**Component:** pediment-ai (AI chat in the block editor)

## Goal

Let users paste, upload, or drag-and-drop images into the AI chat composer so they
can show the AI what to build (e.g. a screenshot of a target layout) instead of only
describing it in words. Attached images are sent to the vision-capable compose model
for the turn they are attached to, and persist in the conversation history so they
re-render after the chat reloads.

## Scope

In scope:
- Three capture methods in the composer: clipboard paste, file-picker upload, drag & drop.
- Client-side validation (type), downscaling, and a per-message image cap.
- Persisting images in a dedicated DB table linked to the user message.
- Sending images as Anthropic `image` content blocks on the turn they are attached to.
- Re-rendering image thumbnails on user messages after the conversation reloads.

Out of scope (YAGNI):
- Non-image attachments (PDF/documents).
- Uploading images to the WordPress Media Library.
- Re-sending images from prior turns to the model (history stays text-only).
- A separate thumbnail variant distinct from the stored downscaled image.
- AI *generating* or returning images.

## Accepted decisions

1. **Input methods:** paste from clipboard, file-upload button, and drag & drop — all three.
2. **Persistence:** images persist in conversation history (re-render after reload).
3. **Storage:** base64 in a dedicated DB table, sent inline to Anthropic. Chosen over
   the WP Media Library because (a) inline base64 works identically in local dev
   (localhost:8890, which Anthropic cannot fetch) and in production, and (b) it keeps
   throwaway reference screenshots out of the Media Library.
4. **Caps:** max 5 images per message; each downscaled to 1568px on the long edge.
5. **Thumbnail:** the persisted downscaled image is reused for display; no separate
   thumbnail variant.

## Architecture

### 1. Capture — Composer UI

`editor/chat/Composer.tsx` gains image handling alongside the existing textarea:

- **Paste:** `onPaste` reads image items from `clipboardData.files`.
- **Upload button:** an icon button next to Send that triggers a hidden
  `<input type="file" accept="image/*" multiple>`.
- **Drag & drop:** `onDragOver` / `onDragLeave` / `onDrop` on the composer wrapper,
  with a drop-highlight state while dragging.

All three funnel into one `addFiles(files: File[])` handler that:
- Accepts only `image/jpeg`, `image/png`, `image/gif`, `image/webp` (Anthropic's
  supported set). Other types are rejected with an inline notice.
- Downscales each image client-side via a canvas: max 1568px on the long edge,
  re-encoded to JPEG quality 0.85 (PNG kept as PNG to preserve transparency; GIF kept
  as GIF). Produces `{ media_type, data }` where `data` is base64 without the
  `data:` prefix.
- Enforces the 5-image-per-message cap (additional files beyond the cap are dropped
  with a notice).

Attached images render as a row of removable thumbnail chips above the textarea.
The Send button is enabled when there is **text OR at least one attached image**
(today it requires non-empty text).

State for the attached-but-not-yet-sent images lives in `Composer` local state and is
cleared on submit (mirroring how `value` is cleared today).

### 2. Transport — useChatTurn

`editor/hooks/useChatTurn.ts`:
- `StartArgs` gains `images: { media_type: string; data: string }[]`.
- `start()` includes `images` in the POST `/pediment-ai/v1/chat/turns` body.
- The optimistic `pendingUserMessage` carries the attachments (as data-URI thumbnails)
  so they appear instantly, before the server round-trip.

`ChatPanel.send()` is widened to accept `(text, images)` and pass them through. The
`QuickActions` callers send no images (text only).

### 3. Storage — new table

New table in `src/Schema/tables.php`, created via `dbDelta`:

```sql
CREATE TABLE {prefix}pediment_ai_chat_attachments (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id bigint(20) UNSIGNED NOT NULL,
  media_type varchar(40) NOT NULL,
  data longtext NOT NULL,            -- base64, no data: prefix
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY message_idx (message_id)
) {charset};
```

`src/Chat/ConversationStore.php`:
- `appendUserMessage(int $conversation_id, string $content, array $images = [])` —
  inserts the message, then one attachment row per image. Returns the message id
  (unchanged signature otherwise; `$images` defaults to `[]`).
- `getAttachments(int $message_id): array` — returns `[{media_type, data}]` rows.
- `buildResult()` attaches an `attachments` array to each **user** message it hydrates,
  so the conversation GET response re-renders thumbnails after reload. (One query per
  conversation load, fetching attachments for all message ids in the conversation, to
  avoid N+1.)
- `clear(int $conversation_id)` cascades: delete attachment rows for the conversation's
  messages before/with deleting the messages.

DB version bump (`pediment_ai_db_version`) so the table is created on upgrade.

### 4. Sending to Anthropic — ChatController / TurnRunner

`src/Rest/ChatController.php` `startTurn()`:
- Reads `images` from the request, normalises to `[{media_type, data}]`, validates each
  has an allowed `media_type` and non-empty `data` (defensive; the client already
  validates). Caps at 5.
- Relaxes the validation: today it 400s on empty `message`. New rule — return the error
  only when **both** message and images are empty.
- Persists images via `appendUserMessage(..., $images)` and captures the returned
  user-message id.
- Inline mode: loads the just-persisted images (or reuses the in-hand array) and passes
  them to `processTurn`.
- Auto mode: stashes `user_message_id` (a small int) in the dispatch input — **not** the
  base64 — so the transient stays small. `runTurn()` reads it back, loads attachments via
  `getAttachments()`, and passes the resulting array to `processTurn`.

`src/Chat/TurnRunner.php` `run()`:
- Gains the current user turn's images (loaded from the store by user-message id, passed
  in via `processTurn`).
- Prepends `image` content blocks to the current user turn's `userContent`:
  `{ "type": "image", "source": { "type": "base64", "media_type": ..., "data": ... } }`,
  placed before the context/prefetch/text blocks.
- `historyToMessages()` is unchanged — prior turns' images are **not** re-sent (shown in
  UI, not re-billed).

`ChatController::processTurn()` signature extends with `array $images` (default `[]`);
both the inline and auto paths supply the loaded attachment array, which it forwards to
`TurnRunner::run()`.

### 5. Rendering — MessageList

`editor/chat/MessageList.tsx`:
- Renders a thumbnail row on any user message whose `attachments` is non-empty, using
  `data:{media_type};base64,{data}` as the `<img>` src, sized small via CSS.

`editor/chat/store.ts`:
- `ChatMessage` gains `attachments?: { media_type: string; data: string }[]`.

Styles in `editor/chat/` SCSS for the composer thumbnail chips, the drop-highlight
state, and the message-list thumbnail row.

## Data flow

```
User pastes/drops/picks image(s)
  → Composer.addFiles(): validate type, downscale, cap → thumbnail chips
  → Send → ChatPanel.send(text, images)
  → useChatTurn.start(): optimistic message w/ thumbnails; POST {message, images, ...}
  → ChatController.startTurn(): validate (text OR images), persist message + attachments,
       stash user_message_id (auto) / pass images (inline), dispatch
  → TurnRunner.run(): load attachments, prepend image content blocks to current user turn,
       stream to Anthropic
  → poll → terminal → conversation GET returns attachments → MessageList re-renders thumbnails
```

## Error handling

- **Unsupported file type:** rejected client-side with an inline notice; never reaches
  the server. Server defensively drops any attachment with a disallowed `media_type`.
- **Over the 5-image cap:** extra files dropped client-side with a notice.
- **Empty message and no images:** 400 (unchanged code path, widened condition).
- **Oversized payload:** mitigated by the 1568px downscale + 5-image cap; worst case
  ~2-3MB JSON POST, within typical PHP `post_max_size`. No special handling beyond that.
- **Downscale failure (canvas):** skip that file with a notice; do not block the others.

## Testing

- **PHP unit (`tests/phpunit`):** `getAttachments` round-trip through
  `appendUserMessage`; `clear()` removes attachment rows; `startTurn` accepts an
  images-only request (empty text) and rejects the empty/empty case.
- **TS unit (`editor/chat/test` or `editor/hooks/test`):** the downscale/validate helper
  rejects non-image types, enforces the cap, and strips the data-URI prefix.
- **E2E (`tests/e2e`):** attach a fake image via the file input, send, and assert the
  request to the provider carries an `image` content block (through the MockProvider
  path) and that a thumbnail renders on the user message.

## Open questions

None — all decisions resolved during brainstorming.
