# Standards — non-negotiable quality bars

These are the things that, if missing or broken, block a feature from shipping. Not nice-to-haves.

## Every user-facing flow has

- **Loading state.** Something visible within 1.5s of action. A spinner alone is OK if accompanied by a label ("Generating page…", "Refining block…"). A blank screen is not OK.
- **Error state.** If a request fails, the user sees a sentence telling them *what* failed and *what they can do*. ("Anthropic returned an error. Try again, or check your API key in Settings.") Never a stack trace, never a bare "Error".
- **Empty state.** Brand-new install, no conversation history, no API key, no selected block — every one of those has a coherent screen. Empty doesn't mean blank.
- **Abort path.** Long-running operations (any chat turn) have a Stop button that actually stops the work server-side within ~1s.
- **Undo.** Every block mutation applied by the AI must collapse into a single Gutenberg history entry. One `Cmd-Z` reverts the whole turn.

## Every REST endpoint has

- **Capability check.** `current_user_can('edit_posts')` (or stricter — `edit_post` on the specific post for chat). Reject 403 with a JSON error.
- **Nonce verification.** WP REST nonce on writes.
- **Input validation.** All params declared in `args` with `type` + `sanitize_callback` + `validate_callback` where it matters. Don't validate impossible cases — only real boundaries.
- **Rate limit.** Per-user, per-hour, enforced via `Usage\RateLimiter`. Default limits live in `OptionsStore`; tune in admin settings.
- **Mock-aware.** When `PEDIMENT_AI_MOCK=true` (or the option is set), the controller resolves the `MockProvider` instead of `Anthropic\Client`. Tests rely on this.
- **PHPUnit coverage.** At minimum: happy path, auth failure, validation failure. Stored under `tests/phpunit/Rest/*ControllerTest.php`.

## Every block mutation goes through

- **Virtual tree.** Tool calls run against `Chat\VirtualTree`, not directly on the WP post. The tree is the single source of truth during a turn.
- **Per-node validation.** `BlockTree\Validator::validateNode()` rejects blocks the host theme has not registered, *before* they enter the virtual tree.
- **Atomic apply.** Client applies all collected mutations in one `editor.replaceBlocks` (or equivalent) inside `dispatch('core').__unstableMarkLastChangeAsPersistent()` so the history coalesces.

## Tests we won't ship without

- **PHPUnit** — unit coverage of all `src/Chat/*`, `src/Rest/*`, `src/BlockTree/*`, `src/Usage/*`, `src/Anthropic/*`.
- **Playwright E2E** — one spec per chat flow (compose, refine, abort). Run against mock fixtures so they're fast and deterministic.
- **No mocked databases in PHPUnit.** Use `WP_UnitTestCase` and let it talk to the real wpdb. We've been burned by mock/prod divergence elsewhere.
- **No skipped tests in main.** A skipped test rots — either fix the test or delete it.

## Code style

- PHP follows `phpcs.xml.dist`. Run `composer lint` before committing. CI will reject otherwise.
- TS strict mode is on; don't `// @ts-ignore` to escape it. If the type genuinely can't be expressed, add a one-line comment explaining why.
- No new comments that restate the code. The exception is hidden constraints — e.g. "Action Scheduler's procedural API is registered by its bootstrap file, which Composer's autoloader does not execute."
- No dead code paths. If a branch can't be reached, delete it — don't comment-pretty-print it.

## Releases

- Bumping `PEDIMENT_AI_VERSION` in [plugin.php](plugin.php) triggers a re-run of `pediment_ai_install_tables()` on the next page load. Always bump when schema changes.
- `composer.json` version stays in lockstep with `PEDIMENT_AI_VERSION`.
- Release notes captured in `SESSION_LOG.md` for the cutting session; promoted to a CHANGELOG when we have one.

## Security

- Anthropic API key stored encrypted in `wp_options` via `Settings\OptionsStore`. Never logged. Never returned by REST.
- `web_fetch_20250910` runs server-side at Anthropic — we don't proxy. Fetched URLs are surfaced as pills in the UI for transparency.
- No `eval` / `assert` / `extract` in PHP. No `dangerouslySetInnerHTML` in TSX.

## What we don't gate on

- Lines-of-code metrics. Big PRs are fine if cohesive.
- Code coverage percent. Cover the meaningful paths; don't game the number.
- Bundle size of the editor JS. WP installs already ship megabytes of editor code; our additions are marginal.
