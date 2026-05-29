# Pediment AI Plugin

WordPress plugin that adds AI-powered authoring to the [pediment](https://github.com/bergert/pediment): Compose a page from a prompt, Edit an existing page, Refine a single block.

## Requirements

- WordPress 6.4+, PHP 8.1+
- `pediment` (Plan A) installed and active
- Anthropic API key

## Install (in a Bedrock client repo)

```bash
composer require bergert/pediment-ai
```

Set `ANTHROPIC_API_KEY` in `.env`. The plugin reads from the env constant when set; otherwise it falls back to the encrypted key in Settings → Pediment AI.

## Three flows

- **Compose.** Document sidebar → "Compose with AI" → prompt + page type → fresh page generated from registered blocks.
- **Edit.** Document sidebar → "Edit with AI" → instruction → page content replaced (use Undo to revert).
- **Refine.** Select any Pediment block → Inspector → "AI refine" → quick actions or custom instruction → attributes update.

Compose and Edit run as background jobs (Action Scheduler); the editor polls `/wp-json/pediment-ai/v1/jobs/{id}` every 750ms. Refine is synchronous.

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

### First-time setup

```bash
composer install
npm install
( cd ../pediment && npm install && npm run build )
npm run build
```

### Start wp-env

```bash
npm run env:start
```

URLs after start:

- Editor: http://localhost:8898/wp-admin (admin / password)
- Tests WordPress: http://localhost:8899

Ports are set in [.wp-env.json](.wp-env.json) (8898 / 8899) to avoid colliding with the sibling `pediment` wp-env on 8888 / 8889.

### Stop wp-env

`npm run env:stop` hits a wp-env 10.39 path-resolution bug. Use one of these instead:

```bash
# Stop + remove containers (keeps DB volume — fast restart)
docker compose -f ~/.wp-env/wp-env-pediment-ai-dcebb3bb/docker-compose.yml down

# Just stop the containers (even faster restart)
docker stop $(docker ps -q --filter "name=wp-env-pediment-ai")

# Nuke the DB too (fresh install on next start)
docker compose -f ~/.wp-env/wp-env-pediment-ai-dcebb3bb/docker-compose.yml down -v
```

### Day-to-day commands

```bash
# Rebuild the editor bundle after JS/TS changes
npm run build

# Watch + rebuild on save
npm run start

# Run PHPUnit (with the workaround for the wp-env run bug)
docker exec -w /var/www/html/wp-content/plugins/pediment-ai \
  wp-env-pediment-ai-dcebb3bb-tests-wordpress-1 vendor/bin/phpunit

# Filter a single test class
docker exec -w /var/www/html/wp-content/plugins/pediment-ai \
  wp-env-pediment-ai-dcebb3bb-tests-wordpress-1 vendor/bin/phpunit --filter ComposeJobTest

# Run Playwright E2E (needs wp-env running)
npm run e2e

# PHP lint
composer lint
composer lint:fix
```

Mock mode is on by default in `.wp-env.json` (`PEDIMENT_AI_MOCK=true`), so the plugin returns fixture responses instead of calling Anthropic. Toggle off in plugin settings to test against real Anthropic.

See [docs/prompts.md](docs/prompts.md) for prompt tuning and [docs/privacy.md](docs/privacy.md) for data-handling disclosures clients should include in their privacy policies.
# pediment-ai
