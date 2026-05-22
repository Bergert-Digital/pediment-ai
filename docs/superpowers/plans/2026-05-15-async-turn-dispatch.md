# Async Turn Dispatch (host-portable streaming) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a chat turn run in a separate request (non-blocking loopback) so `POST /chat/turns` returns `turn_id` immediately and the existing 300 ms poller observes `appendAssistantDelta` writes in real time — restoring visible streaming on Apache mod_php (wp-env) **and** php-fpm, with no browser-SSE.

**Architecture:** A new `TurnDispatcher` stashes the request-only inputs (block_tree, selected_block, message) in a per-turn transient, mints a one-time token, and fires a non-blocking `wp_remote_post` loopback to a new internal route `POST /chat/turns/{id}/run`. That route authenticates by token (system call, not cookie), guards idempotency on turn status, and runs the existing `processTurn`. `startTurn` returns `202 {turn_id}` instantly. A filterable dispatch mode falls back to the current synchronous behavior when loopback is unavailable; the fastcgi/`respondAndFlush` path is removed (loopback supersedes it on every SAPI).

**Tech Stack:** WordPress REST API, `wp_remote_post` non-blocking loopback (same mechanism as core `spawn_cron()`), transients, PHPUnit via wp-env tests container, `pre_http_request` filter for HTTP mocking, existing `MockProvider` via `pediment_ai_provider` filter.

