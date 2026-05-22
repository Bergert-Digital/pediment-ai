# Vision — Starter AI

## What we're building

A WordPress plugin that lets editors compose, edit, and refine pages by talking to Claude — directly inside the Gutenberg block editor.

Editors describe what they want (or pick a quick action), and the assistant performs surgical, undoable mutations against the block tree of the current post. The model only ever produces blocks the host theme has registered, so the output stays on-brand and renders correctly.

## Who it's for

- **Primary user:** the editor at a small-to-mid-sized agency client site, working in WP admin. Not a developer. Knows Gutenberg. Wants to draft and polish pages faster without copy-pasting between ChatGPT and the editor.
- **Buyer:** the agency (Bergert Digital and similar shops) deploying client sites built on top of `pediment`. They install the plugin, configure brand voice + API key once, and the feature shows up for every editor on every page.
- **Not for:** WordPress.org plugin directory users, end-customers of the websites being built, hobbyists running unconstrained themes. The plugin assumes a curated set of registered blocks and a Bedrock-style install.

## Why this exists

Editors at our client sites already draft with ChatGPT and paste results into Gutenberg, where the formatting breaks, lists become paragraphs, and headings need re-leveling. That round-trip is the friction we're removing.

Generative AI in the WP editor exists in other plugins, but they either: (a) generate freeform HTML that ignores the theme's block library, or (b) require a SaaS account and pipe content through someone else's infrastructure. Neither fits agency clients who need provider-direct API calls, GDPR-friendly disclosures, and tight theme integration.

## What "good" looks like

An editor opens a fresh page, clicks "Open AI chat," types "Landing page for a Berlin physiotherapy clinic, three services, contact CTA," and within ~30 seconds has a credible draft made entirely of theme-registered blocks. They select a paragraph, click "Shorten," and it gets shorter. They undo once and the entire turn reverts in a single Gutenberg history step. They never leave the editor.

## Boundaries

**In scope:**

- Compose, edit, and block-level refinement via a single chat sidebar.
- Streaming-feeling responses (server-side DB polling, no SSE on the wire — portable across hosts).
- Anthropic provider only (Claude). Configurable model per flow.
- Brand voice / tone propagated automatically into prompts.
- Per-user rate limiting + month-to-date usage tracking.
- Mock mode for local dev.
- E2E + unit test coverage of all three flows.

**Out of scope (today):**

- Multi-provider (OpenAI, Gemini). Single-provider lets us go deep on Claude features (web_fetch, prompt caching, tool use).
- Public WordPress.org distribution. We ship via Composer to known-good Bedrock installs.
- Image generation / multimodal input.
- Cross-post / cross-site context.
- Workflow features (approvals, drafts visible to specific roles, scheduled publishing).
- Per-editor system-prompt customization.

**Will reconsider later:**

- Multiple conversations per post.
- Conversation summarization for long threads.
- Diff preview before applying.
- Streaming tool-call previews ("about to insert a heading…").

## Operating principles

1. **Host-portable beats fastest-possible streaming.** Many client sites run on shared hosts with FastCGI buffering. We chose DB polling over SSE so the plugin Just Works on anyone's stack.
2. **Tools, not free-form output.** The model emits structured `insert_block` / `update_block` / `delete_block` / `move_block` tool calls against a virtual tree. We never trust freeform HTML.
3. **Atomic application.** A turn's tool calls apply to the canvas as one Gutenberg history entry. One undo reverts the whole turn.
4. **Mock mode is first-class.** Local dev and CI run against fixtures by default. Real Anthropic calls are a deliberate toggle.
5. **GDPR-honest.** What we send to Anthropic is documented in `docs/privacy.md`. No silent data flows.
