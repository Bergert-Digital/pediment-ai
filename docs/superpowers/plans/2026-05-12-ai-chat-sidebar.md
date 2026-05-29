# AI Chat Sidebar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the modal-driven AI flows (Compose/Edit/Refine) with a single Gutenberg `PluginSidebar` chat that streams via DB-backed polling, uses surgical block-mutation tools, and works on any WordPress host.

**Architecture:** Server iterates Anthropic streaming calls in a tool-use loop, accumulating tool calls and prose deltas into a `chat_messages` row keyed per (post, user). Client polls that row at ~300ms and renders text live; on `status: complete` it applies all collected mutations to the canvas in one Gutenberg history entry. No SSE on the wire — keeps things host-portable.

**Tech Stack:** PHP 8.1, WordPress 6.4+, `@wordpress/editor`, `@wordpress/scripts`, phpunit (WP_UnitTestCase), Playwright.

**Spec:** [docs/superpowers/specs/2026-05-12-ai-chat-sidebar-design.md](../specs/2026-05-12-ai-chat-sidebar-design.md)

---

## File Structure

**Created:**

| Path | Purpose |
|---|---|
| `src/Chat/ConversationStore.php` | CRUD on `chat_conversations` + `chat_messages` |
| `src/Chat/Tools.php` | Tool-schema definitions + tool-result application against a virtual tree |
| `src/Chat/VirtualTree.php` | In-memory representation of the post's block tree across one turn; supports `insert/update/delete/move/read` operations and clientId generation |
| `src/Chat/PromptBuilder.php` | Builds system prompt, context messages (block tree skeleton + selection) for a turn |
| `src/Chat/TurnRunner.php` | Orchestrates the Anthropic iterative tool-use loop with abort polling |
| `src/Rest/ChatController.php` | Single REST controller for `/chat/*` routes |
| `src/Activation/StreamingCheck.php` | Detects `fastcgi_finish_request` and registers an admin notice if absent |
| `editor/ChatSidebar.tsx` | Top-level `PluginSidebar` container |
| `editor/chat/MessageList.tsx` | Scrollable message rendering |
| `editor/chat/Composer.tsx` | Input textarea + send/stop |
| `editor/chat/SelectionChip.tsx` | Currently-selected block context display |
| `editor/chat/QuickActions.tsx` | Contextual one-click action row |
| `editor/chat/ToolCallSummary.tsx` | Compact inline tool-call result rendering |
| `editor/hooks/useChatTurn.ts` | Submit turn + poll + apply mutations atomically |
| `editor/hooks/useSelectedBlockContext.ts` | Bridges `core/block-editor` selection → chat |
| `editor/hooks/useConversation.ts` | Loads/refreshes the conversation for the current post |
| `editor/applyToolCalls.ts` | Pure function: applies a tool-call list to the Gutenberg canvas in one history entry |

**Modified:**

| Path | Change |
|---|---|
| `src/Schema/tables.php` | Add two new tables; bump `PEDIMENT_AI_VERSION` reference unchanged |
| `src/Anthropic/Client.php` | Add `stream_messages()` returning an iterator of parsed SSE events |
| `src/Anthropic/ProviderInterface.php` | Add `stream_messages()` to the contract |
| `src/Mock/MockProvider.php` | Add `stream_messages()` shim returning canned events from fixture |
| `src/BlockTree/Validator.php` | Add `validateNode()` for single-block validation |
| `src/Bootstrap.php` | Wire `ChatController` + `StreamingCheck`; remove old controller wiring |
| `plugin.php` | Bump `PEDIMENT_AI_VERSION` to `0.2.0` (triggers schema upgrade) |
| `editor/DocumentPanel.tsx` | Shrink to "Open AI chat" launcher button |
| `editor/index.tsx` | Register new `PluginSidebar`; stop registering `BlockPanel` |
| `editor/styles.scss` | Chat layout styles added, modal styles removed |

**Deleted:**

- `editor/ComposeModal.tsx`
- `editor/EditModal.tsx`
- `editor/BlockPanel.tsx`
- `editor/RefineActions.tsx`
- `editor/SourcePills.tsx`
- `editor/hooks/useJobPolling.ts`
- `editor/hooks/useApiClient.ts`
- `src/Rest/ComposeController.php`
- `src/Rest/EditController.php`
- `src/Rest/RefineController.php`
- `src/Rest/StatusController.php`
- `src/Jobs/ComposeJob.php`
- `src/Jobs/JobStore.php`
- `src/BlockTree/Parser.php`
- All phpunit tests for the above (`tests/phpunit/Rest/*`, `tests/phpunit/Jobs/*`, `tests/phpunit/BlockTree/ParserTest.php` if present)
- `tests/e2e/compose.spec.ts`, `tests/e2e/edit.spec.ts`, `tests/e2e/refine.spec.ts` (replaced by new chat specs)

**Mock fixtures:** Existing `src/Mock/fixtures/*.json` are retained for reference but not used by the chat path. New chat fixtures live in `src/Mock/fixtures/chat/` (one file per scenario; each contains an ordered list of synthetic SSE events).

---

## Conventions

**Commit messages:** Conventional Commits (`feat:`, `fix:`, `refactor:`, `test:`, `chore:`). Include `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

**Run tests:**
- PHP unit: `composer test` (runs `phpunit`). Single class: `composer test -- --filter ChatControllerTest`.
- JS lint: `npm run lint:js`. Build: `npm run build`.
- E2E: `npm run e2e` (requires `wp-env` running; start with `npm run env:start`).

**Capabilities check pattern:** All chat routes use `permission_callback` that asserts `current_user_can( 'edit_post', $post_id )` and that the conversation/turn belongs to a post the user can edit.

**Commit cadence:** Commit after each task (each task contains failing test → implementation → passing test → commit steps).

---

### Task 1: Add chat schema tables

**Files:**
- Modify: `src/Schema/tables.php`
- Test: `tests/phpunit/Schema/ChatTablesTest.php`
- Bump: `plugin.php:22` (PEDIMENT_AI_VERSION → '0.2.0')

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Schema/ChatTablesTest.php`:

```php
<?php
namespace PedimentAi\Tests\Schema;

class ChatTablesTest extends \WP_UnitTestCase {
	public function test_chat_tables_exist_after_install(): void {
		\pediment_ai_install_tables();
		global $wpdb;
		$conv = $wpdb->prefix . 'pediment_ai_chat_conversations';
		$msgs = $wpdb->prefix . 'pediment_ai_chat_messages';
		$this->assertSame( $conv, $wpdb->get_var( "SHOW TABLES LIKE '{$conv}'" ) );
		$this->assertSame( $msgs, $wpdb->get_var( "SHOW TABLES LIKE '{$msgs}'" ) );
	}

	public function test_chat_tables_have_expected_columns(): void {
		\pediment_ai_install_tables();
		global $wpdb;
		$cols = array_column(
			$wpdb->get_results( "DESCRIBE {$wpdb->prefix}pediment_ai_chat_conversations", ARRAY_A ),
			'Field'
		);
		$this->assertSame( [ 'id', 'post_id', 'user_id', 'created_at', 'updated_at' ], $cols );

		$cols = array_column(
			$wpdb->get_results( "DESCRIBE {$wpdb->prefix}pediment_ai_chat_messages", ARRAY_A ),
			'Field'
		);
		$this->assertContains( 'role',       $cols );
		$this->assertContains( 'status',     $cols );
		$this->assertContains( 'content',    $cols );
		$this->assertContains( 'tool_calls', $cols );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ChatTablesTest`
Expected: FAIL — tables do not exist.

- [ ] **Step 3: Bump plugin version**

In `plugin.php`, change:
```
define( 'PEDIMENT_AI_VERSION', '0.1.0' );
```
to:
```
define( 'PEDIMENT_AI_VERSION', '0.2.0' );
```

- [ ] **Step 4: Add tables to schema installer**

In `src/Schema/tables.php`, inside `pediment_ai_install_tables()`, after the existing `dbDelta( $sql_usage );` line and before `update_option(...)`, add:

```php
$conv = $wpdb->prefix . 'pediment_ai_chat_conversations';
$msgs = $wpdb->prefix . 'pediment_ai_chat_messages';

$sql_conv = "CREATE TABLE {$conv} (
	id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id bigint(20) UNSIGNED NOT NULL,
	user_id bigint(20) UNSIGNED NOT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY post_user_idx (post_id, user_id)
) {$charset};";

$sql_msgs = "CREATE TABLE {$msgs} (
	id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	conversation_id bigint(20) UNSIGNED NOT NULL,
	role varchar(20) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'complete',
	content longtext NOT NULL,
	tool_calls longtext NULL,
	error longtext NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY conv_idx (conversation_id, id)
) {$charset};";

dbDelta( $sql_conv );
dbDelta( $sql_msgs );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter ChatTablesTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Schema/tables.php plugin.php tests/phpunit/Schema/ChatTablesTest.php
git commit -m "$(cat <<'EOF'
feat(schema): add chat_conversations and chat_messages tables

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: ConversationStore — get-or-create conversation

**Files:**
- Create: `src/Chat/ConversationStore.php`
- Test: `tests/phpunit/Chat/ConversationStoreTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Chat/ConversationStoreTest.php`:

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\ConversationStore;

class ConversationStoreTest extends \WP_UnitTestCase {
	private ConversationStore $store;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );
		$this->store = new ConversationStore();
	}

	public function test_get_or_create_creates_when_missing(): void {
		$conv = $this->store->getOrCreate( 42, 7 );
		$this->assertSame( 42, $conv['post_id'] );
		$this->assertSame( 7,  $conv['user_id'] );
		$this->assertGreaterThan( 0, $conv['id'] );
		$this->assertSame( [], $conv['messages'] );
	}

	public function test_get_or_create_returns_existing(): void {
		$first  = $this->store->getOrCreate( 42, 7 );
		$second = $this->store->getOrCreate( 42, 7 );
		$this->assertSame( $first['id'], $second['id'] );
	}

	public function test_get_or_create_scopes_per_user(): void {
		$a = $this->store->getOrCreate( 42, 7 );
		$b = $this->store->getOrCreate( 42, 8 );
		$this->assertNotSame( $a['id'], $b['id'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ConversationStoreTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement ConversationStore.getOrCreate**

Create `src/Chat/ConversationStore.php`:

```php
<?php
/**
 * CRUD for the chat_conversations and chat_messages tables.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConversationStore {
	private string $conversations;
	private string $messages;

	public function __construct() {
		global $wpdb;
		$this->conversations = $wpdb->prefix . 'pediment_ai_chat_conversations';
		$this->messages      = $wpdb->prefix . 'pediment_ai_chat_messages';
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	public function getOrCreate( int $post_id, int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$this->conversations} WHERE post_id = %d AND user_id = %d LIMIT 1",
				$post_id,
				$user_id
			),
			ARRAY_A
		);
		if ( $row ) {
			return $this->load( (int) $row['id'] );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->conversations,
			[ 'post_id' => $post_id, 'user_id' => $user_id, 'created_at' => $now, 'updated_at' => $now ]
		);
		return $this->load( (int) $wpdb->insert_id );
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}|null
	 */
	public function findById( int $id ): ?array {
		$row = $this->loadHeader( $id );
		return $row ? $this->load( $id ) : null;
	}

	/**
	 * @return array{id:int, post_id:int, user_id:int, messages:array<int,array<string,mixed>>}
	 */
	private function load( int $id ): array {
		$header = $this->loadHeader( $id );
		if ( ! $header ) {
			return [ 'id' => 0, 'post_id' => 0, 'user_id' => 0, 'messages' => [] ];
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->messages} WHERE conversation_id = %d ORDER BY id ASC LIMIT 200",
				$id
			),
			ARRAY_A
		);
		$messages = array_map( [ $this, 'hydrate' ], $rows ?: [] );
		return [
			'id'       => (int) $header['id'],
			'post_id'  => (int) $header['post_id'],
			'user_id'  => (int) $header['user_id'],
			'messages' => $messages,
		];
	}

	/**
	 * @return array<string,string>|null
	 */
	private function loadHeader( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->conversations} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'role'       => (string) $row['role'],
			'status'     => (string) $row['status'],
			'content'    => (string) $row['content'],
			'tool_calls' => $row['tool_calls'] ? ( json_decode( (string) $row['tool_calls'], true ) ?: [] ) : [],
			'error'      => $row['error']      ? ( json_decode( (string) $row['error'],      true ) ?: null ) : null,
			'created_at' => (string) $row['created_at'],
		];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ConversationStoreTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/ConversationStore.php tests/phpunit/Chat/ConversationStoreTest.php
git commit -m "$(cat <<'EOF'
feat(chat): ConversationStore.getOrCreate scoped per (post, user)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: ConversationStore — message CRUD

**Files:**
- Modify: `src/Chat/ConversationStore.php`
- Modify: `tests/phpunit/Chat/ConversationStoreTest.php`

- [ ] **Step 1: Write failing tests for message CRUD**

Append to `tests/phpunit/Chat/ConversationStoreTest.php` (inside the class):

```php
public function test_append_user_message_writes_row(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->appendUserMessage( $conv['id'], 'Hello world' );
	$loaded = $this->store->findById( $conv['id'] );
	$this->assertCount( 1, $loaded['messages'] );
	$this->assertSame( 'user',    $loaded['messages'][0]['role'] );
	$this->assertSame( 'complete', $loaded['messages'][0]['status'] );
	$this->assertSame( 'Hello world', $loaded['messages'][0]['content'] );
}

public function test_start_assistant_turn_returns_streaming_row(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$loaded = $this->store->findById( $conv['id'] );
	$this->assertSame( 'assistant', $loaded['messages'][0]['role'] );
	$this->assertSame( 'streaming', $loaded['messages'][0]['status'] );
	$this->assertSame( '',          $loaded['messages'][0]['content'] );
}

public function test_append_assistant_delta_is_cumulative(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$this->store->appendAssistantDelta( $id, 'Hel' );
	$this->store->appendAssistantDelta( $id, 'lo!' );
	$msg = $this->store->getMessage( $id );
	$this->assertSame( 'Hello!', $msg['content'] );
}

public function test_record_tool_call_appends_to_json(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$this->store->recordToolCall( $id, [ 'tool' => 'insert_block', 'input' => [ 'foo' => 'bar' ] ] );
	$this->store->recordToolCall( $id, [ 'tool' => 'delete_block', 'input' => [ 'client_id' => 'x' ] ] );
	$msg = $this->store->getMessage( $id );
	$this->assertCount( 2, $msg['tool_calls'] );
	$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );
}

public function test_complete_marks_status(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$this->store->complete( $id );
	$this->assertSame( 'complete', $this->store->getMessage( $id )['status'] );
}

public function test_fail_marks_error_and_status(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$this->store->fail( $id, 'rate_limit', 'Slow down' );
	$msg = $this->store->getMessage( $id );
	$this->assertSame( 'error', $msg['status'] );
	$this->assertSame( 'rate_limit', $msg['error']['code'] );
}

public function test_abort_sets_status_and_is_visible_to_polling(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$id   = $this->store->startAssistantTurn( $conv['id'] );
	$this->store->abort( $id );
	$this->assertSame( 'aborted', $this->store->getMessage( $id )['status'] );
	$this->assertTrue( $this->store->isAborted( $id ) );
}

public function test_clear_deletes_all_messages_for_conversation(): void {
	$conv = $this->store->getOrCreate( 1, 1 );
	$this->store->appendUserMessage( $conv['id'], 'a' );
	$this->store->appendUserMessage( $conv['id'], 'b' );
	$this->store->clear( $conv['id'] );
	$this->assertSame( [], $this->store->findById( $conv['id'] )['messages'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ConversationStoreTest`