**Spec basis:** Conversation research 2026-05-15 — `fastcgi_finish_request` is FPM-only ([PHP manual](https://www.php.net/manual/en/function.fastcgi-finish-request.php)); non-blocking loopback is the WP-canonical cross-SAPI pattern ([Trac #18738](https://core.trac.wordpress.org/ticket/18738)); Action Scheduler is explicitly not for real-time user-facing work ([actionscheduler.org/faq](https://actionscheduler.org/faq/)).

**Worktree note:** Plan-driven multi-task work → execute in a short-lived worktree off `development` (per user worktree policy; create via `superpowers:using-git-worktrees` at execution start). **No schema/migration tasks** — state is carried in transients, not DB columns — so the whole plan is worktree-safe.

**wp-env loopback gotcha (critical):** Inside the wp-env WordPress container, Apache listens on port **80**; the site URL is `http://localhost:8890` (host port mapping). A loopback from PHP *inside* the container to `localhost:8890` does not resolve (nothing on 8890 inside the container) — this is why wp-cron is unreliable in wp-env. The dispatcher therefore targets a **filterable loopback base URL** defaulting to `home_url()` but overridable via the `PEDIMENT_AI_LOOPBACK_URL` constant, set to `http://127.0.0.1` for wp-env in the existing gitignored `pediment-child-theme/.wp-env.override.json`. Requests use the `?rest_route=` form (no permalink dependency) and an explicit `Host` header.

---

## Task 1: TurnDispatcher — one-time token mint/verify (TDD)

**Files:**
- Create: `src/Chat/TurnDispatcher.php`
- Test: `tests/phpunit/Chat/TurnDispatcherTokenTest.php`

- [ ] **Step 1: Write the failing test**

`tests/phpunit/Chat/TurnDispatcherTokenTest.php`:
```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\TurnDispatcher;

class TurnDispatcherTokenTest extends \WP_UnitTestCase {
	public function test_minted_token_verifies_once_then_is_consumed(): void {
		$d     = new TurnDispatcher();
		$token = $d->mintToken( 42 );

		$this->assertNotSame( '', $token );
		$this->assertTrue( $d->consumeToken( 42, $token ), 'first use valid' );
		$this->assertFalse( $d->consumeToken( 42, $token ), 'second use rejected (one-time)' );
	}

	public function test_wrong_token_is_rejected(): void {
		$d = new TurnDispatcher();
		$d->mintToken( 7 );
		$this->assertFalse( $d->consumeToken( 7, 'not-the-token' ) );
		$this->assertFalse( $d->consumeToken( 999, 'anything' ), 'unknown turn rejected' );
	}
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `cd /Users/jonas/Entwicklung/pediment-child-theme && npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai vendor/bin/phpunit --filter TurnDispatcherTokenTest`
Expected: FAIL — `Error: Class "PedimentAi\Chat\TurnDispatcher" not found`.

- [ ] **Step 3: Create `src/Chat/TurnDispatcher.php` with token methods only**

```php
<?php
/**
 * Dispatches a chat turn to run in a separate (loopback) request so the
 * starting request can return immediately and the poller can stream.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TurnDispatcher {
	private const TTL = 300; // seconds; a turn must start within 5 min.

	private function tokenKey( int $turn_id ): string {
		return 'pediment_ai_turn_token_' . $turn_id;
	}

	public function mintToken( int $turn_id ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( $this->tokenKey( $turn_id ), $token, self::TTL );
		return $token;
	}

	public function consumeToken( int $turn_id, string $token ): bool {
		$stored = get_transient( $this->tokenKey( $turn_id ) );
		if ( ! is_string( $stored ) || '' === $stored || ! hash_equals( $stored, $token ) ) {
			return false;
		}
		delete_transient( $this->tokenKey( $turn_id ) );
		return true;
	}
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: same command as Step 2.
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/TurnDispatcher.php tests/phpunit/Chat/TurnDispatcherTokenTest.php
git commit -m "feat(chat): TurnDispatcher one-time turn token"
```

---

## Task 2: Stash + retrieve per-turn runtime inputs (TDD)

The loopback runner has no access to the original request body. Persist the request-only inputs (block_tree, selected_block, message, conversation_id) keyed by turn id.

**Files:**
- Modify: `src/Chat/TurnDispatcher.php`
- Test: `tests/phpunit/Chat/TurnDispatcherStashTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\TurnDispatcher;

class TurnDispatcherStashTest extends \WP_UnitTestCase {
	public function test_stash_roundtrips_then_clears(): void {
		$d       = new TurnDispatcher();
		$payload = [
			'conversation_id' => 5,
			'message'         => 'create a landing page',
			'selected_block'  => null,
			'block_tree'      => [ [ 'name' => 'core/paragraph', 'clientId' => 'a' ] ],
		];
		$d->stashInput( 11, $payload );

		$this->assertSame( $payload, $d->takeInput( 11 ) );
		$this->assertNull( $d->takeInput( 11 ), 'second take is empty (consumed)' );
	}

	public function test_take_missing_returns_null(): void {
		$this->assertNull( ( new TurnDispatcher() )->takeInput( 123456 ) );
	}
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `... vendor/bin/phpunit --filter TurnDispatcherStashTest`
Expected: FAIL — `Call to undefined method ...::stashInput()`.

- [ ] **Step 3: Add stash methods to `TurnDispatcher`**

Add inside the class:
```php
	private function inputKey( int $turn_id ): string {
		return 'pediment_ai_turn_input_' . $turn_id;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function stashInput( int $turn_id, array $payload ): void {
		set_transient( $this->inputKey( $turn_id ), $payload, self::TTL );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function takeInput( int $turn_id ): ?array {
		$v = get_transient( $this->inputKey( $turn_id ) );
		if ( ! is_array( $v ) ) {
			return null;
		}
		delete_transient( $this->inputKey( $turn_id ) );
		return $v;
	}
```

- [ ] **Step 4: Run it, verify it passes**

Run: same as Step 2. Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/TurnDispatcher.php tests/phpunit/Chat/TurnDispatcherStashTest.php
git commit -m "feat(chat): stash per-turn runtime inputs for the loopback runner"
```

---

## Task 3: Loopback URL + non-blocking dispatch (TDD)

**Files:**
- Modify: `src/Chat/TurnDispatcher.php`
- Test: `tests/phpunit/Chat/TurnDispatcherLoopbackTest.php`

- [ ] **Step 1: Write the failing test** (intercept the loopback with `pre_http_request`)

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\TurnDispatcher;

class TurnDispatcherLoopbackTest extends \WP_UnitTestCase {
	public function test_dispatch_fires_nonblocking_loopback_with_token_header(): void {
		$captured = [];
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = [ 'args' => $args, 'url' => $url ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );

		( new TurnDispatcher() )->dispatch( 77, 'tok-abc' );

		remove_all_filters( 'pre_http_request' );
		$this->assertStringContainsString( 'rest_route=', $captured['url'] );
		$this->assertStringContainsString( '/pediment-ai/v1/chat/turns/77/run', urldecode( $captured['url'] ) );
		$this->assertFalse( $captured['args']['blocking'], 'must be non-blocking' );
		$this->assertSame( 'tok-abc', $captured['args']['headers']['X-Pediment-Ai-Token'] );
		$this->assertLessThanOrEqual( 1.0, $captured['args']['timeout'] );
	}

	public function test_loopback_base_is_filterable(): void {
		$seen = '';
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$seen ) {
			$seen = $url;
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );
		add_filter( 'pediment_ai_loopback_url', fn() => 'http://127.0.0.1' );

		( new TurnDispatcher() )->dispatch( 5, 't' );

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pediment_ai_loopback_url' );
		$this->assertStringStartsWith( 'http://127.0.0.1/?rest_route=', $seen );
	}
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `... --filter TurnDispatcherLoopbackTest`
Expected: FAIL — `Call to undefined method ...::dispatch()`.

- [ ] **Step 3: Add `dispatch()` + loopback URL resolution to `TurnDispatcher`**

```php
	/**
	 * Loopback base URL. Defaults to the site home. Override for containerised
	 * dev (e.g. wp-env: define PEDIMENT_AI_LOOPBACK_URL = 'http://127.0.0.1')
	 * because the mapped host port is not reachable from inside the container.
	 */
	public function loopbackUrl(): string {
		$base = defined( 'PEDIMENT_AI_LOOPBACK_URL' ) ? (string) PEDIMENT_AI_LOOPBACK_URL : home_url();
		/**
		 * Filter the loopback base URL used to run chat turns out-of-band.
		 *
		 * @param string $base Base origin, no trailing path.
		 */
		return (string) apply_filters( 'pediment_ai_loopback_url', $base );
	}

	public function dispatch( int $turn_id, string $token ): void {
		$base = rtrim( $this->loopbackUrl(), '/' );
		$url  = $base . '/?rest_route=' . rawurlencode( '/' . \PedimentAi\Rest\ChatController::NS . '/chat/turns/' . $turn_id . '/run' );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'headers'   => array_filter( [
				'X-Pediment-Ai-Token' => $token,
				'Host'               => $host,
			] ),
			'body'      => [ 'turn_id' => $turn_id ],
		] );
	}
