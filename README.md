# Starter AI Plugin

WordPress plugin that adds AI-powered authoring to the [wp-starter-theme](https://github.com/bergert/wp-starter-theme): Compose a page from a prompt, Edit an existing page, Refine a single block.

## Requirements

- WordPress 6.4+, PHP 8.1+
- `wp-starter-theme` (Plan A) installed and active
- Anthropic API key

## Install (in a Bedrock client repo)

```bash
composer require bergert/wp-starter-ai
```

Set `ANTHROPIC_API_KEY` in `.env`. The plugin reads from the env constant when set; otherwise it falls back to the encrypted key in Settings → Starter AI.

## Three flows

- **Compose.** Document sidebar → "Compose with AI" → prompt + page type → fresh page generated from registered blocks.
- **Edit.** Document sidebar → "Edit with AI" → instruction → page content replaced (use Undo to revert).
- **Refine.** Select any starter block → Inspector → "AI refine" → quick actions or custom instruction → attributes update.

Compose and Edit run as background jobs (Action Scheduler); the editor polls `/wp-json/starter-ai/v1/jobs/{id}` every 750ms. Refine is synchronous.

## Web fetch

The model has access to Anthropic's `web_fetch_20250910` server tool during Compose and Edit. It may fetch URLs the user mentions or that it decides are relevant. Fetched URLs appear as pills in the editor.

## Models

Defaults (configurable in Settings):

- Compose / Edit: `claude-sonnet-4-6`
- Refine: `claude-haiku-4-5`

## Rate limits

Per-user, per-hour defaults (configurable in Settings):

- Compose: 30
- Edit: 30
- Refine: 200

## Local dev

```bash
composer install
npm install
( cd ../wp-starter-theme && npm install && npm run build )
npm run build
npm run env:start    # http://localhost:8898 (admin/password)
```

Mock mode is on by default in `.wp-env.json` (`STARTER_AI_MOCK=true`), so the plugin returns fixture responses instead of calling Anthropic. Toggle off in plugin settings to test against real Anthropic.

See [docs/prompts.md](docs/prompts.md) for prompt tuning and [docs/privacy.md](docs/privacy.md) for data-handling disclosures clients should include in their privacy policies.
# WP-Starter-AI