Expected: FAIL — methods do not exist.

- [ ] **Step 3: Implement the methods**

Append to `src/Chat/ConversationStore.php` (inside the class, after `hydrate()`):

```php
public function appendUserMessage( int $conversation_id, string $content ): int {
	return $this->insertMessage( $conversation_id, 'user', 'complete', $content );
}

public function startAssistantTurn( int $conversation_id ): int {
	return $this->insertMessage( $conversation_id, 'assistant', 'streaming', '' );
}

public function appendAssistantDelta( int $message_id, string $delta ): void {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$this->messages} SET content = CONCAT(content, %s), updated_at = %s WHERE id = %d",
			$delta,
			current_time( 'mysql', true ),
			$message_id
		)
	);
}

/**
 * @param array<string,mixed> $call
 */
public function recordToolCall( int $message_id, array $call ): void {
	global $wpdb;
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT tool_calls FROM {$this->messages} WHERE id = %d", $message_id ), ARRAY_A );
	$calls = $row && $row['tool_calls'] ? ( json_decode( (string) $row['tool_calls'], true ) ?: [] ) : [];
	$calls[] = $call;
	$wpdb->update(
		$this->messages,
		[ 'tool_calls' => wp_json_encode( $calls ), 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $message_id ]
	);
}

public function complete( int $message_id ): void {
	global $wpdb;
	$wpdb->update(
		$this->messages,
		[ 'status' => 'complete', 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $message_id ]
	);
}

public function fail( int $message_id, string $code, string $message ): void {
	global $wpdb;
	$wpdb->update(
		$this->messages,
		[
			'status'     => 'error',
			'error'      => wp_json_encode( [ 'code' => $code, 'message' => $message ] ),
			'updated_at' => current_time( 'mysql', true ),
		],
		[ 'id' => $message_id ]
	);
}

public function abort( int $message_id ): void {
	global $wpdb;
	$wpdb->update(
		$this->messages,
		[ 'status' => 'aborted', 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $message_id ]
	);
}

public function isAborted( int $message_id ): bool {
	global $wpdb;
	$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->messages} WHERE id = %d", $message_id ) );
	return 'aborted' === $status;
}

/**
 * @return array<string,mixed>|null
 */
public function getMessage( int $message_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->messages} WHERE id = %d", $message_id ), ARRAY_A );
	return $row ? $this->hydrate( $row ) : null;
}

public function clear( int $conversation_id ): void {
	global $wpdb;
	$wpdb->delete( $this->messages, [ 'conversation_id' => $conversation_id ] );
}

private function insertMessage( int $conversation_id, string $role, string $status, string $content ): int {
	global $wpdb;
	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$this->messages,
		[
			'conversation_id' => $conversation_id,
			'role'            => $role,
			'status'          => $status,
			'content'         => $content,
			'created_at'      => $now,
			'updated_at'      => $now,
		]
	);
	return (int) $wpdb->insert_id;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ConversationStoreTest`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/ConversationStore.php tests/phpunit/Chat/ConversationStoreTest.php
git commit -m "$(cat <<'EOF'
feat(chat): ConversationStore message CRUD (append/delta/tool-call/complete/fail/abort)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: VirtualTree — in-memory block tree mutations

**Files:**
- Create: `src/Chat/VirtualTree.php`
- Test: `tests/phpunit/Chat/VirtualTreeTest.php`

The VirtualTree is the server-side mirror of the post's blocks across one turn. The model emits clientId references; the tree applies inserts/updates/deletes/moves in memory and assigns deterministic new clientIds for `insert_block`.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Chat/VirtualTreeTest.php`:

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\VirtualTree;

class VirtualTreeTest extends \WP_UnitTestCase {
	public function test_loads_initial_tree_with_client_ids(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertNotNull( $tree->find( 'a' ) );
	}