```

- [ ] **Step 4: Run it, verify it passes**

Run: same as Step 2. Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/TurnDispatcher.php tests/phpunit/Chat/TurnDispatcherLoopbackTest.php
git commit -m "feat(chat): non-blocking loopback dispatch with filterable URL"
```

---

## Task 4: Internal `/chat/turns/{id}/run` route — auth + idempotency (TDD)

**Files:**
- Modify: `src/Rest/ChatController.php` (add route in `register()`; add `permRunTurn`, `runTurn`; remove `canDeferResponse`/`respondAndFlush`; rewrite `startTurn`)
- Test: `tests/phpunit/Rest/RunTurnRouteTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\TurnDispatcher;

class RunTurnRouteTest extends \WP_UnitTestCase {
	private int $conv;
	private int $turn;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		( new \PedimentAi\Rest\ChatController() )->register();
		$store      = new ConversationStore();
		$c          = $store->getOrCreate( 1, 1 );
		$this->conv = $c['id'];
		$store->appendUserMessage( $this->conv, 'create a landing page' );
		$this->turn = $store->startAssistantTurn( $this->conv );
		// Force the runner to use the deterministic mock provider.
		add_filter( 'pediment_ai_provider', fn() => new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );
	}

	private function call( array $headers ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns/' . $this->turn . '/run' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_missing_or_wrong_token_is_rejected(): void {
		$this->assertSame( 403, $this->call( [] )->get_status() );
		$this->assertSame( 403, $this->call( [ 'X-Pediment-Ai-Token' => 'nope' ] )->get_status() );
	}

	public function test_valid_token_runs_turn_once_and_is_idempotent(): void {
		$d     = new TurnDispatcher();
		$token = $d->mintToken( $this->turn );
		$d->stashInput( $this->turn, [
			'conversation_id' => $this->conv,
			'message'         => 'create a landing page',
			'selected_block'  => null,
			'block_tree'      => [],
		] );

		$first = $this->call( [ 'X-Pediment-Ai-Token' => $token ] );
		$this->assertSame( 204, $first->get_status() );

		$msg = ( new ConversationStore() )->getMessage( $this->turn );
		$this->assertContains( $msg['status'], [ 'complete', 'error' ], 'turn actually ran' );

		// Token consumed → a replay cannot run it again.
		$replay = $this->call( [ 'X-Pediment-Ai-Token' => $token ] );
		$this->assertSame( 403, $replay->get_status() );
	}
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `... --filter RunTurnRouteTest`
Expected: FAIL — route returns 404 (not registered) so status assertions fail.

- [ ] **Step 3: Register the route + permission + handler**

In `register()`, after the existing turns DELETE route (`ChatController.php:54`), add:
```php
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)/run', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'permRunTurn' ],
			'callback'            => [ $this, 'runTurn' ],
		] );
