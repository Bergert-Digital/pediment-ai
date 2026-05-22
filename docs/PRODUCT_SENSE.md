# Product sense — how to evaluate work like a user

When you finish a task, don't just check the code compiles and tests pass. Open the editor (or imagine doing so) and walk through the flow as a real editor would. The questions below catch the issues unit tests miss.

## The user persona

Imagine Anja — content lead at a German physiotherapy clinic chain. She:

- Manages 6 sites on Bedrock installs, each running `pediment`.
- Is comfortable in Gutenberg but doesn't know what a "block" or "REST endpoint" is.
- Drafts in German, occasionally English.
- Has roughly 12 minutes between meetings and wants to ship a "Services" page in that window.
- Loses trust the moment something looks half-broken (a stuck spinner, a button that doesn't do anything, a page that says "Loading…" with no progress).

If a change wouldn't make Anja's 12 minutes better, it's probably not worth shipping. If a change might *worsen* her 12 minutes (a regression in a flow she relies on), it's probably worth reverting until fixed.

## The three core journeys

### 1. Compose a new page

> "I need a landing page for our Charlottenburg location."

- Opens a fresh page. Is there an obvious entry point for AI? (The Document panel's "Open AI chat" launcher, or auto-opened sidebar on first activation.)
- Types a prompt or picks a page type. Does the chat make the next step clear without explaining itself in three paragraphs?
- Sees the turn streaming. Is *something* moving on screen within 1.5s? (If polling is 300ms and tool calls accumulate at ~5-10s, what is she looking at in the meantime?)
- Turn completes. Are the inserted blocks all theme blocks? Did anything render as `<!-- wp:something/missing -->` placeholder? Does selecting any inserted block show its inspector controls?
- Does **Undo** revert the entire compose turn in one step, leaving the editor as it was before "Send"?

### 2. Edit an existing page

> "Make this more concise and add a CTA at the end."

- Page has existing blocks. She opens the chat. Does the sidebar know what's already on the page? Does the model have context without her copy-pasting?
- She sends. The model might issue 4 `update_block` calls and one `insert_block`. While it's working, can she still see the old content? (Don't blank the canvas — apply atomically at the end.)
- Result: the right blocks updated, nothing else touched. The CTA is at the bottom, not in the middle.
- **Undo** restores the pre-turn state in one step.

### 3. Refine a selected block

> Anja selects a paragraph, clicks "Shorten."

- Quick action appears only when a block is selected. Is the row's action set actually relevant to the block type? (Don't show "Change heading level" on a paragraph.)
- Clicking sends a templated user message that shows up in the thread, so she has a record of what she asked for.
- The model defaults its tool calls to the selected block — doesn't go editing siblings.
- Undo behavior: one step reverts.

## Cross-cutting product questions

For every flow, ask:

- **Trust signals.** Does the user know the model is working? Did errors arrive with a useful sentence, not a stack trace?
- **Reversibility.** Is everything undoable in one Gutenberg history step? If something was applied that shouldn't have been, can she fix it without reaching for the database?
- **Abort.** While a turn is running, the **Stop** button must actually stop it within ~1s. Server should mark the turn aborted and not keep streaming into a corpse.
- **Empty states.** Brand new install, no API key set, mock mode off — what does she see? Is the message actionable ("Add your Anthropic API key in Settings → Pediment AI")?
- **Rate limit hit.** What does the chat look like after the 31st compose this hour? Does it tell her *when* she can try again?
- **Selection lost.** She clicks the chat sidebar — does that count as deselecting a block? (It shouldn't; quick actions need persistent selection.)
- **Cold canvas.** Page is empty, nothing selected. Quick-action row should be hidden, not show stale buttons.

## Smells to look for

When auditing the codebase, these are red flags worth fixing without being asked:

- A `try/catch` that swallows the error and shows a generic "Something went wrong." Always surface what the user can do next.
- A spinner that runs forever on a failed network request.
- A button labeled "Submit" or "Click here" — be specific.
- An empty-state placeholder that's just `…`. Empty states are the cheapest UX wins available.
- Copy that names internal concepts ("Tool call", "VirtualTree", "Turn ID"). Anja doesn't know what those are.
- A flow that says "Page generated successfully" without showing what was generated.
- A modal where the X is the only way out and clicking the backdrop does nothing.

## What product sense is NOT

Don't substitute product sense for actual user testing. Once a flow is live, the questions Anja actually asks ("why did it write headings in English when I typed in German?") will outrank anything imagined here. When unsure, **ask** the user before adding speculative polish.