	public function test_insert_at_end_appends_and_returns_new_client_id(): void {
		$tree = new VirtualTree( [] );
		$cid  = $tree->insert( null, 'end', [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'New' ], 'innerBlocks' => [] ] );
		$this->assertNotEmpty( $cid );
		$node = $tree->find( $cid );
		$this->assertSame( 'core/paragraph', $node['name'] );
	}

	public function test_insert_after_existing_block(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$cid = $tree->insert( 'a', 'after', [ 'name' => 'core/heading', 'attributes' => [ 'content' => 'H' ], 'innerBlocks' => [] ] );
		$order = array_column( $tree->toArray(), 'clientId' );
		$this->assertSame( [ 'a', $cid, 'b' ], $order );
	}

	public function test_update_merges_attributes(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Old', 'dropCap' => true ], 'innerBlocks' => [] ],
		] );
		$tree->update( 'a', [ 'content' => 'New' ], null );
		$node = $tree->find( 'a' );
		$this->assertSame( 'New', $node['attributes']['content'] );
		$this->assertTrue( $node['attributes']['dropCap'] );
	}

	public function test_delete_removes_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$tree->delete( 'a' );
		$this->assertNull( $tree->find( 'a' ) );
	}

	public function test_move_reorders_blocks(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
			[ 'clientId' => 'c', 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$tree->move( 'c', 'a', 'before' );
		$this->assertSame( [ 'c', 'a', 'b' ], array_column( $tree->toArray(), 'clientId' ) );
	}

	public function test_find_returns_null_for_missing_client_id(): void {
		$tree = new VirtualTree( [] );
		$this->assertNull( $tree->find( 'missing' ) );
	}

	public function test_skeleton_with_focus_emits_full_content_near_target(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'long ', 200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'b', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'mid ',  200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'c', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'far ',  200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'd', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'farr ', 200 ) ], 'innerBlocks' => [] ],
			[ 'clientId' => 'e', 'name' => 'core/paragraph', 'attributes' => [ 'content' => str_repeat( 'farrr ',200 ) ], 'innerBlocks' => [] ],
		] );
		// focus on 'a', window=3 → a,b,c full; d,e truncated
		$skeleton = $tree->skeleton( 'a', 3 );
		$this->assertFalse( ! empty( $skeleton[0]['truncated'] ) );
		$this->assertFalse( ! empty( $skeleton[1]['truncated'] ) );
		$this->assertFalse( ! empty( $skeleton[2]['truncated'] ) );
		$this->assertTrue( ! empty( $skeleton[3]['truncated'] ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter VirtualTreeTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement VirtualTree**

Create `src/Chat/VirtualTree.php`:

```php
<?php
/**
 * In-memory mutable block tree used during a chat turn.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the tree of blocks for one turn. Tracks clientIds; generates new ones for inserts.
 * Inner-block mutations are addressable by clientId — the methods recurse.
 */
final class VirtualTree {
	/** @var array<int,array<string,mixed>> */
	private array $tree;

	private int $counter = 0;

	/**
	 * @param array<int,array<string,mixed>> $initial Blocks as { clientId, name, attributes, innerBlocks }.
	 */
	public function __construct( array $initial ) {
		$this->tree = $this->normalize( $initial );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function toArray(): array {
		return $this->tree;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( string $clientId ): ?array {
		return $this->locateRef( $this->tree, $clientId )['node'] ?? null;
	}

	/**
	 * Insert a block. $afterClientId may be null when $position is "start" or "end".
	 *
	 * @param array{name:string, attributes:array<string,mixed>, innerBlocks?:array<int,array<string,mixed>>} $block
	 */
	public function insert( ?string $afterClientId, string $position, array $block ): string {
		$cid             = $this->mintClientId();
		$normalized      = $this->normalizeNode( $block, $cid );
		if ( 'start' === $position ) {
			array_unshift( $this->tree, $normalized );
			return $cid;
		}
		if ( 'end' === $position || null === $afterClientId ) {
			$this->tree[] = $normalized;
			return $cid;
		}
		$located = $this->locateRef( $this->tree, $afterClientId );
		if ( null === $located ) {
			$this->tree[] = $normalized;
			return $cid;
		}
		$siblings =& $located['siblings'];
		$index    = $located['index'];
		$insertAt = 'before' === $position ? $index : $index + 1;
		array_splice( $siblings, $insertAt, 0, [ $normalized ] );
		return $cid;
	}

	/**
	 * @param array<string,mixed>|null      $attrs
	 * @param string|null                   $content Convenience: written into $attrs['content'].
	 */
	public function update( string $clientId, ?array $attrs, ?string $content ): bool {
		$located = $this->locateRef( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}
		$siblings =& $located['siblings'];
		$index    = $located['index'];
		if ( is_array( $attrs ) ) {
			$siblings[ $index ]['attributes'] = array_merge( $siblings[ $index ]['attributes'], $attrs );
		}
		if ( null !== $content ) {
			$siblings[ $index ]['attributes']['content'] = $content;
		}
		return true;
	}

	public function delete( string $clientId ): bool {
		$located = $this->locateRef( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}
		$siblings =& $located['siblings'];
		array_splice( $siblings, $located['index'], 1 );
		return true;
	}

	public function move( string $clientId, string $targetClientId, string $position ): bool {
		$located = $this->locateRef( $this->tree, $clientId );
		if ( null === $located ) {
			return false;
		}
		$siblings =& $located['siblings'];
		$node     = $siblings[ $located['index'] ];
		array_splice( $siblings, $located['index'], 1 );

		$targetLoc = $this->locateRef( $this->tree, $targetClientId );
		if ( null === $targetLoc ) {
			// Target gone — restore at original position.
			array_splice( $siblings, $located['index'], 0, [ $node ] );
			return false;
		}
		$targetSiblings =& $targetLoc['siblings'];
		$insertAt        = 'before' === $position ? $targetLoc['index'] : $targetLoc['index'] + 1;
		array_splice( $targetSiblings, $insertAt, 0, [ $node ] );
		return true;
	}

	/**
	 * Returns a skeleton representation suitable for sending to the model.
	 * Blocks within $window positions of $focusClientId (top-level distance) get full content;
	 * others get truncated content and `truncated: true`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function skeleton( ?string $focusClientId, int $window = 3 ): array {
		$focusIndex = null;
		if ( null !== $focusClientId ) {
			foreach ( $this->tree as $i => $node ) {
				if ( $node['clientId'] === $focusClientId ) {
					$focusIndex = $i;
					break;
				}
			}
		}
		$out = [];
		foreach ( $this->tree as $i => $node ) {
			$near       = ( null === $focusIndex ) || abs( $i - $focusIndex ) <= $window;
			$out[]      = $this->skeletonNode( $node, $near );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array<string,mixed>
	 */
	private function skeletonNode( array $node, bool $full ): array {
		$content = (string) ( $node['attributes']['content'] ?? '' );
		$entry   = [
			'clientId'    => $node['clientId'],
			'name'        => $node['name'],
			'attributes'  => $node['attributes'],
		];
		if ( ! $full && strlen( $content ) > 120 ) {
			$entry['attributes']           = $node['attributes'];
			$entry['attributes']['content'] = substr( $content, 0, 120 );
			$entry['truncated']             = true;
		}
		if ( ! empty( $node['innerBlocks'] ) ) {
			$entry['innerBlocks'] = array_map(
				fn( $child ) => $this->skeletonNode( $child, $full ),
				$node['innerBlocks']
			);
		}
		return $entry;
	}

	/**
	 * @param array<int,array<string,mixed>> $tree
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( array $tree ): array {
		return array_map( fn( $n ) => $this->normalizeNode( $n, $n['clientId'] ?? $this->mintClientId() ), $tree );
	}

	/**
	 * @param array<string,mixed> $node
	 * @return array<string,mixed>
	 */
	private function normalizeNode( array $node, string $clientId ): array {
		return [
			'clientId'    => $clientId,
			'name'        => (string) ( $node['name'] ?? '' ),
			'attributes'  => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
			'innerBlocks' => array_map(
				fn( $child ) => $this->normalizeNode( $child, $child['clientId'] ?? $this->mintClientId() ),
				is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : []
			),
		];
	}

	private function mintClientId(): string {
		$this->counter++;
		return 'srv-' . bin2hex( random_bytes( 4 ) ) . '-' . $this->counter;
	}

	/**
	 * Locate a node and return a reference to its parent siblings array + index.
	 *
	 * @param array<int,array<string,mixed>> $tree
	 * @return array{siblings:array<int,array<string,mixed>>, index:int, node:array<string,mixed>}|null
	 */
	private function &locateRef( array &$tree, string $clientId ): ?array {
		$null = null; // for returning by reference
		foreach ( $tree as $i => &$node ) {
			if ( $node['clientId'] === $clientId ) {
				$ref = [ 'siblings' => &$tree, 'index' => $i, 'node' => $node ];
				return $ref;
			}
			if ( ! empty( $node['innerBlocks'] ) ) {
				$inner =& $node['innerBlocks'];
				$found =& $this->locateRef( $inner, $clientId );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return $null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter VirtualTreeTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/VirtualTree.php tests/phpunit/Chat/VirtualTreeTest.php
git commit -m "$(cat <<'EOF'
feat(chat): VirtualTree for in-memory block mutations across a turn

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Validator gains `validateNode` for single-block checks

**Files:**
- Modify: `src/BlockTree/Validator.php`
- Test: `tests/phpunit/BlockTree/ValidatorTest.php` (extend or create)

- [ ] **Step 1: Write the failing test**

Append to (or create) `tests/phpunit/BlockTree/ValidatorTest.php`:

```php
<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Validator;

class ValidatorTest extends \WP_UnitTestCase {
	public function test_validate_node_returns_no_errors_for_valid_block(): void {
		$schema = [
			'core/paragraph' => [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ], 'allowsInnerBlocks' => false ],
		];
		$errors = ( new Validator( $schema ) )->validateNode(
			[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'hi' ], 'innerBlocks' => [] ]
		);
		$this->assertSame( [], $errors );
	}

	public function test_validate_node_rejects_unknown_block(): void {
		$schema = [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ];
		$errors = ( new Validator( $schema ) )->validateNode(
			[ 'name' => 'core/nope', 'attributes' => [], 'innerBlocks' => [] ]
		);
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'core/nope', $errors[0] );
	}

	public function test_validate_node_rejects_inner_when_disallowed(): void {
		$schema = [
			'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'attributes' => [], 'allowsInnerBlocks' => false ],
		];
		$errors = ( new Validator( $schema ) )->validateNode( [
			'name'        => 'core/paragraph',
			'attributes'  => [],
			'innerBlocks' => [ [ 'name' => 'core/heading', 'attributes' => [], 'innerBlocks' => [] ] ],
		] );
		$this->assertNotEmpty( $errors );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ValidatorTest`
Expected: FAIL — `validateNode` does not exist.

- [ ] **Step 3: Implement validateNode**

Append to `src/BlockTree/Validator.php` (inside the class, after `validate()`):

```php
/**
 * Validate a single block node (used per tool call in chat).
 *
 * @param array<string,mixed> $node
 * @return string[] Errors; empty means valid.
 */
public function validateNode( array $node ): array {
	return $this->validate( [ $node ] );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ValidatorTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BlockTree/Validator.php tests/phpunit/BlockTree/ValidatorTest.php
git commit -m "$(cat <<'EOF'
feat(blocktree): Validator.validateNode for per-tool-call validation

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Tools — schema definitions + apply against VirtualTree

**Files:**
- Create: `src/Chat/Tools.php`
- Test: `tests/phpunit/Chat/ToolsTest.php`

The `Tools` class owns: (a) the Anthropic tool-definition JSON we send with each Messages request, and (b) the dispatcher that takes a tool name + input, validates it, mutates the VirtualTree, and returns the tool_result payload.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Chat/ToolsTest.php`:

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\BlockTree\Validator;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\VirtualTree;

class ToolsTest extends \WP_UnitTestCase {
	private function tools(): Tools {
		$schema = [
			'core/paragraph' => [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'attributes' => [ 'content' => [ 'type' => 'string' ], 'level' => [ 'type' => 'number' ] ], 'allowsInnerBlocks' => false ],
		];
		return new Tools( $schema, new Validator( $schema ) );
	}

	public function test_definitions_lists_all_five_tools(): void {
		$names = array_column( $this->tools()->definitions(), 'name' );
		$this->assertEqualsCanonicalizing(
			[ 'insert_block', 'update_block', 'delete_block', 'move_block', 'read_block' ],
			$names
		);
	}

	public function test_apply_insert_block_returns_client_id_and_mutates_tree(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'insert_block', [
			'after_client_id' => null,
			'position'        => 'end',
			'block'           => [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hi' ], 'innerBlocks' => [] ],
		] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertNotEmpty( $result['content']['client_id'] );
		$this->assertSame( 'core/paragraph', $tree->toArray()[0]['name'] );
	}

	public function test_apply_insert_block_rejects_invalid_block(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'insert_block', [
			'after_client_id' => null,
			'position'        => 'end',
			'block'           => [ 'name' => 'core/nope', 'attributes' => [], 'innerBlocks' => [] ],
		] );
		$this->assertTrue( $result['is_error'] );
		$this->assertStringContainsString( 'core/nope', (string) $result['content'] );
	}

	public function test_apply_update_block_returns_ok(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Old' ], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'a', 'content' => 'New' ] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertSame( 'New', $tree->find( 'a' )['attributes']['content'] );
	}

	public function test_apply_update_block_for_missing_id_returns_error(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'update_block', [ 'client_id' => 'missing', 'content' => 'x' ] );
		$this->assertTrue( $result['is_error'] );
		$this->assertStringContainsString( 'Block not found', (string) $result['content'] );
	}

	public function test_apply_read_block_returns_full_node(): void {
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Full text' ], 'innerBlocks' => [] ],
		] );
		$result = $this->tools()->apply( $tree, 'read_block', [ 'client_id' => 'a' ] );
		$this->assertFalse( $result['is_error'] ?? false );
		$this->assertSame( 'core/paragraph', $result['content']['name'] );
		$this->assertSame( 'Full text',       $result['content']['attributes']['content'] );
	}

	public function test_apply_unknown_tool_returns_error(): void {
		$tree   = new VirtualTree( [] );
		$result = $this->tools()->apply( $tree, 'do_unspeakable_things', [] );
		$this->assertTrue( $result['is_error'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ToolsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement Tools**

Create `src/Chat/Tools.php`:

```php
<?php
/**
 * Tool schema definitions and tool-call dispatcher for chat.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

use PedimentAi\BlockTree\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tools {
	/**
	 * @param array<string,array<string,mixed>> $blockSchema Map of blockName => spec.
	 */
	public function __construct(
		private readonly array $blockSchema,
		private readonly Validator $validator
	) {}

	/**
	 * Anthropic tool definitions sent in the Messages request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions(): array {
		$blockSchema = [
			'type'       => 'object',
			'properties' => [
				'name'        => [ 'type' => 'string', 'enum' => array_keys( $this->blockSchema ) ],
				'attributes'  => [ 'type' => 'object' ],
				'innerBlocks' => [ 'type' => 'array' ],
			],
			'required'   => [ 'name', 'attributes' ],
		];

		return [
			[
				'name'         => 'insert_block',
				'description'  => 'Insert a new block into the post. Use after_client_id+position to place it; use position=end+after_client_id=null to append.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'after_client_id' => [ 'type' => [ 'string', 'null' ] ],
						'position'        => [ 'type' => 'string', 'enum' => [ 'before', 'after', 'start', 'end' ] ],
						'block'           => $blockSchema,
					],
					'required'   => [ 'position', 'block' ],
				],
			],
			[
				'name'         => 'update_block',
				'description'  => 'Update attributes (and/or content) of an existing block. Pass only the keys you want to change.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'client_id' => [ 'type' => 'string' ],
						'attrs'     => [ 'type' => 'object' ],
						'content'   => [ 'type' => 'string' ],
					],
					'required'   => [ 'client_id' ],
				],
			],
			[
				'name'         => 'delete_block',
				'description'  => 'Delete a block by clientId.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [ 'client_id' => [ 'type' => 'string' ] ],
					'required'   => [ 'client_id' ],
				],
			],
			[
				'name'         => 'move_block',
				'description'  => 'Move a block before or after another block.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'client_id'        => [ 'type' => 'string' ],
						'target_client_id' => [ 'type' => 'string' ],
						'position'         => [ 'type' => 'string', 'enum' => [ 'before', 'after' ] ],
					],
					'required'   => [ 'client_id', 'target_client_id', 'position' ],
				],
			],
			[
				'name'         => 'read_block',
				'description'  => 'Read the full contents of a block whose content was truncated in the initial context.',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [ 'client_id' => [ 'type' => 'string' ] ],
					'required'   => [ 'client_id' ],
				],
			],
		];
	}

	/**
	 * Apply a tool call to the virtual tree and return the synthetic tool_result payload.
	 *
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	public function apply( VirtualTree $tree, string $tool, array $input ): array {
		switch ( $tool ) {
			case 'insert_block':
				return $this->applyInsert( $tree, $input );
			case 'update_block':
				return $this->applyUpdate( $tree, $input );
			case 'delete_block':
				return $this->applyDelete( $tree, $input );
			case 'move_block':
				return $this->applyMove( $tree, $input );
			case 'read_block':
				return $this->applyRead( $tree, $input );
		}
		return [ 'content' => "Unknown tool: {$tool}", 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyInsert( VirtualTree $tree, array $input ): array {
		$block = is_array( $input['block'] ?? null ) ? $input['block'] : [];
		$errors = $this->validator->validateNode( [
			'name'        => (string) ( $block['name'] ?? '' ),
			'attributes'  => is_array( $block['attributes']  ?? null ) ? $block['attributes']  : [],
			'innerBlocks' => is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [],
		] );
		if ( ! empty( $errors ) ) {
			return [ 'content' => 'Validation failed: ' . implode( '; ', $errors ), 'is_error' => true ];
		}
		$cid = $tree->insert(
			isset( $input['after_client_id'] ) && is_string( $input['after_client_id'] ) ? $input['after_client_id'] : null,
			(string) ( $input['position'] ?? 'end' ),
			$block
		);
		return [ 'content' => [ 'ok' => true, 'client_id' => $cid ] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyUpdate( VirtualTree $tree, array $input ): array {
		$cid = (string) ( $input['client_id'] ?? '' );
		if ( '' === $cid || null === $tree->find( $cid ) ) {
			return [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
		}
		$attrs   = is_array( $input['attrs'] ?? null ) ? $input['attrs'] : null;
		$content = isset( $input['content'] ) ? (string) $input['content'] : null;
		// Validate the merged node.
		$node      = $tree->find( $cid );
		$mergedAtt = is_array( $attrs ) ? array_merge( $node['attributes'], $attrs ) : $node['attributes'];
		if ( null !== $content ) {
			$mergedAtt['content'] = $content;
		}
		$errors = $this->validator->validateNode( [
			'name'        => $node['name'],
			'attributes'  => $mergedAtt,
			'innerBlocks' => $node['innerBlocks'],
		] );
		if ( ! empty( $errors ) ) {
			return [ 'content' => 'Validation failed: ' . implode( '; ', $errors ), 'is_error' => true ];
		}
		$tree->update( $cid, $attrs, $content );
		return [ 'content' => [ 'ok' => true ] ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyDelete( VirtualTree $tree, array $input ): array {
		$cid = (string) ( $input['client_id'] ?? '' );
		return $tree->delete( $cid )
			? [ 'content' => [ 'ok' => true ] ]
			: [ 'content' => "Block not found: {$cid}", 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyMove( VirtualTree $tree, array $input ): array {
		$ok = $tree->move(
			(string) ( $input['client_id']        ?? '' ),
			(string) ( $input['target_client_id'] ?? '' ),
			(string) ( $input['position']         ?? 'after' )
		);
		return $ok ? [ 'content' => [ 'ok' => true ] ] : [ 'content' => 'Move failed (block not found)', 'is_error' => true ];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{content:mixed, is_error?:bool}
	 */
	private function applyRead( VirtualTree $tree, array $input ): array {
		$cid  = (string) ( $input['client_id'] ?? '' );
		$node = $tree->find( $cid );
		return null === $node
			? [ 'content' => "Block not found: {$cid}", 'is_error' => true ]
			: [ 'content' => [ 'name' => $node['name'], 'attributes' => $node['attributes'], 'innerBlocks' => $node['innerBlocks'] ] ];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ToolsTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/Tools.php tests/phpunit/Chat/ToolsTest.php
git commit -m "$(cat <<'EOF'
feat(chat): Tools schema definitions and VirtualTree dispatcher

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Anthropic Client — streaming method

**Files:**
- Modify: `src/Anthropic/ProviderInterface.php`
- Modify: `src/Anthropic/Client.php`
- Test: `tests/phpunit/Anthropic/ClientStreamTest.php`

The chat path needs Anthropic streaming. We parse the SSE response body chunk-by-chunk and yield events.

`wp_remote_post` buffers the response — we can't stream through it. Use `curl_exec` directly with a write callback when streaming, falling back to a non-streaming `messages()` for callers that don't need it.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Anthropic/ClientStreamTest.php`:

```php
<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\Client;

class ClientStreamTest extends \WP_UnitTestCase {
	public function test_stream_messages_parses_sse_events(): void {
		$sse = implode( '', [
			"event: message_start\n",
			"data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"model\":\"claude-sonnet-4-6\",\"usage\":{\"input_tokens\":10}}}\n\n",
			"event: content_block_start\n",
			"data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
			"event: content_block_delta\n",
			"data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n",
			"event: content_block_delta\n",
			"data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" world\"}}\n\n",
			"event: message_delta\n",
			"data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
			"event: message_stop\n",
			"data: {\"type\":\"message_stop\"}\n\n",
		] );

		$client = new Client( 'k' );
		$events = iterator_to_array( $client->parseSseStream( $sse ) );
		$types  = array_column( $events, 'type' );

		$this->assertContains( 'message_start',         $types );
		$this->assertContains( 'content_block_delta',   $types );
		$this->assertContains( 'message_delta',         $types );
		$this->assertContains( 'message_stop',          $types );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ClientStreamTest`
Expected: FAIL — `parseSseStream` does not exist.

- [ ] **Step 3: Update ProviderInterface**

Edit `src/Anthropic/ProviderInterface.php`. Inside the interface, add:

```php
/**
 * Stream a Messages call. Yields parsed SSE events as { type, ...payload } arrays.
 *
 * @param array<string,mixed> $args Anthropic Messages request body (with stream: true added by the implementation).
 * @return iterable<int,array<string,mixed>>|\WP_Error
 */
public function stream_messages( array $args );
```

- [ ] **Step 4: Implement parseSseStream and stream_messages in Client**

Append to `src/Anthropic/Client.php` (inside the class):

```php
/**
 * @param array<string,mixed> $args
 * @return iterable<int,array<string,mixed>>|\WP_Error
 */
public function stream_messages( array $args ) {
	$args['stream'] = true;

	$ch = curl_init();
	if ( false === $ch ) {
		return new \WP_Error( 'pediment_ai_curl_init', 'curl_init failed' );
	}
	$buffer = '';
	curl_setopt_array( $ch, [
		CURLOPT_URL            => rtrim( $this->baseUrl, '/' ) . '/v1/messages',
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => [
			'x-api-key: ' . $this->apiKey,
			'anthropic-version: ' . self::API_VERSION,
			'content-type: application/json',
			'accept: text/event-stream',
		],
		CURLOPT_POSTFIELDS     => wp_json_encode( $args ),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => $this->timeout,
		CURLOPT_WRITEFUNCTION  => function ( $h, $chunk ) use ( &$buffer ) {
			$buffer .= $chunk;
			return strlen( $chunk );
		},
	] );
	$ok      = curl_exec( $ch );
	$err     = curl_error( $ch );
	$status  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( false === $ok ) {
		return new \WP_Error( 'pediment_ai_curl_failed', $err ?: 'cURL failed' );
	}
	if ( $status < 200 || $status >= 300 ) {
		$body = json_decode( $buffer, true );
		return new \WP_Error(
			'pediment_ai_anthropic_' . $status,
			is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Anthropic API error',
			[ 'status' => $status ]
		);
	}

	return $this->parseSseStream( $buffer );
}

/**
 * Parses an SSE blob into a generator of decoded `data:` events.
 * Public so tests can drive it without a real HTTP call.
 *
 * @param string $sse
 * @return \Generator<int,array<string,mixed>>
 */
public function parseSseStream( string $sse ): \Generator {
	$blocks = preg_split( "/\r?\n\r?\n/", $sse );
	foreach ( (array) $blocks as $block ) {
		$block = trim( $block );
		if ( '' === $block ) {
			continue;
		}
		foreach ( preg_split( "/\r?\n/", $block ) as $line ) {
			if ( str_starts_with( $line, 'data: ' ) ) {
				$payload = substr( $line, 6 );
				$decoded = json_decode( $payload, true );
				if ( is_array( $decoded ) ) {
					yield $decoded;
				}
			}
		}
	}
}
```

> **Note for the implementer:** the test exercises only `parseSseStream`. We do not unit-test the real `stream_messages` HTTP path here — that would require a network double for cURL, which is its own project. We rely on the e2e tests in Task 16 to cover the end-to-end streaming behavior against the Mock provider.

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter ClientStreamTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Anthropic/ProviderInterface.php src/Anthropic/Client.php tests/phpunit/Anthropic/ClientStreamTest.php
git commit -m "$(cat <<'EOF'
feat(anthropic): streaming Messages client via cURL + SSE parser

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: MockProvider — streaming shim

**Files:**
- Modify: `src/Mock/MockProvider.php`
- Create: `src/Mock/fixtures/chat/insert-paragraph.json`
- Create: `src/Mock/fixtures/chat/update-selected.json`
- Test: `tests/phpunit/Mock/MockProviderStreamTest.php`

The mock returns events for two scenarios used in tests:
1. `insert-paragraph` — model emits one `insert_block` then ends.
2. `update-selected` — model emits one `update_block` referencing the selected block then ends.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Mock/MockProviderStreamTest.php`:

```php
<?php
namespace PedimentAi\Tests\Mock;

use PedimentAi\Mock\MockProvider;

class MockProviderStreamTest extends \WP_UnitTestCase {
	public function test_stream_messages_yields_insert_event_for_compose_request(): void {
		$provider = new MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
		$events   = iterator_to_array(
			$provider->stream_messages( [
				'tools'    => [ [ 'name' => 'insert_block' ] ],
				'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Add a paragraph that says hi' ] ] ] ],
			] )
		);
		$types = array_column( $events, 'type' );
		$this->assertContains( 'content_block_start', $types );
		$this->assertContains( 'message_stop',        $types );

		$tools = array_filter( $events, fn( $e ) => ( $e['type'] ?? '' ) === 'content_block_start' && ( $e['content_block']['type'] ?? '' ) === 'tool_use' );
		$this->assertNotEmpty( $tools );
		$first = array_values( $tools )[0];
		$this->assertSame( 'insert_block', $first['content_block']['name'] );
	}

	public function test_stream_messages_yields_update_event_when_selection_present(): void {
		$provider = new MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
		$events   = iterator_to_array(
			$provider->stream_messages( [
				'tools'    => [ [ 'name' => 'update_block' ] ],
				'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Shorten the selected paragraph (selected_block.clientId=abc)' ] ] ] ],
			] )
		);
		$tools = array_filter( $events, fn( $e ) => ( $e['type'] ?? '' ) === 'content_block_start' && ( $e['content_block']['type'] ?? '' ) === 'tool_use' );
		$first = array_values( $tools )[0];
		$this->assertSame( 'update_block', $first['content_block']['name'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter MockProviderStreamTest`
Expected: FAIL — `stream_messages` does not exist.

- [ ] **Step 3: Create fixture files**

Create `src/Mock/fixtures/chat/insert-paragraph.json`:

```json
[
  { "type": "message_start", "message": { "id": "mock_1", "model": "mock", "usage": { "input_tokens": 5 } } },
  { "type": "content_block_start", "index": 0, "content_block": { "type": "text", "text": "" } },
  { "type": "content_block_delta", "index": 0, "delta": { "type": "text_delta", "text": "Adding a paragraph." } },
  { "type": "content_block_stop", "index": 0 },
  { "type": "content_block_start", "index": 1, "content_block": { "type": "tool_use", "id": "tu_1", "name": "insert_block", "input": {} } },
  { "type": "content_block_delta", "index": 1, "delta": { "type": "input_json_delta", "partial_json": "{\"after_client_id\":null,\"position\":\"end\",\"block\":{\"name\":\"core/paragraph\",\"attributes\":{\"content\":\"Hello from mock.\"},\"innerBlocks\":[]}}" } },
  { "type": "content_block_stop", "index": 1 },
  { "type": "message_delta", "delta": { "stop_reason": "end_turn" }, "usage": { "output_tokens": 12 } },
  { "type": "message_stop" }
]
```

Create `src/Mock/fixtures/chat/update-selected.json`:

```json
[
  { "type": "message_start", "message": { "id": "mock_2", "model": "mock", "usage": { "input_tokens": 5 } } },
  { "type": "content_block_start", "index": 0, "content_block": { "type": "text", "text": "" } },
  { "type": "content_block_delta", "index": 0, "delta": { "type": "text_delta", "text": "Shortening." } },
  { "type": "content_block_stop", "index": 0 },
  { "type": "content_block_start", "index": 1, "content_block": { "type": "tool_use", "id": "tu_2", "name": "update_block", "input": {} } },
  { "type": "content_block_delta", "index": 1, "delta": { "type": "input_json_delta", "partial_json": "{\"client_id\":\"abc\",\"content\":\"Short.\"}" } },
  { "type": "content_block_stop", "index": 1 },
  { "type": "message_delta", "delta": { "stop_reason": "end_turn" }, "usage": { "output_tokens": 5 } },
  { "type": "message_stop" }
]
```

- [ ] **Step 4: Implement stream_messages on MockProvider**

Append to `src/Mock/MockProvider.php` (inside the class):

```php
/**
 * @param array<string,mixed> $args
 * @return \Generator<int,array<string,mixed>>|\WP_Error
 */
public function stream_messages( array $args ) {
	$text     = $this->concatenateUserText( $args );
	$fixture  = $this->resolveChatFixture( $text );
	$path     = $this->fixturesDir . '/chat/' . $fixture . '.json';
	if ( ! file_exists( $path ) ) {
		return new \WP_Error( 'pediment_ai_mock_missing', "Missing chat fixture: {$fixture}" );
	}
	$events = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $events ) ) {
		return new \WP_Error( 'pediment_ai_mock_invalid', "Invalid chat fixture: {$fixture}" );
	}
	return ( static function () use ( $events ) {
		foreach ( $events as $e ) {
			yield $e;
		}
	} )();
}

private function resolveChatFixture( string $text ): string {
	if ( false !== stripos( $text, 'selected_block.clientId' ) || false !== stripos( $text, 'selected paragraph' ) ) {
		return 'update-selected';
	}
	return 'insert-paragraph';
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter MockProviderStreamTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Mock/MockProvider.php src/Mock/fixtures/chat/ tests/phpunit/Mock/MockProviderStreamTest.php
git commit -m "$(cat <<'EOF'
feat(mock): stream_messages shim with chat fixtures for tests

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: PromptBuilder — system prompt + context messages

**Files:**
- Create: `src/Chat/PromptBuilder.php`
- Test: `tests/phpunit/Chat/PromptBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Chat/PromptBuilderTest.php`:

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\VirtualTree;

class PromptBuilderTest extends \WP_UnitTestCase {
	public function test_system_prompt_lists_block_names_and_tool_conventions(): void {
		$pb = new PromptBuilder( [
			'core/paragraph' => [ 'description' => 'A paragraph.', 'attributes' => [], 'allowsInnerBlocks' => false ],
			'core/heading'   => [ 'description' => 'A heading.',   'attributes' => [], 'allowsInnerBlocks' => false ],
		] );
		$sys = $pb->systemPrompt();
		$this->assertStringContainsString( 'core/paragraph', $sys );
		$this->assertStringContainsString( 'core/heading',   $sys );
		$this->assertStringContainsString( 'insert_block',   $sys );
	}

	public function test_context_message_includes_selection_chip(): void {
		$pb = new PromptBuilder( [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ] );
		$tree = new VirtualTree( [
			[ 'clientId' => 'a', 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Selected.' ], 'innerBlocks' => [] ],
		] );
		$msg = $pb->contextMessage( $tree, 'a' );
		$this->assertStringContainsString( '"selected_block"', $msg );
		$this->assertStringContainsString( '"clientId":"a"',   $msg );
	}

	public function test_history_slice_keeps_last_n_turns(): void {
		$pb = new PromptBuilder( [] );
		$history = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$history[] = [ 'role' => 'user',      'content' => "u{$i}", 'tool_calls' => [] ];
			$history[] = [ 'role' => 'assistant', 'content' => "a{$i}", 'tool_calls' => [] ];
		}
		$sliced = $pb->historyToMessages( $history, 20 );
		$this->assertCount( 20, $sliced );
		$this->assertSame( 'u20', $sliced[0]['content'][0]['text'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter PromptBuilderTest`
Expected: FAIL.

- [ ] **Step 3: Implement PromptBuilder**

Create `src/Chat/PromptBuilder.php`:

```php
<?php
/**
 * Builds the system prompt and context messages for a chat turn.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PromptBuilder {
	/**
	 * @param array<string,array<string,mixed>> $blockSchema
	 */
	public function __construct( private readonly array $blockSchema ) {}

	public function systemPrompt(): string {
		$lines   = [];
		$lines[] = 'You are an AI assistant inside the WordPress block editor. The user is composing or editing a post and is chatting with you in a sidebar.';
		$lines[] = 'When the user asks you to change the post, call the appropriate tool: insert_block, update_block, delete_block, move_block. Use read_block to fetch the full content of a block whose content is shown truncated in your initial context.';
		$lines[] = 'Mutation tool calls are applied at the end of your turn — you do not see the post change between calls. The synthetic tool_result you receive for inserts contains the new client_id; use it for subsequent calls in the same turn that reference the inserted block.';
		$lines[] = 'Write naturally and concisely in your prose. Do not over-explain. Do not apologize. If you are not changing the post, simply answer the question.';
		$lines[] = '';
		$lines[] = 'Available blocks (use these — do not invent block names):';
		foreach ( $this->blockSchema as $name => $info ) {
			$description = isset( $info['description'] ) ? (string) $info['description'] : '';
			$lines[]     = '' !== $description ? "- {$name} — {$description}" : "- {$name}";
		}
		return implode( "\n", $lines );
	}

	/**
	 * Returns a single user-content text part representing the current tree + selection.
	 */
	public function contextMessage( VirtualTree $tree, ?string $selectedClientId ): string {
		$payload = [
			'block_tree'     => $tree->skeleton( $selectedClientId, 3 ),
			'selected_block' => null === $selectedClientId ? null : $tree->find( $selectedClientId ),
		];
		return "Current post state:\n" . wp_json_encode( $payload );
	}

	/**
	 * Convert stored history rows into Anthropic Messages format. Keeps the last $maxTurns
	 * messages (each row is one message; pairs are user+assistant).
	 *
	 * @param array<int,array<string,mixed>> $history
	 * @return array<int,array<string,mixed>>
	 */
	public function historyToMessages( array $history, int $maxTurns = 20 ): array {
		$sliced = array_slice( $history, max( 0, count( $history ) - $maxTurns ) );
		$out    = [];
		foreach ( $sliced as $msg ) {
			$role = (string) ( $msg['role'] ?? '' );
			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}
			$out[] = [
				'role'    => $role,
				'content' => [ [ 'type' => 'text', 'text' => (string) ( $msg['content'] ?? '' ) ] ],
			];
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter PromptBuilderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/PromptBuilder.php tests/phpunit/Chat/PromptBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(chat): PromptBuilder for system prompt, tree context, and history slicing

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: TurnRunner — iterative tool-use loop against the mock

**Files:**
- Create: `src/Chat/TurnRunner.php`
- Test: `tests/phpunit/Chat/TurnRunnerTest.php`

This is the heart of the server side: read events from `stream_messages`, accumulate text deltas to the DB row, process `tool_use` blocks against the VirtualTree, emit synthetic `tool_result` blocks, and loop until `stop_reason: end_turn`.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Chat/TurnRunnerTest.php`:

```php
<?php
namespace PedimentAi\Tests\Chat;

use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\TurnRunner;
use PedimentAi\Chat\VirtualTree;
use PedimentAi\BlockTree\Validator;
use PedimentAi\Mock\MockProvider;

class TurnRunnerTest extends \WP_UnitTestCase {
	private ConversationStore $store;
	private Tools $tools;
	private PromptBuilder $prompts;
	private MockProvider $provider;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );

		$schema         = [ 'core/paragraph' => [ 'attributes' => [], 'allowsInnerBlocks' => false ] ];
		$this->store    = new ConversationStore();
		$this->tools    = new Tools( $schema, new Validator( $schema ) );
		$this->prompts  = new PromptBuilder( $schema );
		$this->provider = new MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
	}

	public function test_run_records_text_delta_and_one_tool_call(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $this->provider, 'claude-sonnet-4-6' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [ [ 'role' => 'user', 'content' => 'Add a paragraph that says hi' ] ],
			selectedId:    null,
			currentUserMsg: 'Add a paragraph that says hi'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'complete', $msg['status'] );
		$this->assertStringContainsString( 'Adding a paragraph', $msg['content'] );
		$this->assertCount( 1, $msg['tool_calls'] );
		$this->assertSame( 'insert_block', $msg['tool_calls'][0]['tool'] );
	}

	public function test_run_records_failure_on_provider_error(): void {
		$conv     = $this->store->getOrCreate( 1, 1 );
		$turn_id  = $this->store->startAssistantTurn( $conv['id'] );

		$broken = new class implements \PedimentAi\Anthropic\ProviderInterface {
			public function messages( array $args ) { return new \WP_Error( 'down', 'Down' ); }
			public function stream_messages( array $args ) { return new \WP_Error( 'down', 'Down' ); }
		};
		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $broken, 'mock' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [],
			selectedId:    null,
			currentUserMsg: 'go'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'error', $msg['status'] );
		$this->assertSame( 'down',  $msg['error']['code'] );
	}

	public function test_run_stops_immediately_when_turn_marked_aborted(): void {
		$conv    = $this->store->getOrCreate( 1, 1 );
		$turn_id = $this->store->startAssistantTurn( $conv['id'] );
		$this->store->abort( $turn_id );

		$runner = new TurnRunner( $this->store, $this->tools, $this->prompts, $this->provider, 'mock' );
		$runner->run(
			turn_id:       $turn_id,
			tree:          new VirtualTree( [] ),
			history:       [],
			selectedId:    null,
			currentUserMsg: 'Add a paragraph that says hi'
		);

		$msg = $this->store->getMessage( $turn_id );
		$this->assertSame( 'aborted', $msg['status'] );
		// No tool calls recorded because we bailed before processing events.
		$this->assertSame( [], $msg['tool_calls'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter TurnRunnerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement TurnRunner**

Create `src/Chat/TurnRunner.php`:

```php
<?php
/**
 * Orchestrates the Anthropic iterative tool-use loop for one chat turn.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Chat;

use PedimentAi\Anthropic\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TurnRunner {
	private const MAX_ITERATIONS = 8;
	private const MAX_TOKENS     = 4096;

	public function __construct(
		private readonly ConversationStore $store,
		private readonly Tools $tools,
		private readonly PromptBuilder $prompts,
		private readonly ProviderInterface $provider,
		private readonly string $model
	) {}

	/**
	 * @param array<int,array<string,mixed>> $history Prior conversation messages (role+content).
	 */
	public function run(
		int $turn_id,
		VirtualTree $tree,
		array $history,
		?string $selectedId,
		string $currentUserMsg
	): void {
		if ( $this->store->isAborted( $turn_id ) ) {
			return;
		}

		$messages = $this->prompts->historyToMessages( $history, 20 );
		// The most recent user message — separately, so we can prepend tree context to it.
		$messages[] = [
			'role'    => 'user',
			'content' => [
				[ 'type' => 'text', 'text' => $this->prompts->contextMessage( $tree, $selectedId ) ],
				[ 'type' => 'text', 'text' => $currentUserMsg ],
			],
		];

		for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
			if ( $this->store->isAborted( $turn_id ) ) {
				return;
			}

			$result = $this->provider->stream_messages( [
				'model'      => $this->model,
				'max_tokens' => self::MAX_TOKENS,
				'system'     => $this->prompts->systemPrompt(),
				'tools'      => $this->tools->definitions(),
				'messages'   => $messages,
			] );

			if ( is_wp_error( $result ) ) {
				$this->store->fail( $turn_id, $result->get_error_code(), $result->get_error_message() );
				return;
			}

			$assistantContent = [];
			$toolResults      = [];
			$stop_reason      = null;
			$current_tu       = null;

			foreach ( $result as $event ) {
				if ( $this->store->isAborted( $turn_id ) ) {
					return;
				}
				$type = (string) ( $event['type'] ?? '' );

				if ( 'content_block_start' === $type && 'tool_use' === ( $event['content_block']['type'] ?? '' ) ) {
					$current_tu = [
						'type'  => 'tool_use',
						'id'    => (string) ( $event['content_block']['id']   ?? '' ),
						'name'  => (string) ( $event['content_block']['name'] ?? '' ),
						'input' => '',
					];
					continue;
				}
				if ( 'content_block_delta' === $type ) {
					$delta = $event['delta'] ?? [];
					if ( 'text_delta' === ( $delta['type'] ?? '' ) ) {
						$text = (string) ( $delta['text'] ?? '' );
						$this->store->appendAssistantDelta( $turn_id, $text );
						$assistantContent[] = [ 'type' => 'text', 'text' => $text ];
					} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) && null !== $current_tu ) {
						$current_tu['input'] .= (string) ( $delta['partial_json'] ?? '' );
					}
					continue;
				}
				if ( 'content_block_stop' === $type && null !== $current_tu ) {
					$tu_input        = json_decode( $current_tu['input'], true );
					$tu_input        = is_array( $tu_input ) ? $tu_input : [];
					$tool_result     = $this->tools->apply( $tree, $current_tu['name'], $tu_input );
					$this->store->recordToolCall( $turn_id, [
						'tool'  => $current_tu['name'],
						'input' => $tu_input,
						'output' => $tool_result['content'] ?? null,
						'is_error' => ! empty( $tool_result['is_error'] ),
					] );
					$assistantContent[] = [
						'type'  => 'tool_use',
						'id'    => $current_tu['id'],
						'name'  => $current_tu['name'],
						'input' => $tu_input,
					];
					$toolResults[] = [
						'type'        => 'tool_result',
						'tool_use_id' => $current_tu['id'],
						'content'     => is_string( $tool_result['content'] ) ? $tool_result['content'] : wp_json_encode( $tool_result['content'] ),
						'is_error'    => ! empty( $tool_result['is_error'] ),
					];
					$current_tu = null;
					continue;
				}
				if ( 'message_delta' === $type ) {
					$stop_reason = (string) ( $event['delta']['stop_reason'] ?? '' );
				}
			}

			if ( 'end_turn' === $stop_reason || '' === $stop_reason || null === $stop_reason ) {
				$this->store->complete( $turn_id );
				return;
			}

			// Continue the loop: append assistant turn + tool_results, then call again.
			$messages[] = [ 'role' => 'assistant', 'content' => $assistantContent ];
			$messages[] = [ 'role' => 'user',      'content' => $toolResults ];
		}

		// Iteration cap.
		$this->store->fail( $turn_id, 'iteration_limit', 'Reached maximum tool-use iterations.' );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter TurnRunnerTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Chat/TurnRunner.php tests/phpunit/Chat/TurnRunnerTest.php
git commit -m "$(cat <<'EOF'
feat(chat): TurnRunner iterative tool-use loop with abort polling

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: ChatController — REST routes

**Files:**
- Create: `src/Rest/ChatController.php`
- Test: `tests/phpunit/Rest/ChatControllerTest.php`

The controller wires the REST surface but does NOT run TurnRunner synchronously inside `POST /chat/turns` during tests — for tests we rely on a separate trigger we can call from the test. In production the controller calls TurnRunner directly after `fastcgi_finish_request()` (Task 12). For testability, we extract a `processTurn(int $turn_id)` method on the controller that tests can call.

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Rest/ChatControllerTest.php`:

```php
<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Chat\ConversationStore;

class ChatControllerTest extends \WP_UnitTestCase {
	private \WP_REST_Server $server;
	private int $post_id;

	public function setUp(): void {
		parent::setUp();
		\pediment_ai_install_tables();
		global $wpdb;
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_conversations" );
		$wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_chat_messages" );

		// Route registration depends on the global REST server existing already.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		$this->post_id = $this->factory->post->create( [ 'post_author' => $user_id, 'post_status' => 'draft' ] );

		// Force the mock provider regardless of constants.
		add_filter( 'pediment_ai_provider', static fn() => new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' ) );
	}

	public function test_get_conversation_creates_on_first_call(): void {
		$req = new \WP_REST_Request( 'GET', '/pediment-ai/v1/chat/conversations' );
		$req->set_param( 'post_id', $this->post_id );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertGreaterThan( 0, $res->get_data()['id'] );
		$this->assertSame( [], $res->get_data()['messages'] );
	}

	public function test_post_turn_returns_202_and_persists_user_message(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		$req  = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'message', 'Hi' );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 202, $res->get_status() );
		$this->assertGreaterThan( 0, $res->get_data()['turn_id'] );

		$loaded = ( new ConversationStore() )->findById( $conv['id'] );
		$this->assertCount( 2, $loaded['messages'] ); // user + streaming-assistant placeholder
		$this->assertSame( 'user',      $loaded['messages'][0]['role'] );
		$this->assertSame( 'assistant', $loaded['messages'][1]['role'] );
		$this->assertSame( 'streaming', $loaded['messages'][1]['status'] );
	}

	public function test_get_turn_returns_turn_state(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		$req  = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'message', 'Add a paragraph that says hi' );
		$post_res = $this->server->dispatch( $req );
		$turn_id  = $post_res->get_data()['turn_id'];

		$get = new \WP_REST_Request( 'GET', "/pediment-ai/v1/chat/turns/{$turn_id}" );
		$res = $this->server->dispatch( $get );
		$this->assertSame( 200, $res->get_status() );
		$this->assertContains( $res->get_data()['status'], [ 'streaming', 'complete' ] );
	}

	public function test_delete_turn_marks_aborted(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		$req  = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $this->post_id );
		$req->set_param( 'message', 'x' );
		$turn_id = $this->server->dispatch( $req )->get_data()['turn_id'];

		$del = new \WP_REST_Request( 'DELETE', "/pediment-ai/v1/chat/turns/{$turn_id}" );
		$res = $this->server->dispatch( $del );
		$this->assertSame( 204, $res->get_status() );
		$this->assertSame( 'aborted', ( new ConversationStore() )->getMessage( $turn_id )['status'] );
	}

	public function test_delete_conversation_clears_messages(): void {
		$conv = ( new ConversationStore() )->getOrCreate( $this->post_id, get_current_user_id() );
		( new ConversationStore() )->appendUserMessage( $conv['id'], 'a' );

		$del = new \WP_REST_Request( 'DELETE', "/pediment-ai/v1/chat/conversations/{$conv['id']}" );
		$res = $this->server->dispatch( $del );
		$this->assertSame( 204, $res->get_status() );
		$this->assertSame( [], ( new ConversationStore() )->findById( $conv['id'] )['messages'] );
	}

	public function test_post_turn_rejects_for_post_user_cannot_edit(): void {
		$other = $this->factory->post->create( [ 'post_status' => 'draft', 'post_author' => 999 ] );
		// Drop to a user who cannot edit others' posts.
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );
		$conv = ( new ConversationStore() )->getOrCreate( $other, get_current_user_id() );

		$req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/chat/turns' );
		$req->set_param( 'conversation_id', $conv['id'] );
		$req->set_param( 'post_id', $other );
		$req->set_param( 'message', 'x' );
		$res = $this->server->dispatch( $req );
		$this->assertSame( 403, $res->get_status() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter ChatControllerTest`
Expected: FAIL — routes not registered.

- [ ] **Step 3: Implement ChatController**

Create `src/Rest/ChatController.php`:

```php
<?php
/**
 * REST routes under /pediment-ai/v1/chat/*.
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Rest;

use PedimentAi\Anthropic\Client;
use PedimentAi\Anthropic\SchemaBuilder;
use PedimentAi\BlockTree\Validator;
use PedimentAi\Chat\ConversationStore;
use PedimentAi\Chat\PromptBuilder;
use PedimentAi\Chat\Tools;
use PedimentAi\Chat\TurnRunner;
use PedimentAi\Chat\VirtualTree;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ChatController {
	public const NS = 'pediment-ai/v1';

	public function register(): void {
		register_rest_route( self::NS, '/chat/conversations', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'permGetConv' ],
			'callback'            => [ $this, 'getConversation' ],
			'args'                => [ 'post_id' => [ 'type' => 'integer', 'required' => true ] ],
		] );
		register_rest_route( self::NS, '/chat/conversations/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => [ $this, 'permTouchConv' ],
			'callback'            => [ $this, 'clearConversation' ],
		] );
		register_rest_route( self::NS, '/chat/turns', [
			'methods'             => 'POST',
			'permission_callback' => [ $this, 'permPostTurn' ],
			'callback'            => [ $this, 'startTurn' ],
		] );
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'permTouchTurn' ],
			'callback'            => [ $this, 'getTurn' ],
		] );
		register_rest_route( self::NS, '/chat/turns/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'permission_callback' => [ $this, 'permTouchTurn' ],
			'callback'            => [ $this, 'abortTurn' ],
		] );
	}

	// --- Permission callbacks ---

	public function permGetConv( \WP_REST_Request $r ): bool {
		$post_id = (int) $r->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}
	public function permTouchConv( \WP_REST_Request $r ): bool {
		$conv = ( new ConversationStore() )->findById( (int) $r->get_param( 'id' ) );
		return $conv && current_user_can( 'edit_post', $conv['post_id'] ) && (int) $conv['user_id'] === get_current_user_id();
	}
	public function permPostTurn( \WP_REST_Request $r ): bool {
		$post_id = (int) $r->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}
	public function permTouchTurn( \WP_REST_Request $r ): bool {
		$msg = ( new ConversationStore() )->getMessage( (int) $r->get_param( 'id' ) );
		if ( ! $msg ) { return false; }
		$conv = ( new ConversationStore() )->findById( (int) ( $msg['conversation_id'] ?? 0 ) );
		// getMessage doesn't include conversation_id today — fetch directly:
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.post_id, c.user_id FROM {$wpdb->prefix}pediment_ai_chat_messages m
			 JOIN {$wpdb->prefix}pediment_ai_chat_conversations c ON c.id = m.conversation_id
			 WHERE m.id = %d",
			(int) $r->get_param( 'id' )
		), ARRAY_A );
		return $row && current_user_can( 'edit_post', (int) $row['post_id'] ) && (int) $row['user_id'] === get_current_user_id();
	}

	// --- Handlers ---

	public function getConversation( \WP_REST_Request $r ): \WP_REST_Response {
		$conv = ( new ConversationStore() )->getOrCreate( (int) $r->get_param( 'post_id' ), get_current_user_id() );
		return new \WP_REST_Response( $conv, 200 );
	}

	public function clearConversation( \WP_REST_Request $r ): \WP_REST_Response {
		( new ConversationStore() )->clear( (int) $r->get_param( 'id' ) );
		return new \WP_REST_Response( null, 204 );
	}

	public function startTurn( \WP_REST_Request $r ) {
		$post_id         = (int) $r->get_param( 'post_id' );
		$conversation_id = (int) $r->get_param( 'conversation_id' );
		$message         = trim( (string) $r->get_param( 'message' ) );
		$selected        = $r->get_param( 'selected_block' );
		if ( '' === $message ) {
			return new \WP_Error( 'pediment_ai_invalid', __( 'Message is required.', 'pediment-ai' ), [ 'status' => 400 ] );
		}

		$limits = (array) get_option( 'pediment_ai_rate_limits', \PedimentAi\Usage\RateLimiter::DEFAULTS );
		if ( ! ( new \PedimentAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), 'compose' ) ) {
			return new \WP_Error( 'pediment_ai_rate_limited', __( 'Rate limit reached.', 'pediment-ai' ), [ 'status' => 429 ] );
		}

		$store   = new ConversationStore();
		$store->appendUserMessage( $conversation_id, $message );
		$turn_id = $store->startAssistantTurn( $conversation_id );

		// Build context for TurnRunner.
		$tree_source = is_array( $r->get_param( 'block_tree' ) ) ? $r->get_param( 'block_tree' ) : [];
		$tree        = new VirtualTree( $tree_source );

		$response = new \WP_REST_Response( [ 'turn_id' => $turn_id ], 202 );

		// In production we close the response before running the turn.
		// In tests (no fastcgi_finish_request, or WP_TESTS_DOMAIN defined), we run inline.
		if ( $this->canDeferResponse() ) {
			$this->respondAndFlush( $response );
			$this->processTurn( $turn_id, $conversation_id, $tree, $selected, $message );
			exit;
		}

		$this->processTurn( $turn_id, $conversation_id, $tree, $selected, $message );
		return $response;
	}

	public function getTurn( \WP_REST_Request $r ): \WP_REST_Response {
		$msg = ( new ConversationStore() )->getMessage( (int) $r->get_param( 'id' ) );
		if ( ! $msg ) {
			return new \WP_REST_Response( [ 'status' => 'error', 'error' => [ 'code' => 'not_found', 'message' => 'Turn not found' ] ], 404 );
		}
		return new \WP_REST_Response( [
			'status'     => $msg['status'],
			'content'    => $msg['content'],
			'tool_calls' => $msg['tool_calls'],
			'error'      => $msg['error'],
		], 200 );
	}

	public function abortTurn( \WP_REST_Request $r ): \WP_REST_Response {
		( new ConversationStore() )->abort( (int) $r->get_param( 'id' ) );
		return new \WP_REST_Response( null, 204 );
	}

	// --- Internal ---

	/**
	 * @param array<string,mixed>|null $selected
	 */
	public function processTurn( int $turn_id, int $conversation_id, VirtualTree $tree, $selected, string $message ): void {
		$store = new ConversationStore();
		$conv  = $store->findById( $conversation_id );
		// Build history (everything except the just-inserted user+assistant pair).
		$history = array_slice( $conv['messages'], 0, -2 );

		$schema   = ( new SchemaBuilder() )->build();
		$tools    = new Tools( $schema['blocks'], new Validator( $schema['blocks'] ) );
		$prompts  = new PromptBuilder( $schema['blocks'] );
		$provider = apply_filters(
			'pediment_ai_provider',
			new Client( ( new \PedimentAi\Settings\OptionsStore() )->getApiKey() )
		);
		$model    = (string) apply_filters( 'pediment_ai_model_compose', 'claude-sonnet-4-6' );

		$selectedId = is_array( $selected ) && isset( $selected['clientId'] ) ? (string) $selected['clientId'] : null;

		( new TurnRunner( $store, $tools, $prompts, $provider, $model ) )->run(
			turn_id:        $turn_id,
			tree:           $tree,
			history:        $history,
			selectedId:     $selectedId,
			currentUserMsg: $message
		);
	}

	private function canDeferResponse(): bool {
		return function_exists( 'fastcgi_finish_request' ) && ! defined( 'WP_TESTS_DOMAIN' );
	}

	private function respondAndFlush( \WP_REST_Response $r ): void {
		status_header( $r->get_status() );
		nocache_headers();
		echo wp_json_encode( $r->get_data() );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}
}
```

> **Note for the implementer:** `WP_TESTS_DOMAIN` is defined by `wp-tests-config-sample.php` in the WP test-suite. That's our test-mode signal. In a test environment, `canDeferResponse()` returns false, so the controller runs `processTurn` inline before returning the response — making the polling test assertions deterministic.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter ChatControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Rest/ChatController.php tests/phpunit/Rest/ChatControllerTest.php
git commit -m "$(cat <<'EOF'
feat(rest): ChatController for /chat/* routes with deferred TurnRunner

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Wire ChatController in Bootstrap; delete legacy backend

**Files:**
- Modify: `src/Bootstrap.php`
- Delete: `src/Rest/ComposeController.php`, `src/Rest/EditController.php`, `src/Rest/RefineController.php`, `src/Rest/StatusController.php`, `src/Jobs/ComposeJob.php`, `src/Jobs/JobStore.php`, `src/BlockTree/Parser.php`
- Delete tests: `tests/phpunit/Rest/ComposeControllerTest.php`, `tests/phpunit/Rest/EditControllerTest.php`, `tests/phpunit/Rest/RefineControllerTest.php`, `tests/phpunit/Rest/StatusControllerTest.php`, `tests/phpunit/Jobs/ComposeJobTest.php`, `tests/phpunit/Jobs/JobStoreTest.php`, `tests/phpunit/BlockTree/ParserTest.php` (if present)

- [ ] **Step 1: Verify the existing test suite is green**

Run: `composer test`
Expected: All tests pass (we should have a fully green suite before demolishing).

- [ ] **Step 2: Edit Bootstrap.php — replace `rest_api_init` action**

In `src/Bootstrap.php`, replace the existing block:

```php
add_action(
	'rest_api_init',
	static function () {
		( new \PedimentAi\Rest\ComposeController() )->register();
		( new \PedimentAi\Rest\EditController() )->register();
		( new \PedimentAi\Rest\RefineController() )->register();
		( new \PedimentAi\Rest\StatusController() )->register();
	}
);
```

with:

```php
add_action(
	'rest_api_init',
	static function () {
		( new \PedimentAi\Rest\ChatController() )->register();
	}
);
```

- [ ] **Step 3: Remove obsolete action hooks**

In `src/Bootstrap.php`, delete both `add_action( 'pediment_ai_job_completed', ... )` and `add_action( 'pediment_ai_job_run', ... )` blocks entirely (lines that wire `ComposeJob` and `JobStore`).

- [ ] **Step 4: Delete legacy PHP files**

Run:
```bash
rm src/Rest/ComposeController.php src/Rest/EditController.php src/Rest/RefineController.php src/Rest/StatusController.php \
   src/Jobs/ComposeJob.php src/Jobs/JobStore.php \
   src/BlockTree/Parser.php
rmdir src/Jobs 2>/dev/null || true
rm tests/phpunit/Rest/ComposeControllerTest.php tests/phpunit/Rest/EditControllerTest.php tests/phpunit/Rest/RefineControllerTest.php tests/phpunit/Rest/StatusControllerTest.php
rm tests/phpunit/Jobs/ComposeJobTest.php tests/phpunit/Jobs/JobStoreTest.php
rmdir tests/phpunit/Jobs 2>/dev/null || true
rm tests/phpunit/BlockTree/ParserTest.php 2>/dev/null || true
```

- [ ] **Step 5: Run all PHP tests**

Run: `composer test`
Expected: All remaining tests pass; no references to deleted classes.

If anything fails because Action Scheduler still references the job-run action, that's fine — it'll just be a stale registration that does nothing.

- [ ] **Step 6: Commit**

```bash
git add -u src/ tests/phpunit/
git commit -m "$(cat <<'EOF'
refactor: remove legacy compose/edit/refine/status REST surface and job runner

ChatController is now the sole REST entry point for AI work.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: StreamingCheck — admin notice when fastcgi_finish_request missing

**Files:**
- Create: `src/Activation/StreamingCheck.php`
- Modify: `src/Bootstrap.php`
- Test: `tests/phpunit/Activation/StreamingCheckTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Activation/StreamingCheckTest.php`:

```php
<?php
namespace PedimentAi\Tests\Activation;

use PedimentAi\Activation\StreamingCheck;

class StreamingCheckTest extends \WP_UnitTestCase {
	public function test_renders_notice_when_function_missing(): void {
		$check = new StreamingCheck( fn() => false );
		ob_start();
		$check->renderNotice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'fastcgi_finish_request', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_renders_nothing_when_function_present(): void {
		$check = new StreamingCheck( fn() => true );
		ob_start();
		$check->renderNotice();
		$this->assertSame( '', ob_get_clean() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter StreamingCheckTest`
Expected: FAIL.

- [ ] **Step 3: Implement StreamingCheck**

Create `src/Activation/StreamingCheck.php`:

```php
<?php
/**
 * Admin notice when the host lacks fastcgi_finish_request (degrades streaming UX).
 *
 * @package PedimentAi
 */

declare(strict_types=1);

namespace PedimentAi\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamingCheck {
	/** @var callable():bool */
	private $detector;

	public function __construct( ?callable $detector = null ) {
		$this->detector = $detector ?? static fn(): bool => function_exists( 'fastcgi_finish_request' );
	}

	public function renderNotice(): void {
		if ( ( $this->detector )() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Pediment AI: PHP-FPM\'s fastcgi_finish_request() is not available on this host. The chat sidebar will still work but the first response of each turn will not feel streamed. Ask your hosting provider about enabling FastCGI or upgrading to PHP-FPM.',
				'pediment-ai'
			)
		);
	}
}
```

- [ ] **Step 4: Wire it in Bootstrap.php**

In `src/Bootstrap.php`, inside `register()`, add (after the existing `( new \PedimentAi\Settings\Page() )->register();` line):

```php
add_action( 'admin_notices', [ new \PedimentAi\Activation\StreamingCheck(), 'renderNotice' ] );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter StreamingCheckTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Activation/StreamingCheck.php src/Bootstrap.php tests/phpunit/Activation/StreamingCheckTest.php
git commit -m "$(cat <<'EOF'
feat(activation): admin notice when fastcgi_finish_request is missing

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: Frontend — ChatSidebar shell with PluginSidebar registration

**Files:**
- Create: `editor/ChatSidebar.tsx`
- Modify: `editor/index.tsx`
- Modify: `editor/styles.scss`

This task gets the sidebar visible in WordPress with a placeholder body. Subsequent tasks fill in messages, composer, selection, mutations. Old modals stay alive in parallel until Task 17 demolishes them — this keeps the editor functional between commits.

- [ ] **Step 1: Create the sidebar component**

Create `editor/ChatSidebar.tsx`:

```tsx
import { PluginSidebar as PluginSidebarFromEditor } from '@wordpress/editor';
import { PluginSidebar as PluginSidebarFromEditPost } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';

// WP <6.6 only exposes PluginSidebar on @wordpress/edit-post; WP 6.6+ moved it to @wordpress/editor.
const PluginSidebar = PluginSidebarFromEditor ?? PluginSidebarFromEditPost;

export const SIDEBAR_NAME = 'pediment-ai/chat';

export default function ChatSidebar() {
  return (
    <PluginSidebar
      name="chat"
      title={__('AI Chat', 'pediment-ai')}
      icon="format-chat"
      className="pediment-ai-chat"
    >
      <div className="pediment-ai-chat__body">
        <p>{__('Chat surface coming online…', 'pediment-ai')}</p>
      </div>
    </PluginSidebar>
  );
}
```

- [ ] **Step 2: Register the sidebar in editor/index.tsx**

Edit `editor/index.tsx`. Replace the current contents:

```tsx
import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import BlockPanel from './BlockPanel';
import ChatSidebar from './ChatSidebar';
import './styles.scss';

registerPlugin('pediment-ai-document-panel', { render: DocumentPanel });
registerPlugin('pediment-ai-block-panel',    { render: BlockPanel });
registerPlugin('pediment-ai-chat',           { render: ChatSidebar });
```

- [ ] **Step 3: Add minimal chat styles**

Append to `editor/styles.scss`:

```scss
.pediment-ai-chat {
  &__body { padding: 12px 16px; font-size: 13px; }
}
```

- [ ] **Step 4: Build and smoke-check**

Run: `npm run build`
Expected: build succeeds without errors.

- [ ] **Step 5: Commit**

```bash
git add editor/ChatSidebar.tsx editor/index.tsx editor/styles.scss
git commit -m "$(cat <<'EOF'
feat(editor): ChatSidebar PluginSidebar shell with WP <6.6/6.6+ fallback

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: Frontend — useChatTurn hook + Composer + MessageList

**Files:**
- Create: `editor/hooks/useConversation.ts`
- Create: `editor/hooks/useChatTurn.ts`
- Create: `editor/chat/MessageList.tsx`
- Create: `editor/chat/Composer.tsx`
- Create: `editor/chat/ToolCallSummary.tsx`
- Modify: `editor/ChatSidebar.tsx`
- Modify: `editor/styles.scss`

- [ ] **Step 1: Implement useConversation**

Create `editor/hooks/useConversation.ts`:

```ts
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
        path: `/pediment-ai/v1/chat/conversations?post_id=${postId}`,
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
    await apiFetch({ path: `/pediment-ai/v1/chat/conversations/${conv.id}`, method: 'DELETE' });
    await load();
  }, [conv, load]);

  return { conv, error, reload: load, clear, setConv };
}
```

- [ ] **Step 2: Implement useChatTurn**

Create `editor/hooks/useChatTurn.ts`:

```ts
import apiFetch from '@wordpress/api-fetch';
import { useState, useRef, useCallback } from '@wordpress/element';
import { select } from '@wordpress/data';
import type { ChatMessage } from './useConversation';

const POLL_MS = 300;

type StartArgs = {
  conversationId: number;
  postId: number;
  message: string;
  selectedBlock: { clientId: string; name: string; attributes: any; innerBlocks: any[] } | null;
  onComplete: (msg: ChatMessage) => void;
};

export default function useChatTurn() {
  const [streaming, setStreaming] = useState<ChatMessage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<number | null>(null);
  const abortedRef = useRef(false);

  const stop = useCallback(() => {
    if (streaming) {
      apiFetch({ path: `/pediment-ai/v1/chat/turns/${streaming.id}`, method: 'DELETE' }).catch(() => {});
      abortedRef.current = true;
    }
    if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
    setStreaming(null);
  }, [streaming]);

  const start = useCallback(async (args: StartArgs) => {
    setError(null);
    abortedRef.current = false;
    const blockTree = blocksToTree((select('core/block-editor') as any).getBlocks());
    let turnId: number;
    try {
      const r = await apiFetch<{ turn_id: number }>({
        path: '/pediment-ai/v1/chat/turns',
        method: 'POST',
        data: {
          conversation_id: args.conversationId,
          post_id:         args.postId,
          message:         args.message,
          selected_block:  args.selectedBlock,
          block_tree:      blockTree,
        },
      });
      turnId = r.turn_id;
    } catch (e: any) {
      setError(e?.message ?? 'Failed to start turn');
      return;
    }

    const tick = async () => {
      try {
        const t = await apiFetch<ChatMessage>({ path: `/pediment-ai/v1/chat/turns/${turnId}`, method: 'GET' });
        if (abortedRef.current) return;
        setStreaming({ ...t, id: turnId });
        if (t.status !== 'streaming') {
          if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
          setStreaming(null);
          args.onComplete({ ...t, id: turnId });
        }
      } catch (e: any) {
        if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
        setStreaming(null);
        setError(e?.message ?? 'Polling failed');
      }
    };
    await tick();
    timer.current = window.setInterval(tick, POLL_MS);
  }, []);

  return { streaming, error, start, stop };
}

function blocksToTree(blocks: any[]): any[] {
  return blocks.map((b) => ({
    clientId:    b.clientId,
    name:        b.name,
    attributes:  b.attributes ?? {},
    innerBlocks: blocksToTree(b.innerBlocks ?? []),
  }));
}
```

- [ ] **Step 3: Implement ToolCallSummary**

Create `editor/chat/ToolCallSummary.tsx`:

```tsx
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function ToolCallSummary({ calls }: { calls: any[] }) {
  const [open, setOpen] = useState(false);
  if (!calls?.length) return null;
  const counts: Record<string, number> = {};
  for (const c of calls) { counts[c.tool] = (counts[c.tool] ?? 0) + 1; }
  const label = Object.entries(counts).map(([t, n]) => `${humanize(t, n)}`).join(', ');
  return (
    <div className="pediment-ai-chat__tools">
      <button type="button" className="pediment-ai-chat__tools-toggle" onClick={() => setOpen(!open)}>
        {open ? '▾ ' : '▸ '}{label}
      </button>
      {open && (
        <pre className="pediment-ai-chat__tools-detail">{JSON.stringify(calls, null, 2)}</pre>
      )}
    </div>
  );
}

function humanize(tool: string, n: number): string {
  const noun = tool.replace(/_/g, ' ');
  return n === 1 ? `1 ${noun}` : `${n} ${noun}s`;
}
```

- [ ] **Step 4: Implement MessageList**

Create `editor/chat/MessageList.tsx`:

```tsx
import { useEffect, useRef } from '@wordpress/element';
import ToolCallSummary from './ToolCallSummary';
import type { ChatMessage } from '../hooks/useConversation';

export default function MessageList({ messages, streaming }: { messages: ChatMessage[]; streaming: ChatMessage | null }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => { ref.current?.scrollTo({ top: ref.current.scrollHeight, behavior: 'smooth' }); }, [messages.length, streaming?.content]);

  const display = streaming ? [...messages, streaming] : messages;

  return (
    <div className="pediment-ai-chat__messages" ref={ref}>
      {display.map((m) => (
        <div key={m.id} className={`pediment-ai-chat__message pediment-ai-chat__message--${m.role}`}>
          <div className="pediment-ai-chat__bubble">
            {m.content}
            {m.status === 'streaming' && <span className="pediment-ai-chat__caret" />}
          </div>
          <ToolCallSummary calls={m.tool_calls} />
          {m.error && <div className="pediment-ai-chat__error">{m.error.message}</div>}
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 5: Implement Composer**

Create `editor/chat/Composer.tsx`:

```tsx
import { Button } from '@wordpress/components';
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Composer({ onSubmit, onStop, busy }: { onSubmit: (text: string) => void; onStop: () => void; busy: boolean }) {
  const [value, setValue] = useState('');
  const ref = useRef<HTMLTextAreaElement>(null);

  const submit = () => {
    const trimmed = value.trim();
    if (!trimmed || busy) return;
    onSubmit(trimmed);
    setValue('');
  };

  return (
    <div className="pediment-ai-chat__composer">
      <textarea
        ref={ref}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
        }}
        placeholder={__('Ask the AI to write or edit…', 'pediment-ai')}
        rows={3}
        disabled={busy}
      />
      <div className="pediment-ai-chat__composer-actions">
        {busy
          ? <Button variant="secondary" onClick={onStop}>{__('Stop', 'pediment-ai')}</Button>
          : <Button variant="primary"   onClick={submit} disabled={!value.trim()}>{__('Send', 'pediment-ai')}</Button>}
      </div>
    </div>
  );
}
```

- [ ] **Step 6: Wire everything into ChatSidebar**

Replace `editor/ChatSidebar.tsx` with:

```tsx
import { PluginSidebar as PluginSidebarFromEditor } from '@wordpress/editor';
import { PluginSidebar as PluginSidebarFromEditPost } from '@wordpress/edit-post';
import { useSelect } from '@wordpress/data';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import useConversation from './hooks/useConversation';
import useChatTurn from './hooks/useChatTurn';
import MessageList from './chat/MessageList';
import Composer from './chat/Composer';