```

Add these methods (place `permRunTurn` after `permTouchTurn`, `runTurn` after `abortTurn`):
```php
	public function permRunTurn( \WP_REST_Request $r ): bool {
		$turn_id = (int) $r->get_param( 'id' );
		$token   = (string) $r->get_header( 'X-Pediment-Ai-Token' );
		return '' !== $token && ( new \PedimentAi\Chat\TurnDispatcher() )->consumeToken( $turn_id, $token );
	}

	public function runTurn( \WP_REST_Request $r ): \WP_REST_Response {
		$turn_id = (int) $r->get_param( 'id' );
		$store   = new ConversationStore();
		$msg     = $store->getMessage( $turn_id );

		// Idempotency: only a freshly-started assistant turn may be run.
		if ( ! $msg || 'streaming' !== $msg['status'] ) {
			return new \WP_REST_Response( null, 204 );
		}

		$input = ( new \PedimentAi\Chat\TurnDispatcher() )->takeInput( $turn_id );
		if ( null === $input ) {
			$store->fail( $turn_id, 'dispatch_lost', 'Turn inputs expired before the runner started.' );
			return new \WP_REST_Response( null, 204 );
		}

		ignore_user_abort( true );
		$tree = new VirtualTree( is_array( $input['block_tree'] ?? null ) ? $input['block_tree'] : [] );
		$this->processTurn(
			$turn_id,
			(int) $input['conversation_id'],
			$tree,
			$input['selected_block'] ?? null,
			(string) $input['message']
		);
		return new \WP_REST_Response( null, 204 );
	}
```

> Note: `startAssistantTurn()` creates the assistant row with status `streaming` (see `ConversationStore`); the idempotency guard relies on that. Confirm the literal in `ConversationStore::startAssistantTurn` is `'streaming'`; if it differs, use that exact value here.

- [ ] **Step 4: Run it, verify it passes**

Run: same as Step 2. Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Rest/ChatController.php tests/phpunit/Rest/RunTurnRouteTest.php
git commit -m "feat(chat): internal token-authed /run route, idempotent"
```

---

## Task 5: Rewrite `startTurn` to dispatch async, with inline fallback (TDD)

**Files:**
- Modify: `src/Rest/ChatController.php` (`startTurn`; delete `canDeferResponse`, `respondAndFlush`)
- Test: `tests/phpunit/Rest/StartTurnDispatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Chat\ConversationStore;

class StartTurnDispatchTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		( new \PedimentAi\Rest\ChatController() )->register();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function start( int $post_id, int $conv ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_body_params( [
			'post_id'         => $post_id,
			'conversation_id' => $conv,
			'message'         => 'create a landing page',
			'block_tree'      => [],
		] );
		return rest_get_server()->dispatch( $req );
	}

	public function test_auto_mode_returns_202_fast_and_fires_loopback_without_running_inline(): void {
		$post = self::factory()->post->create();
		$conv = ( new ConversationStore() )->getOrCreate( $post, get_current_user_id() )['id'];

		$fired = false;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$fired ) {
			if ( false !== strpos( $url, '/run' ) ) {
				$fired = true;
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		}, 10, 3 );

		$res = $this->start( $post, $conv );

		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 202, $res->get_status() );
		$turn_id = $res->get_data()['turn_id'];
		$this->assertTrue( $fired, 'loopback /run was dispatched' );
		// Inline did NOT run it: still in the fresh streaming state.
		$this->assertSame( 'streaming', ( new ConversationStore() )->getMessage( $turn_id )['status'] );
	}

	public function test_inline_mode_runs_synchronously(): void {
		$post = self::factory()->post->create();
		$conv = ( new ConversationStore() )->getOrCreate( $post, get_current_user_id() )['id'];
		add_filter( 'pediment_ai_dispatch_mode', fn() => 'inline' );
		add_filter( 'pediment_ai_provider', fn() => new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );

		$res     = $this->start( $post, $conv );
		$turn_id = $res->get_data()['turn_id'];

		remove_all_filters( 'pediment_ai_dispatch_mode' );
		remove_all_filters( 'pediment_ai_provider' );
		$this->assertSame( 202, $res->get_status() );
		$this->assertContains(
			( new ConversationStore() )->getMessage( $turn_id )['status'],
			[ 'complete', 'error' ],
			'inline mode ran the turn before returning'
		);
	}
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `... --filter StartTurnDispatchTest`
Expected: FAIL — `test_auto_...` fails because current `startTurn` runs inline (status not `streaming`) and never fires `/run`.

- [ ] **Step 3: Rewrite `startTurn`; delete the fastcgi path**

Replace `startTurn` body from `$response = new \WP_REST_Response(...)` (`ChatController.php:119`) through the end of the method with:
```php
		$dispatcher = new \PedimentAi\Chat\TurnDispatcher();
		/**
		 * Dispatch mode: 'auto' (non-blocking loopback; streams) or 'inline'
		 * (run synchronously before responding; no streaming, but needs no
		 * loopback). Default 'auto'.
		 *
		 * @param string $mode
		 */
		$mode = (string) apply_filters( 'pediment_ai_dispatch_mode', 'auto' );

		if ( 'inline' === $mode ) {
			$this->processTurn( $turn_id, $conversation_id, $tree, $selected, $message );
			return new \WP_REST_Response( [ 'turn_id' => $turn_id ], 202 );
		}

		$dispatcher->stashInput( $turn_id, [
			'conversation_id' => $conversation_id,
			'message'         => $message,
			'selected_block'  => $selected,
			'block_tree'      => is_array( $r->get_param( 'block_tree' ) ) ? $r->get_param( 'block_tree' ) : [],
		] );
		$dispatcher->dispatch( $turn_id, $dispatcher->mintToken( $turn_id ) );

		return new \WP_REST_Response( [ 'turn_id' => $turn_id ], 202 );
```

Then delete the now-unused `canDeferResponse()` and `respondAndFlush()` methods (`ChatController.php:182-193`).

- [ ] **Step 4: Run it, verify it passes**

Run: same as Step 2. Expected: PASS (2 tests).

- [ ] **Step 5: Run the full plugin suite (no regressions)**

Run: `cd /Users/jonas/Entwicklung/pediment-child-theme && npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai vendor/bin/phpunit`
Expected: 0 failures. Pre-existing 2 `OptionsStore` skips are unrelated. If any prior test asserted the old synchronous `startTurn`/`canDeferResponse` behavior, update it to set `add_filter('pediment_ai_dispatch_mode', fn()=>'inline')` in its setUp and note the change in the commit body.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/ChatController.php tests/phpunit/Rest/StartTurnDispatchTest.php
git commit -m "feat(chat): async loopback dispatch with inline fallback; drop fastcgi path"
```

---

## Task 6: Lint + docs

**Files:**
- Modify: `docs/extending.md`

- [ ] **Step 1: Run lint gate**

Run: `cd /Users/jonas/Entwicklung/pediment-ai && composer lint`
Expected: exit 0 (warnings-only acceptable, matching the rest of the codebase). Fix any ERROR (e.g. deprecated functions) in the new files.

- [ ] **Step 2: Document the new filters/constant** — append to `docs/extending.md`:

````markdown
## Turn execution mode (streaming vs inline)

A chat turn runs out-of-band via a non-blocking loopback request so the
browser can poll and see streaming. Controls:

```php
// Force synchronous execution (no streaming) — for hosts where loopback
// is blocked. Default is 'auto' (loopback).
add_filter( 'pediment_ai_dispatch_mode', fn() => 'inline' );

// Override the loopback origin. Needed in containers (e.g. wp-env) where
// the public host:port is not reachable from inside the container.
add_filter( 'pediment_ai_loopback_url', fn() => 'http://127.0.0.1' );
```

Or define `PEDIMENT_AI_LOOPBACK_URL` (constant) for the same effect without a
filter — preferred for wp-env via `.wp-env.override.json`.
````

- [ ] **Step 3: Commit**

```bash
git add docs/extending.md
git commit -m "docs(extending): document dispatch mode + loopback URL controls"
```

---

## Task 7: wp-env wiring + end-to-end streaming verification

**Files:**
- Modify: `/Users/jonas/Entwicklung/pediment-child-theme/.wp-env.override.json` (gitignored — local only)

- [ ] **Step 1: Point the loopback at the container's own Apache**