const PluginSidebar = PluginSidebarFromEditor ?? PluginSidebarFromEditPost;

export const SIDEBAR_NAME = 'pediment-ai/chat';

export default function ChatSidebar() {
  const postId = useSelect((s) => (s('core/editor') as any).getCurrentPostId(), []) as number | null;
  const { conv, error: convError, reload, clear } = useConversation(postId);
  const { streaming, error: turnError, start, stop } = useChatTurn();

  const send = (text: string) => {
    if (!conv || !postId) return;
    start({
      conversationId: conv.id,
      postId,
      message: text,
      selectedBlock: null,
      onComplete: () => reload(),
    });
  };

  return (
    <PluginSidebar name="chat" title={__('AI Chat', 'pediment-ai')} icon="format-chat" className="pediment-ai-chat">
      <div className="pediment-ai-chat__header">
        <span className="pediment-ai-chat__title">{__('AI Chat', 'pediment-ai')}</span>
        <Button variant="tertiary" size="small" onClick={clear}>{__('Clear', 'pediment-ai')}</Button>
      </div>
      <MessageList messages={conv?.messages ?? []} streaming={streaming} />
      {(convError || turnError) && <div className="pediment-ai-chat__error">{convError ?? turnError}</div>}
      <Composer onSubmit={send} onStop={stop} busy={!!streaming} />
    </PluginSidebar>
  );
}
```

- [ ] **Step 7: Add styles**

Append to `editor/styles.scss` (replacing the placeholder `&__body` rule):

```scss
.pediment-ai-chat {
  display: flex; flex-direction: column; height: 100%;
  &__header   { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid #ddd; }
  &__title    { font-weight: 600; }
  &__messages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
  &__message  { display: flex; flex-direction: column; }
  &__message--user      { align-items: flex-end; }
  &__message--assistant { align-items: flex-start; }
  &__bubble   { padding: 8px 12px; border-radius: 12px; max-width: 88%; background: #f0f0f1; white-space: pre-wrap; }
  &__message--user .pediment-ai-chat__bubble { background: #007cba; color: #fff; }
  &__caret    { display: inline-block; width: 6px; height: 13px; background: currentColor; margin-left: 4px; animation: pediment-ai-blink 1s infinite; }
  &__tools    { font-size: 11px; color: #555; margin-top: 4px; }
  &__tools-toggle { background: none; border: 0; padding: 0; color: inherit; cursor: pointer; font-size: 11px; }
  &__tools-detail { background: #f6f7f7; padding: 6px; font-size: 11px; max-height: 200px; overflow: auto; }
  &__error    { color: #b32d2e; padding: 4px 12px; font-size: 12px; }
  &__composer { border-top: 1px solid #ddd; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
  &__composer textarea { width: 100%; resize: vertical; }
  &__composer-actions { display: flex; justify-content: flex-end; }
}
@keyframes pediment-ai-blink { 50% { opacity: 0; } }
```

- [ ] **Step 8: Build**

Run: `npm run build`
Expected: success.

- [ ] **Step 9: Commit**

```bash
git add editor/hooks/useConversation.ts editor/hooks/useChatTurn.ts editor/chat/ editor/ChatSidebar.tsx editor/styles.scss
git commit -m "$(cat <<'EOF'
feat(editor): ChatSidebar with useChatTurn, MessageList, Composer

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 16: Frontend — selection awareness + quick actions

**Files:**
- Create: `editor/hooks/useSelectedBlockContext.ts`
- Create: `editor/chat/SelectionChip.tsx`
- Create: `editor/chat/QuickActions.tsx`
- Modify: `editor/ChatSidebar.tsx`

- [ ] **Step 1: Implement useSelectedBlockContext**

Create `editor/hooks/useSelectedBlockContext.ts`:

```ts
import { useSelect } from '@wordpress/data';

export type SelectedBlock = {
  clientId: string;
  name: string;
  attributes: Record<string, any>;
  innerBlocks: any[];
};

export default function useSelectedBlockContext(): SelectedBlock | null {
  return useSelect((s) => {
    const bs = s('core/block-editor') as any;
    const clientId = bs.getSelectedBlockClientId();
    if (!clientId) return null;
    const block = bs.getBlock(clientId);
    if (!block) return null;
    return {
      clientId,
      name: block.name,
      attributes: block.attributes ?? {},
      innerBlocks: block.innerBlocks ?? [],
    };
  }, []);
}
```

- [ ] **Step 2: Implement SelectionChip**

Create `editor/chat/SelectionChip.tsx`:

```tsx
import { Button } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { SelectedBlock } from '../hooks/useSelectedBlockContext';

export default function SelectionChip({ block }: { block: SelectedBlock }) {
  const preview = (block.attributes.content ?? block.attributes.text ?? '').toString().slice(0, 60);
  const clear   = () => (dispatch('core/block-editor') as any).clearSelectedBlock();
  return (
    <div className="pediment-ai-chat__chip">
      <span className="pediment-ai-chat__chip-type">{block.name.replace(/^core\//, '')}</span>
      <span className="pediment-ai-chat__chip-preview">{preview}</span>
      <Button size="small" variant="tertiary" onClick={clear} aria-label={__('Clear selection', 'pediment-ai')}>×</Button>
    </div>
  );
}
```

- [ ] **Step 3: Implement QuickActions**

Create `editor/chat/QuickActions.tsx`:

```tsx
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { SelectedBlock } from '../hooks/useSelectedBlockContext';

const PRESETS: Record<string, { label: string; instruction: string }[]> = {
  'core/paragraph': [
    { label: 'Shorten',     instruction: 'Shorten the selected paragraph.' },
    { label: 'Expand',      instruction: 'Expand the selected paragraph with more detail.' },
    { label: 'Rewrite',     instruction: 'Rewrite the selected paragraph in different words.' },
    { label: 'Fix grammar', instruction: 'Fix any grammar or typos in the selected paragraph.' },
  ],
  'core/heading': [
    { label: 'Shorten',  instruction: 'Shorten the selected heading.' },
    { label: 'Rewrite',  instruction: 'Rewrite the selected heading with a different angle.' },
  ],
  'core/list': [
    { label: 'Add item',   instruction: 'Add another item to the selected list.' },
    { label: 'Reorder',    instruction: 'Reorder the items in the selected list more logically.' },
  ],
  'core/image': [
    { label: 'Alt text', instruction: 'Generate alt text for the selected image.' },
    { label: 'Caption',  instruction: 'Write a short caption for the selected image.' },
  ],
};
const FALLBACK = [
  { label: 'Improve', instruction: 'Improve the selected block.' },
  { label: 'Rewrite', instruction: 'Rewrite the selected block in different words.' },
];

export default function QuickActions({ block, onAction, busy }: { block: SelectedBlock; onAction: (instruction: string) => void; busy: boolean }) {
  const actions = PRESETS[block.name] ?? FALLBACK;
  return (
    <div className="pediment-ai-chat__quick">
      {actions.map((a) => (
        <Button key={a.label} variant="secondary" size="small" onClick={() => onAction(a.instruction)} disabled={busy}>
          {__(a.label, 'pediment-ai')}
        </Button>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Wire chip + quick actions into ChatSidebar**

Edit `editor/ChatSidebar.tsx`. After the existing imports, add:

```tsx
import useSelectedBlockContext from './hooks/useSelectedBlockContext';
import SelectionChip from './chat/SelectionChip';
import QuickActions from './chat/QuickActions';
```

Inside the component, after `const { streaming, error: turnError, start, stop } = useChatTurn();`, add:

```tsx
const selected = useSelectedBlockContext();

const sendWithSelection = (text: string) => {
  if (!conv || !postId) return;
  start({
    conversationId: conv.id,
    postId,
    message: text,
    selectedBlock: selected,
    onComplete: () => reload(),
  });
};
```

Replace the existing `send` function and the line that uses it with `sendWithSelection`. Then, inside the JSX (above `<Composer ... />`), add:

```tsx
{selected && (
  <>
    <SelectionChip block={selected} />
    <QuickActions block={selected} onAction={sendWithSelection} busy={!!streaming} />
  </>
)}
```

(Make sure to swap `onSubmit={send}` → `onSubmit={sendWithSelection}` in the `<Composer ... />` element.)

- [ ] **Step 5: Add styles for chip + quick actions**

Append to `editor/styles.scss` (inside `.pediment-ai-chat { ... }`):

```scss
&__chip    { display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: #fffbe6; border-top: 1px solid #f1d889; font-size: 12px; }
&__chip-type    { font-weight: 600; }
&__chip-preview { color: #555; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
&__quick   { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 12px 0; }
```

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: success.

- [ ] **Step 7: Commit**

```bash
git add editor/hooks/useSelectedBlockContext.ts editor/chat/SelectionChip.tsx editor/chat/QuickActions.tsx editor/ChatSidebar.tsx editor/styles.scss
git commit -m "$(cat <<'EOF'
feat(editor): selection chip + contextual quick actions for chat sidebar

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 17: Frontend — apply tool calls to canvas atomically on turn complete

**Files:**
- Create: `editor/applyToolCalls.ts`
- Modify: `editor/hooks/useChatTurn.ts`

- [ ] **Step 1: Implement applyToolCalls**

Create `editor/applyToolCalls.ts`:

```ts
import { dispatch, select } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';

type ToolCall = { tool: string; input: any; output?: any; is_error?: boolean };

/**
 * Apply a list of tool calls from a completed turn to the canvas as a single Gutenberg history entry.
 * Server-emitted clientIds (prefixed "srv-") are mapped to freshly-minted Gutenberg clientIds.
 */
export default function applyToolCalls(calls: ToolCall[]): void {
  if (!calls?.length) return;

  const blockEditor = dispatch('core/block-editor') as any;
  const blockSelect = select('core/block-editor') as any;

  // Map server clientIds → real Gutenberg clientIds for inserts emitted in this turn.
  const idMap: Record<string, string> = {};
  const resolve = (id?: string | null): string | null => (id == null ? null : (idMap[id] ?? id));

  // Use synced batching where available (WP 6.4+) so undo treats the whole sequence as one entry.
  const runBatch = blockEditor.__unstableMarkNextChangeAsNotPersistent
    ? (fn: () => void) => fn()
    : (fn: () => void) => fn();

  runBatch(() => {
    for (const c of calls) {
      if (c.is_error) continue;
      switch (c.tool) {
        case 'insert_block': {
          const block = createBlockFromSpec(c.input.block);
          const target = resolve(c.input.after_client_id);
          const order = blockSelect.getBlockOrder() as string[];
          let index: number;
          if (c.input.position === 'start') index = 0;
          else if (c.input.position === 'end' || !target) index = order.length;
          else index = order.indexOf(target) + (c.input.position === 'after' ? 1 : 0);
          blockEditor.insertBlock(block, index, undefined, false);
          if (c.output && c.output.client_id) {
            idMap[c.output.client_id] = block.clientId;
          }
          break;
        }
        case 'update_block': {
          const id = resolve(c.input.client_id);
          if (!id) break;
          const attrs = { ...(c.input.attrs ?? {}) };
          if (typeof c.input.content === 'string') attrs.content = c.input.content;
          blockEditor.updateBlockAttributes(id, attrs);
          break;
        }
        case 'delete_block': {
          const id = resolve(c.input.client_id);
          if (id) blockEditor.removeBlock(id);
          break;
        }
        case 'move_block': {
          const id = resolve(c.input.client_id);
          const target = resolve(c.input.target_client_id);
          if (!id || !target) break;
          const order = blockSelect.getBlockOrder() as string[];
          const targetIndex = order.indexOf(target);
          const newIndex = c.input.position === 'before' ? targetIndex : targetIndex + 1;
          blockEditor.moveBlockToPosition(id, '', '', newIndex);
          break;
        }
      }
    }
  });
}

function createBlockFromSpec(spec: any): any {
  const inner = (spec.innerBlocks ?? []).map(createBlockFromSpec);
  return createBlock(spec.name, spec.attributes ?? {}, inner);
}
```

- [ ] **Step 2: Wire applyToolCalls into useChatTurn**

Edit `editor/hooks/useChatTurn.ts`. At the top, add import:

```ts
import applyToolCalls from '../applyToolCalls';
```

Replace the `if (t.status !== 'streaming')` block inside `tick` with:

```ts
if (t.status !== 'streaming') {
  if (timer.current !== null) { window.clearInterval(timer.current); timer.current = null; }
  setStreaming(null);
  if (t.status === 'complete' && Array.isArray(t.tool_calls)) {
    applyToolCalls(t.tool_calls);
  }
  args.onComplete({ ...t, id: turnId });
}
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: success.

- [ ] **Step 4: Commit**

```bash
git add editor/applyToolCalls.ts editor/hooks/useChatTurn.ts
git commit -m "$(cat <<'EOF'
feat(editor): apply tool calls to canvas atomically on turn complete

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 18: Demolish legacy editor code; shrink DocumentPanel to a launcher

**Files:**
- Delete: `editor/ComposeModal.tsx`, `editor/EditModal.tsx`, `editor/BlockPanel.tsx`, `editor/RefineActions.tsx`, `editor/SourcePills.tsx`, `editor/hooks/useApiClient.ts`, `editor/hooks/useJobPolling.ts`
- Modify: `editor/index.tsx`
- Modify: `editor/DocumentPanel.tsx`
- Modify: `editor/styles.scss` (remove old `&__modal`, `&__pills`, `&__progress`, `&__panel` rules that are no longer referenced)
- Delete e2e: `tests/e2e/compose.spec.ts`, `tests/e2e/edit.spec.ts`, `tests/e2e/refine.spec.ts`

- [ ] **Step 1: Replace DocumentPanel with a launcher**

Edit `editor/DocumentPanel.tsx`. Replace the file with:

```tsx
import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditor } from '@wordpress/editor';
import { PluginDocumentSettingPanel as PluginDocumentSettingPanelFromEditPost } from '@wordpress/edit-post';
import { Button } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const PluginDocumentSettingPanel =
  PluginDocumentSettingPanelFromEditor ?? PluginDocumentSettingPanelFromEditPost;

export default function DocumentPanel() {
  const open = () => {
    const d = dispatch('core/editor') as any;
    if (typeof d.openGeneralSidebar === 'function') {
      d.openGeneralSidebar('pediment-ai/chat');
    } else {
      (dispatch('core/edit-post') as any).openGeneralSidebar('pediment-ai/chat');
    }
  };
  return (
    <PluginDocumentSettingPanel name="pediment-ai" title="AI" className="pediment-ai__panel">
      <Button variant="primary" onClick={open}>{__('Open AI Chat', 'pediment-ai')}</Button>
    </PluginDocumentSettingPanel>
  );
}
```

- [ ] **Step 2: Simplify editor/index.tsx**

Replace `editor/index.tsx` with:

```tsx
import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import ChatSidebar from './ChatSidebar';
import './styles.scss';

registerPlugin('pediment-ai-document-panel', { render: DocumentPanel });
registerPlugin('pediment-ai-chat',           { render: ChatSidebar });
```

- [ ] **Step 3: Delete obsolete files**

```bash
rm editor/ComposeModal.tsx editor/EditModal.tsx editor/BlockPanel.tsx editor/RefineActions.tsx editor/SourcePills.tsx
rm editor/hooks/useApiClient.ts editor/hooks/useJobPolling.ts
rm tests/e2e/compose.spec.ts tests/e2e/edit.spec.ts tests/e2e/refine.spec.ts
```

- [ ] **Step 4: Remove unused styles**

Edit `editor/styles.scss`. Remove these rules (they only apply to the deleted modals/components):

```
&__panel    { /* container */ }
&__pills    { ... }
&__pill     { ... }
&__progress { ... }
&__error    { ... }   // keep — used by ChatSidebar (already defined inside .pediment-ai-chat)
```

Keep the `&__error` block — `.pediment-ai__error` may still be referenced from the legacy `.pediment-ai` namespace; if grep shows zero references, delete it too.

Verify:
```bash
grep -r 'pediment-ai__pill\|pediment-ai__progress\|pediment-ai__panel\|pediment-ai__modal' editor/
```
Expected: no matches.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: success.

- [ ] **Step 6: Run remaining e2e specs to verify nothing else breaks**

Run: `npm run env:start && npm run e2e -- --grep smoke`
Expected: smoke spec passes.

- [ ] **Step 7: Commit**

```bash
git add -u editor/ tests/e2e/
git commit -m "$(cat <<'EOF'
refactor(editor): demolish legacy modals/block-panel; DocumentPanel becomes launcher

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 19: E2E test — chat-driven compose + refine + abort

**Files:**
- Create: `tests/e2e/chat-compose.spec.ts`
- Create: `tests/e2e/chat-refine.spec.ts`
- Create: `tests/e2e/chat-abort.spec.ts`
- Modify: `.wp-env.json` (only if mock mode is not currently enabled)

The e2e tests need `PEDIMENT_AI_MOCK=true` so the mock provider is exercised — but the repo currently has `PEDIMENT_AI_MOCK: false`. Set it to true for the test runs by passing the env via the wp-env config or by setting the setting in test.

Use the existing `pediment_ai_settings.mock_mode` option through wp-cli or by reading whatever pattern the existing specs used.

- [ ] **Step 1: Enable mock mode for tests**

Edit `.wp-env.json`. Change `"PEDIMENT_AI_MOCK": false` to `"PEDIMENT_AI_MOCK": true` — OR add a Playwright fixture that flips `mock_mode` via wp-cli before each spec. Pick whichever the existing specs use; if `PEDIMENT_AI_MOCK` was already used in older specs, flip the env flag.

> **Implementer:** check the existing `tests/e2e/smoke.spec.ts` for the pattern used previously. If older specs assumed mock mode without an env flip, keep the `false` setting and add no env change. If they flipped the env, do the same here.

- [ ] **Step 2: Write chat-compose e2e**

Create `tests/e2e/chat-compose.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('chat sidebar inserts a paragraph from mock fixture', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat E2E');

  // Open the AI chat sidebar via the document-panel launcher.
  await page.getByRole('button', { name: /open ai chat/i }).click();

  // Wait for the sidebar to render.
  const sidebar = page.locator('.pediment-ai-chat');
  await sidebar.waitFor({ state: 'visible', timeout: 10_000 });

  // Send a message that triggers the insert-paragraph fixture.
  await sidebar.locator('textarea').fill('Add a paragraph that says hi');
  await sidebar.getByRole('button', { name: /^send$/i }).click();

  // The text bubble should appear with the mock's assistant prose.
  await expect(sidebar.getByText(/adding a paragraph/i)).toBeVisible({ timeout: 15_000 });

  // The canvas should receive the inserted paragraph.
  await expect(page.locator('p.wp-block-paragraph', { hasText: 'Hello from mock.' })).toBeVisible({ timeout: 10_000 });
});
```

- [ ] **Step 3: Write chat-refine e2e**

Create `tests/e2e/chat-refine.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('quick action shortens the selected paragraph via chat', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat Refine E2E');

  // Insert a paragraph manually.
  await page.keyboard.press('Tab'); // focus the canvas
  await page.locator('.block-editor-default-block-appender__content').click();
  await page.keyboard.type('A long paragraph that needs shortening, several sentences indeed.');

  // Select the paragraph block (click in it).
  await page.locator('p.wp-block-paragraph', { hasText: 'A long paragraph' }).click();

  // Open the chat sidebar.
  await page.getByRole('button', { name: /open ai chat/i }).click();
  const sidebar = page.locator('.pediment-ai-chat');
  await sidebar.waitFor({ state: 'visible' });

  // Selection chip should appear; click the "Shorten" quick action.
  await expect(sidebar.locator('.pediment-ai-chat__chip')).toBeVisible();
  await sidebar.getByRole('button', { name: /^shorten$/i }).click();

  // The mock fixture replaces content with "Short."
  await expect(page.locator('p.wp-block-paragraph', { hasText: 'Short.' })).toBeVisible({ timeout: 15_000 });
});
```

- [ ] **Step 4: Write chat-abort e2e**

Create `tests/e2e/chat-abort.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('stop button aborts an in-flight turn', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat Abort E2E');
  await page.getByRole('button', { name: /open ai chat/i }).click();

  const sidebar = page.locator('.pediment-ai-chat');
  await sidebar.waitFor({ state: 'visible' });

  await sidebar.locator('textarea').fill('Add a paragraph that says hi');
  await sidebar.getByRole('button', { name: /^send$/i }).click();

  // Stop button appears while streaming — click it immediately.
  await sidebar.getByRole('button', { name: /^stop$/i }).click();

  // The turn row should be marked aborted; the assistant bubble should still render
  // (it may have partial content), and no paragraph should land on the canvas.
  await expect(page.locator('p.wp-block-paragraph', { hasText: 'Hello from mock.' })).toHaveCount(0, { timeout: 5_000 });
});
```

> **Note for the implementer:** the mock provider returns synthetic events all at once — the stream is effectively instantaneous in tests. The abort spec is best-effort; if the turn completes before the stop button is clicked, the test may need to be tagged `@flaky` or skipped. If skipping, leave a comment explaining why.

- [ ] **Step 5: Run e2e**

Run: `npm run env:start && npm run build && npm run e2e`
Expected: chat-compose and chat-refine pass; chat-abort may pass or be flaky (see note).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/chat-compose.spec.ts tests/e2e/chat-refine.spec.ts tests/e2e/chat-abort.spec.ts .wp-env.json
git commit -m "$(cat <<'EOF'
test(e2e): chat-driven compose, refine via quick action, abort flow

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 20: Auto-open sidebar on first install

**Files:**
- Modify: `editor/ChatSidebar.tsx`

A small first-run nudge: on the user's first editor load after the plugin upgrade, open the sidebar once. Track via a per-user option (option name: `pediment_ai_chat_seen`).

- [ ] **Step 1: Add a REST route for the one-time flag**

Add to `src/Rest/ChatController.php` `register()`:

```php
register_rest_route( self::NS, '/chat/seen', [
	'methods'             => 'POST',
	'permission_callback' => static fn() => is_user_logged_in(),
	'callback'            => function () {
		update_user_meta( get_current_user_id(), 'pediment_ai_chat_seen', '1' );
		return new \WP_REST_Response( null, 204 );
	},
] );
register_rest_route( self::NS, '/chat/seen', [
	'methods'             => 'GET',
	'permission_callback' => static fn() => is_user_logged_in(),
	'callback'            => function () {
		return new \WP_REST_Response( [ 'seen' => '1' === (string) get_user_meta( get_current_user_id(), 'pediment_ai_chat_seen', true ) ], 200 );
	},
] );
```

- [ ] **Step 2: Auto-open from ChatSidebar**

Edit `editor/ChatSidebar.tsx`. Add at the top of imports:

```tsx
import apiFetch from '@wordpress/api-fetch';
import { useEffect } from '@wordpress/element';
```

Inside the `ChatSidebar` component, after the existing hook calls, add:

```tsx
useEffect(() => {
  (async () => {
    try {
      const { seen } = await apiFetch<{ seen: boolean }>({ path: '/pediment-ai/v1/chat/seen', method: 'GET' });
      if (!seen) {
        const open = (dispatch('core/editor') as any).openGeneralSidebar
          ?? (dispatch('core/edit-post') as any).openGeneralSidebar;
        open?.('pediment-ai/chat');
        await apiFetch({ path: '/pediment-ai/v1/chat/seen', method: 'POST' });
      }
    } catch {
      // first-run nudge is best-effort
    }
  })();
}, []);
```

Add `import { dispatch } from '@wordpress/data';` to the imports if not already present.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: success.

- [ ] **Step 4: Commit**

```bash
git add editor/ChatSidebar.tsx src/Rest/ChatController.php
git commit -m "$(cat <<'EOF'
feat(editor): auto-open chat sidebar on first user load

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 21: Final sweep — green test suite, type-check, lint

- [ ] **Step 1: Run PHP tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 2: Run JS lint**

Run: `npm run lint:js`
Expected: zero errors. Fix any that surface; do not commit broken code.

- [ ] **Step 3: Build production bundle**

Run: `npm run build`
Expected: success, no warnings.

- [ ] **Step 4: Run full e2e suite**

Run: `npm run env:start && npm run e2e`
Expected: all specs pass (chat-abort flakiness aside — see Task 19 note).

- [ ] **Step 5: Manual sanity test**

In a real wp-env browser session: open a page, open the AI chat, send "Add a paragraph that says hi", verify a paragraph lands; select the paragraph, click the "Shorten" quick action, verify it changes; press Cmd-Z and verify both turns undo as expected (each turn = one history entry).

This step covers what e2e can't quite verify (perceptual streaming feel, focus management, etc.).

- [ ] **Step 6: Commit any fixes from the sweep**

```bash
git status
# stage and commit only intentional fixes, with a brief message describing each.
```

---

## Self-Review

### Spec coverage

Walking through every section of [the spec](../specs/2026-05-12-ai-chat-sidebar-design.md):

- **Sidebar registration** — Tasks 14, 18 ✓
- **Sidebar layout (header / messages / chip / quick actions / composer)** — Tasks 14, 15, 16 ✓
- **Selection awareness** — Task 16 ✓
- **Tool-use model + context + tools + virtual tree + read_block + error handling** — Tasks 4, 6, 9, 10 ✓
- **Streaming via polling + turn lifecycle + abort + portability fallback** — Tasks 7, 10, 11 ✓
- **Schema (two tables, per (post, user))** — Tasks 1, 2 ✓
- **REST surface (5 routes + seen-once)** — Tasks 11, 20 ✓
- **Demolition list** — Tasks 12, 18 ✓
- **Auth (edit_post per route)** — Task 11 ✓
- **Rate limiting** — Task 11 (reuses existing `Usage/RateLimiter`) ✓
- **Activation streaming check admin notice** — Task 13 ✓
- **Auto-open on first install** — Task 20 ✓
- **Iterative tool-use loop with synthetic tool_results** — Task 10 ✓
- **Open risks (fastcgi missing, long trees, concurrent turns)** — fastcgi addressed in Task 13; long trees mitigated via skeleton in Task 4/9; concurrent turns accepted (last-write-wins) — no extra task needed.

### Placeholder scan

- No "TBD" / "TODO" / "implement later".
- One soft caveat is noted in Task 19 Step 4 about the abort spec being flaky against the instant mock provider — that's a real implementation concern, not a placeholder. Implementer can pick: tag `@flaky`, add a synthetic delay to the mock, or skip.
- One soft caveat in Task 19 Step 1 about checking the existing mock-mode convention — that's a deliberate "read the existing tests first" instruction, not a missing instruction.

### Type consistency

- `ChatMessage` shape used in `useConversation`, `useChatTurn`, `MessageList`, `ToolCallSummary` — same fields (`id`, `role`, `status`, `content`, `tool_calls`, `error`, `created_at`).
- `SelectedBlock` shape used in `useSelectedBlockContext`, `SelectionChip`, `QuickActions` — same fields.
- `applyToolCalls` consumes `{ tool, input, output?, is_error? }` shape — matches the JSON column written by `recordToolCall` in Task 3 / Task 10.
- Tool input shapes (`after_client_id`, `position`, `block`, `client_id`, `target_client_id`, `attrs`, `content`) — consistent between `Tools::definitions()` (Task 6), `Tools::apply*()` (Task 6), and `applyToolCalls` (Task 17).
- Conversation getOrCreate result shape (`id`, `post_id`, `user_id`, `messages`) — same in `ConversationStore` (Task 2), `ChatController.getConversation` (Task 11), `useConversation` hook (Task 15).