Merge into `pediment-child-theme/.wp-env.override.json` `config` block (preserve the existing `ANTHROPIC_API_KEY`):
```json
{
  "config": {
    "ANTHROPIC_API_KEY": "<existing value — do not change>",
    "PEDIMENT_AI_LOOPBACK_URL": "http://127.0.0.1"
  }
}
```

- [ ] **Step 2: Apply config (regenerates wp-config, no DB wipe)**

Run: `cd /Users/jonas/Entwicklung/pediment-child-theme && npm run env:start`
Then confirm: `npx wp-env run cli wp eval 'echo defined("PEDIMENT_AI_LOOPBACK_URL") ? PEDIMENT_AI_LOOPBACK_URL : "UNSET";'`
Expected: `http://127.0.0.1`.

- [ ] **Step 3: Merge the worktree back to `development`, rebuild, then verify on the user's main checkout**

Per worktree policy: run automated checks in the worktree, merge to `development`, then hand off. No frontend build changed (frontend already polls), so no `npm run build` needed. The user re-runs "create a landing page for a hair salon" at `localhost:8890` in the browser.

- [ ] **Step 4: Confirm streaming with DB timestamp spread (objective evidence)**

After a fresh turn, run:
```bash
cd /Users/jonas/Entwicklung/pediment-child-theme
npx wp-env run cli wp eval '
$w=$GLOBALS["wpdb"];
$r=$w->get_row("SELECT id,status,created_at,updated_at,CHAR_LENGTH(content) c FROM wp_pediment_ai_chat_messages WHERE role=\"assistant\" ORDER BY id DESC LIMIT 1",ARRAY_A);
echo "turn={$r["id"]} status={$r["status"]} created={$r["created_at"]} updated={$r["updated_at"]} content_len={$r["c"]}\n";'
```
Expected (streaming working): the row reaches `status=streaming` with growing `content` while the turn runs, and `updated_at` is seconds later than `created_at` — i.e. the runner request executed separately while `startTurn` already returned. If `created_at == updated_at` and content only appears at the end, loopback did not reach the runner: re-check `PEDIMENT_AI_LOOPBACK_URL` and that `127.0.0.1:80` serves WP inside the container (`npx wp-env run cli curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/?rest_route=/`).

- [ ] **Step 5: No commit** — `.wp-env.override.json` is gitignored (local dev only). Note completion to the user.

---

## Self-Review

**Spec coverage:**
- "POST returns turn_id immediately" → Task 5 (202 without inline run in auto mode) ✓
- "turn runs in a separate request, poller streams" → Task 4 `/run` + Task 3 non-blocking loopback ✓
- "works on mod_php and FPM" → loopback is SAPI-independent; fastcgi path removed (Task 5) ✓
- "no browser-SSE; keep poll architecture" → no frontend change; poller untouched ✓
- "graceful fallback when loopback unavailable" → `pediment_ai_dispatch_mode=inline` (Task 5, Task 6 docs) ✓
- "auth + no double-run" → one-time token (Task 1) + status-guard idempotency (Task 4) ✓
- "wp-env loopback gotcha" → filterable URL + constant + Task 7 wiring/verification ✓
- "no schema/migration" → transients only; worktree-safe (header) ✓

**Placeholder scan:** No TBD/TODO; every code step is complete; every command has an expected result. The one conditional ("if a prior test asserted old behavior, set inline filter") is an explicit, bounded instruction, not a hidden gap.

**Type/name consistency:** `TurnDispatcher` methods — `mintToken`/`consumeToken` (Task 1), `stashInput`/`takeInput` (Task 2), `dispatch`/`loopbackUrl` (Task 3) — used identically in Tasks 4 & 5. Route `'/chat/turns/(?P<id>\d+)/run'` and header `X-Pediment-Ai-Token` consistent across Tasks 3, 4. Transient key prefixes (`pediment_ai_turn_token_`, `pediment_ai_turn_input_`) defined once in Task 1/2 and not reused elsewhere. `pediment_ai_dispatch_mode` / `pediment_ai_loopback_url` / `PEDIMENT_AI_LOOPBACK_URL` consistent across Tasks 3, 5, 6, 7.

**Open risk flagged for execution:** `ConversationStore::startAssistantTurn` must create the row with status `'streaming'` for the Task 4 idempotency guard and the Task 5 `test_auto_...` assertion. Verify that literal at execution start; if it is e.g. `'pending'`, substitute the actual value in the Task 4 guard and the Task 5 test.
