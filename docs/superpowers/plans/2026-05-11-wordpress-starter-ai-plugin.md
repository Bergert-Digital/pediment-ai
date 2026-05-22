# WordPress Pediment AI Plugin (Plan B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Depends on Plan A.** This plugin discovers blocks at runtime via `WP_Block_Type_Registry` and operates on the block tree produced by the starter theme. Plan A v0.1.0 must be installed (via Composer or wp-env mount) for local dev and tests.

**Goal:** Build `pediment-ai`: a WordPress plugin that adds three AI-powered authoring flows — Compose (new page from prompt), Edit (modify existing page), Refine (one block from prompt) — to the Gutenberg editor. Anthropic Claude as the model, polling transport, web_fetch enabled, mock mode for tests.

**Architecture:** PHP REST + Action Scheduler background workers for compose/edit (long jobs), blocking PHP request for refine (short jobs). Editor sidebar in React, built by `@wordpress/scripts`. Schema discovered at runtime from `WP_Block_Type_Registry` and sent as an Anthropic tool input JSON schema. Job state in a small custom table; status polled via REST. Mock provider returns fixtures in tests / dev. Usage telemetry in a sibling table. Per-user rate limits via transients.

**Tech Stack:** WordPress 6.4+, PHP 8.1+, `@wordpress/scripts`, TypeScript + React, Action Scheduler (`woocommerce/action-scheduler` Composer package), PHPUnit, Playwright, GitHub Actions CI.

**Repo location:** `/Users/jonas/Entwicklung/pediment-ai/` (sibling to pediment).

---

## File Structure

```
pediment-ai/
  plugin.php                            Plugin header + bootstrap
  composer.json                         Composer deps (action-scheduler, phpcs, phpunit)
  package.json                          @wordpress/scripts, Playwright
  tsconfig.json                         TS config
  phpcs.xml.dist                        PHPCS config
  phpunit.xml.dist                      PHPUnit config
  playwright.config.ts                  Playwright config
  .wp-env.json                          wp-env mounts both this plugin AND pediment
  .gitignore                            build/, vendor/, node_modules
  README.md                             Setup, dev, config
  uninstall.php                         Drop tables + scheduled actions on plugin delete

  src/
    Bootstrap.php                       Wires hooks, REST routes, settings page
    Anthropic/
      Client.php                        wp_remote_post wrapper for Messages API
      ToolUseParser.php                 Extracts emit_page / emit_block tool calls + web_fetch events
      SchemaBuilder.php                 WP_Block_Type_Registry → tool input JSON schema
    BlockTree/
      Parser.php                        post_content → JSON tree (uses parse_blocks)
      Serializer.php                    JSON tree → serialized block markup (uses serialize_blocks)
      Validator.php                     Validates a tree against the runtime schema
    Jobs/
      JobStore.php                      CRUD on wp_pediment_ai_jobs table
      ComposeJob.php                    Action Scheduler worker for compose + edit
    Rest/
      ComposeController.php             POST /v1/compose — enqueues job
      EditController.php                POST /v1/edit — enqueues job
      RefineController.php              POST /v1/refine — synchronous
      StatusController.php              GET /v1/jobs/{id}
    Mock/
      MockProvider.php                  Returns fixture responses when PEDIMENT_AI_MOCK=true
      fixtures/
        compose-landing.json
        compose-about.json
        compose-services.json
        compose-contact.json
        edit-add-faq.json
        edit-shorten.json
        refine-hero.json
        refine-cta.json
        refine-faq-item.json
    Usage/
      Tracker.php                       Records per-call telemetry
      Pricing.php                       Token → USD estimate (configurable per model)
      RateLimiter.php                   Per-user transient-based limits
    Settings/
      Page.php                          Plugin settings screen
      OptionsStore.php                  Encrypted API key storage
    Schema/
      tables.php                        wp_pediment_ai_jobs + wp_pediment_ai_usage CREATE TABLE
  editor/
    index.tsx                           Entry: registers plugin slot
    DocumentPanel.tsx                   Compose + Edit buttons in document sidebar
    BlockPanel.tsx                      Refine actions in block-level sidebar
    ComposeModal.tsx                    Prompt input + page-type select + tone
    EditModal.tsx                       Prompt input for edit flow
    RefineActions.tsx                   Quick actions + custom instruction
    SourcePills.tsx                     Renders web_fetch URLs
    hooks/
      useJobPolling.ts                  Poll /v1/jobs/{id} every 750ms
      useApiClient.ts                   Wrapped wp.apiFetch helpers

  schema/
    blocks.json                         Cache from `wp pediment-ai dump-schema` (optional)

  wp-cli/
    DumpSchemaCommand.php               wp pediment-ai dump-schema

  tests/
    phpunit/
      bootstrap.php
      Anthropic/
        ClientTest.php
        SchemaBuilderTest.php
        ToolUseParserTest.php
      BlockTree/
        ParserTest.php
        SerializerTest.php
        ValidatorTest.php
      Jobs/
        JobStoreTest.php
        ComposeJobTest.php
      Rest/
        ComposeControllerTest.php
        EditControllerTest.php
        RefineControllerTest.php
        StatusControllerTest.php
      Usage/
        TrackerTest.php
        RateLimiterTest.php
      Mock/
        MockProviderTest.php
    e2e/
      compose.spec.ts
      edit.spec.ts
      refine.spec.ts
      utils.ts
  .github/workflows/
    ci.yml
```

---

## Phase 0: Repository setup

### Task 1: Initialize plugin with header and skeleton

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/plugin.php`
- Create: `/Users/jonas/Entwicklung/pediment-ai/uninstall.php`
- Create: `/Users/jonas/Entwicklung/pediment-ai/.gitignore`
- Create: `/Users/jonas/Entwicklung/pediment-ai/README.md` (skeleton)
- Create: `/Users/jonas/Entwicklung/pediment-ai/src/Bootstrap.php` (stub)

- [ ] **Step 1: Create the directory and init git**

```bash
mkdir -p /Users/jonas/Entwicklung/pediment-ai
cd /Users/jonas/Entwicklung/pediment-ai
git init
```

- [ ] **Step 2: Write plugin.php (WordPress plugin header + bootstrap)**

```php
<?php
/**
 * Plugin Name:       Pediment AI
 * Plugin URI:        https://github.com/bergert/pediment-ai
 * Description:       Gutenberg AI composer for pediment: compose, edit, and refine pages with Claude.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jonas Bergert
 * Author URI:        https://bergert.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pediment-ai
 *
 * @package PedimentAi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PEDIMENT_AI_VERSION', '0.1.0' );
define( 'PEDIMENT_AI_PLUGIN_FILE', __FILE__ );
define( 'PEDIMENT_AI_PLUGIN_DIR', __DIR__ );
define( 'PEDIMENT_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

add_action( 'plugins_loaded', static function () {
    if ( class_exists( '\\PedimentAi\\Bootstrap' ) ) {
        ( new \PedimentAi\Bootstrap() )->register();
    }
} );
```

- [ ] **Step 3: Write src/Bootstrap.php (empty stub)**

```php
<?php
declare(strict_types=1);

namespace PedimentAi;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Bootstrap {
    public function register(): void {
        // Hooks wired in subsequent tasks.
    }
}
```

- [ ] **Step 4: Write uninstall.php**

```php
<?php
/**
 * Runs when the plugin is deleted from wp-admin. Drops AI plugin tables.
 *
 * @package PedimentAi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pediment_ai_jobs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pediment_ai_usage" );

delete_option( 'pediment_ai_settings' );

wp_clear_scheduled_hook( 'pediment_ai_job_run' );
```

- [ ] **Step 5: Write .gitignore**

```
node_modules/
vendor/
build/
.env
.env.local
.phpunit.result.cache
playwright-report/
test-results/
.DS_Store
schema/blocks.json
```

- [ ] **Step 6: Write README.md skeleton**

```markdown
# Pediment AI Plugin

Gutenberg AI composer for [pediment](https://github.com/bergert/pediment). Compose, edit, and refine pages with Claude.

## Requirements

- WordPress 6.4+, PHP 8.1+
- `pediment` (Plan A) active
- Anthropic API key (set via `.env` `ANTHROPIC_API_KEY` or plugin settings)

## Local dev

See `docs/development.md` (added in Task 31).
```

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "chore: initialize pediment-ai plugin scaffold"
```

### Task 2: Composer setup with autoload + Action Scheduler

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/composer.json`
- Create: `/Users/jonas/Entwicklung/pediment-ai/phpcs.xml.dist`

- [ ] **Step 1: Write composer.json**

```json
{
  "name": "bergert/pediment-ai",
  "description": "WordPress AI page composer plugin for pediment",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1",
    "woocommerce/action-scheduler": "^3.7"
  },
  "require-dev": {
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "yoast/phpunit-polyfills": "^3.0",
    "phpunit/phpunit": "^9.6"
  },
  "autoload": {
    "psr-4": {
      "PedimentAi\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "PedimentAi\\Tests\\": "tests/phpunit/"
    }
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true,
      "composer/installers": true
    }
  },
  "scripts": {
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "test": "phpunit"
  }
}
```

- [ ] **Step 2: Write phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="Pediment AI">
  <description>Coding standards for pediment-ai.</description>

  <file>plugin.php</file>
  <file>src/</file>
  <file>uninstall.php</file>

  <exclude-pattern>build/*</exclude-pattern>
  <exclude-pattern>node_modules/*</exclude-pattern>
  <exclude-pattern>vendor/*</exclude-pattern>
  <exclude-pattern>tests/*</exclude-pattern>

  <arg name="extensions" value="php"/>
  <arg name="colors"/>
  <arg value="ps"/>

  <rule ref="WordPress">
    <exclude name="WordPress.Files.FileName"/>
    <exclude name="Universal.Files.SeparateFunctionsFromOO"/>
  </rule>

  <config name="testVersion" value="8.1-"/>
  <rule ref="PHPCompatibilityWP"/>
</ruleset>
```

- [ ] **Step 3: Install Composer dependencies**

```bash
composer install
```

Expected: `vendor/` populated; Action Scheduler available; WPCS standards installed.

- [ ] **Step 4: Verify autoload by adding a tiny check**

```bash
php -r 'require "vendor/autoload.php"; echo class_exists("PedimentAi\\Bootstrap") ? "OK\n" : "FAIL\n";'
```

Expected: `OK`.

- [ ] **Step 5: Verify Action Scheduler classes are reachable**

```bash
php -r 'require "vendor/autoload.php"; echo class_exists("ActionScheduler") ? "OK\n" : "FAIL\n";'
```

Expected: `OK`.

- [ ] **Step 6: Verify PHPCS runs**

```bash
composer lint
```

Expected: exits 0 or warnings only.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock phpcs.xml.dist
git commit -m "chore: composer + PSR-4 autoload + Action Scheduler dep"
```

### Task 3: wp-env config that mounts both this plugin AND the starter theme

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/.wp-env.json`

> **Why mount the theme?** This plugin's tests and editor work only make sense when the starter theme's blocks are present. wp-env can mount sibling repos via a relative path.

- [ ] **Step 1: Write .wp-env.json**

```json
{
  "core": "WordPress/WordPress#6.5",
  "phpVersion": "8.1",
  "plugins": ["."],
  "themes": ["../pediment"],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true,
    "PEDIMENT_AI_MOCK": true
  },
  "mappings": {
    "wp-content/mu-plugins/activate-starter-theme.php": "./tests/fixtures/mu-activate-theme.php"
  }
}
```

- [ ] **Step 2: Write the mu-plugin that activates the theme on first boot**

Create `tests/fixtures/mu-activate-theme.php`:

```php
<?php
/**
 * Forces pediment to be the active theme during wp-env.
 */

add_action( 'init', function () {
    if ( wp_get_theme()->get_stylesheet() !== 'pediment' ) {
        switch_theme( 'pediment' );
    }
}, 1 );
```

- [ ] **Step 3: Build the starter theme so blocks are registered**

```bash
( cd ../pediment && npm run build )
```

- [ ] **Step 4: Start wp-env and verify both are loaded**

```bash
npx wp-env start
```

Expected: WP starts on http://localhost:8888. The plugin is activated; the starter theme is active. (Verify in Appearance > Themes.)

- [ ] **Step 5: Stop wp-env**

```bash
npx wp-env stop
```

- [ ] **Step 6: Commit**

```bash
git add .wp-env.json tests/fixtures/mu-activate-theme.php
git commit -m "chore: wp-env mounts plugin + sibling starter theme"
```

### Task 4: NPM setup with @wordpress/scripts + TypeScript

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/package.json`
- Create: `/Users/jonas/Entwicklung/pediment-ai/tsconfig.json`

- [ ] **Step 1: Write package.json**

```json
{
  "name": "pediment-ai",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "start": "wp-scripts start --webpack-src-dir=editor --output-path=build",
    "build": "wp-scripts build --webpack-src-dir=editor --output-path=build",
    "lint:js": "wp-scripts lint-js editor/",
    "format": "wp-scripts format editor/",
    "test:js": "wp-scripts test-unit-js",
    "e2e": "playwright test",
    "env:start": "wp-env start",
    "env:stop": "wp-env stop"
  },
  "devDependencies": {
    "@playwright/test": "^1.45.0",
    "@wordpress/env": "^10.0.0",
    "@wordpress/scripts": "^28.0.0",
    "typescript": "^5.4.0"
  }
}
```

- [ ] **Step 2: Write tsconfig.json**

```json
{
  "extends": "@wordpress/scripts/tsconfig.json",
  "compilerOptions": {
    "baseUrl": ".",
    "paths": { "@/*": ["editor/*"] },
    "jsx": "react-jsx",
    "allowJs": true,
    "noEmit": true
  },
  "include": ["editor/**/*.ts", "editor/**/*.tsx"],
  "exclude": ["node_modules", "build"]
}
```

- [ ] **Step 3: Install npm deps**

```bash
npm install
```

- [ ] **Step 4: Create the entry stub and verify build runs**

```bash
mkdir -p editor
cat > editor/index.tsx <<'EOF'
// Editor entry — populated in Tasks 22+.
export {};
EOF
npm run build
```

Expected: completes; build/index.js exists.

- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json tsconfig.json editor/index.tsx
git commit -m "chore: @wordpress/scripts + TypeScript editor build"
```

### Task 5: PHPUnit setup

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/phpunit.xml.dist`
- Create: `/Users/jonas/Entwicklung/pediment-ai/tests/phpunit/bootstrap.php`
- Create: `/Users/jonas/Entwicklung/pediment-ai/tests/phpunit/SmokeTest.php`

- [ ] **Step 1: Write phpunit.xml.dist**

```xml
<?xml version="1.0"?>
<phpunit
  bootstrap="tests/phpunit/bootstrap.php"
  backupGlobals="false"
  colors="true"
  beStrictAboutCoversAnnotation="true"
  beStrictAboutOutputDuringTests="true"
  beStrictAboutTestsThatDoNotTestAnything="false"
  verbose="true">
  <testsuites>
    <testsuite name="plugin">
      <directory>tests/phpunit/</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 2: Write tests/phpunit/bootstrap.php**

```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
    require dirname( __DIR__, 2 ) . '/plugin.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 3: Write a smoke test**

```php
<?php
namespace PedimentAi\Tests;

class SmokeTest extends \WP_UnitTestCase {
    public function test_plugin_constants_defined(): void {
        $this->assertTrue( defined( 'PEDIMENT_AI_VERSION' ) );
    }

    public function test_bootstrap_class_exists(): void {
        $this->assertTrue( class_exists( '\\PedimentAi\\Bootstrap' ) );
    }
}
```

- [ ] **Step 4: Run the smoke test**

```bash
npx wp-env start
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml.dist tests/phpunit/bootstrap.php tests/phpunit/SmokeTest.php
git commit -m "test: phpunit setup with wp-env test container"
```

### Task 6: Playwright setup

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/playwright.config.ts`
- Create: `/Users/jonas/Entwicklung/pediment-ai/tests/e2e/smoke.spec.ts`

- [ ] **Step 1: Install Playwright browsers**

```bash
npx playwright install --with-deps chromium
```

- [ ] **Step 2: Write playwright.config.ts**

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'html',
  use: {
    baseURL: 'http://localhost:8888',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
```

- [ ] **Step 3: Write a smoke spec**

```ts
import { test, expect } from '@playwright/test';

test('admin login screen reachable', async ({ page }) => {
  await page.goto('/wp-login.php');
  await expect(page.locator('#user_login')).toBeVisible();
});
```

- [ ] **Step 4: Run E2E**

```bash
npm run e2e
```

Expected: 1 test passes.

- [ ] **Step 5: Commit**

```bash
git add playwright.config.ts tests/e2e/smoke.spec.ts
git commit -m "test: playwright e2e smoke setup"
```

### Task 7: GitHub Actions CI

**Files:**
- Create: `/Users/jonas/Entwicklung/pediment-ai/.github/workflows/ci.yml`

- [ ] **Step 1: Write the workflow**

```yaml
name: CI

on:
  pull_request:
  push:
    branches: [main]

jobs:
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          tools: composer
      - run: composer install --prefer-dist --no-progress
      - run: composer lint

  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          path: pediment-ai
      - uses: actions/checkout@v4
        with:
          repository: bergert/pediment
          ref: main
          token: ${{ secrets.STARTER_THEME_PAT }}
          path: pediment
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm
          cache-dependency-path: pediment-ai/package-lock.json
      - name: Build starter theme blocks
        run: |
          cd pediment
          npm ci
          npm run build
      - name: Install plugin deps
        run: |
          cd pediment-ai
          npm ci
      - name: Start wp-env (mounts both)
        run: cd pediment-ai && npm run env:start
      - name: Run PHPUnit
        run: cd pediment-ai && npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit
      - if: always()
        run: cd pediment-ai && npm run env:stop

  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          path: pediment-ai
      - uses: actions/checkout@v4
        with:
          repository: bergert/pediment
          ref: main
          token: ${{ secrets.STARTER_THEME_PAT }}
          path: pediment
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm
          cache-dependency-path: pediment-ai/package-lock.json
      - run: cd pediment && npm ci && npm run build
      - run: cd pediment-ai && npm ci
      - run: cd pediment-ai && npx playwright install --with-deps chromium
      - run: cd pediment-ai && npm run env:start
      - run: cd pediment-ai && npm run build
      - run: cd pediment-ai && npm run e2e
      - if: always()
        run: cd pediment-ai && npm run env:stop
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: pediment-ai/playwright-report/
```

> Note: `secrets.STARTER_THEME_PAT` is a personal access token with read access to the private theme repo. Set it under repo Settings → Secrets and variables → Actions before the first CI run.

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: workflow runs phpcs + phpunit + e2e against sibling theme"
```

---

## Phase 1: Anthropic client

### Task 8: AnthropicClient — Messages API wrapper

**Files:**
- Create: `src/Anthropic/Client.php`
- Create: `tests/phpunit/Anthropic/ClientTest.php`

> **Design:** Thin wrapper around `wp_remote_post` against `https://api.anthropic.com/v1/messages`. No official SDK exists for PHP. The class exposes one method `messages( array $args ): array` returning the decoded body. Errors are returned as `WP_Error`. Streaming is NOT supported in v1; calls are blocking.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\Client;

class ClientTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        remove_all_filters( 'pre_http_request' );
    }

    public function test_sends_message_with_correct_headers_and_body(): void {
        $captured = null;
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
            $captured = compact( 'args', 'url' );
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [
                    'id'           => 'msg_01',
                    'type'         => 'message',
                    'role'         => 'assistant',
                    'model'        => 'claude-sonnet-4-6',
                    'content'      => [ [ 'type' => 'text', 'text' => 'hi' ] ],
                    'stop_reason'  => 'end_turn',
                    'usage'        => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
                ] ),
                'headers' => [],
            ];
        }, 10, 3 );

        $client = new Client( 'test-key', 'https://api.anthropic.com' );
        $body   = $client->messages( [
            'model'     => 'claude-sonnet-4-6',
            'max_tokens' => 1024,
            'messages'  => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
        ] );

        $this->assertSame( 'msg_01', $body['id'] );
        $this->assertSame( 'https://api.anthropic.com/v1/messages', $captured['url'] );
        $this->assertSame( 'POST', $captured['args']['method'] );
        $this->assertSame( 'test-key',         $captured['args']['headers']['x-api-key'] );
        $this->assertSame( '2023-06-01',       $captured['args']['headers']['anthropic-version'] );
        $this->assertSame( 'application/json', $captured['args']['headers']['content-type'] );

        $sent_body = json_decode( $captured['args']['body'], true );
        $this->assertSame( 'claude-sonnet-4-6', $sent_body['model'] );
    }

    public function test_returns_wp_error_on_http_error(): void {
        add_filter( 'pre_http_request', function () {
            return new \WP_Error( 'http_request_failed', 'Connection refused' );
        } );

        $client = new Client( 'test-key' );
        $result = $client->messages( [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 100,
            'messages'   => [],
        ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_returns_wp_error_on_anthropic_4xx(): void {
        add_filter( 'pre_http_request', function () {
            return [
                'response' => [ 'code' => 400 ],
                'body'     => wp_json_encode( [
                    'type'  => 'error',
                    'error' => [ 'type' => 'invalid_request_error', 'message' => 'Invalid model' ],
                ] ),
                'headers' => [],
            ];
        } );

        $client = new Client( 'test-key' );
        $result = $client->messages( [ 'model' => 'bad', 'max_tokens' => 1, 'messages' => [] ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'pediment_ai_anthropic_400', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 'invalid_request_error', $data['error_type'] );
    }

    public function test_retries_once_on_429(): void {
        $calls = 0;
        add_filter( 'pre_http_request', function () use ( &$calls ) {
            $calls++;
            if ( $calls === 1 ) {
                return [
                    'response' => [ 'code' => 429 ],
                    'body'     => wp_json_encode( [ 'type' => 'error', 'error' => [ 'type' => 'rate_limit', 'message' => 'slow down' ] ] ),
                    'headers'  => [],
                ];
            }
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [ 'id' => 'msg_retry', 'content' => [], 'usage' => [ 'input_tokens' => 1, 'output_tokens' => 1 ] ] ),
                'headers'  => [],
            ];
        } );

        $client = new Client( 'test-key' );
        $body   = $client->messages( [ 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1, 'messages' => [] ] );
        $this->assertSame( 'msg_retry', $body['id'] );
        $this->assertSame( 2, $calls );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ClientTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Anthropic/Client.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Client {
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_TIMEOUT = 90;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int    $timeout = self::DEFAULT_TIMEOUT
    ) {}

    /**
     * Calls POST /v1/messages.
     *
     * @param array<string,mixed> $args Anthropic Messages API request body.
     * @return array<string,mixed>|\WP_Error Decoded response body or WP_Error.
     */
    public function messages( array $args ) {
        return $this->postWithRetry( '/v1/messages', $args );
    }

    /**
     * @param string              $path
     * @param array<string,mixed> $args
     * @param int                 $attempt
     * @return array<string,mixed>|\WP_Error
     */
    private function postWithRetry( string $path, array $args, int $attempt = 0 ) {
        $response = wp_remote_post(
            rtrim( $this->baseUrl, '/' ) . $path,
            [
                'timeout' => $this->timeout,
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                'body'    => wp_json_encode( $args ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $body   = json_decode( $raw, true );

        if ( $status >= 200 && $status < 300 ) {
            return is_array( $body ) ? $body : [];
        }

        if ( ( $status === 429 || ( $status >= 500 && $status < 600 ) ) && $attempt < 1 ) {
            usleep( 750_000 ); // 0.75s backoff
            return $this->postWithRetry( $path, $args, $attempt + 1 );
        }

        $error_type = is_array( $body ) && isset( $body['error']['type'] ) ? (string) $body['error']['type'] : 'unknown';
        $message    = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Anthropic API error';

        return new \WP_Error(
            'pediment_ai_anthropic_' . $status,
            $message,
            [ 'error_type' => $error_type, 'status' => $status ]
        );
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ClientTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Anthropic/Client.php tests/phpunit/Anthropic/ClientTest.php
git commit -m "feat(anthropic): Client wraps Messages API with retry-on-429/5xx"
```

---

## Phase 2: Block tree + schema

### Task 9: BlockTree\Parser

**Files:**
- Create: `src/BlockTree/Parser.php`
- Create: `tests/phpunit/BlockTree/ParserTest.php`

> **Shape:** WP's native `parse_blocks()` returns arrays with keys `blockName`, `attrs`, `innerBlocks`, `innerHTML`, `innerContent`. Our internal tree uses `{name, attributes, innerBlocks}` — simpler, JSON-friendly, AI-friendly. Parser converts WP → ours.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Parser;

class ParserTest extends \WP_UnitTestCase {
    public function test_parses_single_block(): void {
        $tree = ( new Parser() )->parse( '<!-- wp:pediment/hero {"headline":"Hi"} /-->' );
        $this->assertCount( 1, $tree );
        $this->assertSame( 'pediment/hero', $tree[0]['name'] );
        $this->assertSame( 'Hi', $tree[0]['attributes']['headline'] );
        $this->assertSame( [], $tree[0]['innerBlocks'] );
    }

    public function test_parses_nested_blocks(): void {
        $tree = ( new Parser() )->parse(
            '<!-- wp:pediment/faq -->' .
            '<!-- wp:pediment/faq-item {"question":"Q","answer":"A"} /-->' .
            '<!-- /wp:pediment/faq -->'
        );
        $this->assertSame( 'pediment/faq', $tree[0]['name'] );
        $this->assertCount( 1, $tree[0]['innerBlocks'] );
        $this->assertSame( 'pediment/faq-item', $tree[0]['innerBlocks'][0]['name'] );
        $this->assertSame( 'Q', $tree[0]['innerBlocks'][0]['attributes']['question'] );
    }

    public function test_filters_out_freeform_whitespace_blocks(): void {
        $tree = ( new Parser() )->parse(
            "\n\n<!-- wp:pediment/hero /-->\n\n<!-- wp:pediment/cta /-->\n"
        );
        $this->assertCount( 2, $tree );
    }

    public function test_returns_empty_array_for_empty_content(): void {
        $this->assertSame( [], ( new Parser() )->parse( '' ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ParserTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/BlockTree/Parser.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Parser {
    /**
     * Parse Gutenberg block markup into a simple JSON tree.
     *
     * @param string $content
     * @return array<int, array{name:string, attributes:array<string,mixed>, innerBlocks:array}>
     */
    public function parse( string $content ): array {
        if ( '' === trim( $content ) ) {
            return [];
        }
        return $this->map( parse_blocks( $content ) );
    }

    /**
     * @param array<int, array<string,mixed>> $blocks
     * @return array<int, array{name:string, attributes:array, innerBlocks:array}>
     */
    private function map( array $blocks ): array {
        $out = [];
        foreach ( $blocks as $block ) {
            $name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            if ( '' === $name ) {
                continue; // skip freeform / whitespace blocks
            }
            $out[] = [
                'name'        => $name,
                'attributes'  => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
                'innerBlocks' => $this->map( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [] ),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ParserTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/BlockTree/Parser.php tests/phpunit/BlockTree/ParserTest.php
git commit -m "feat(blocktree): Parser — post_content to JSON tree"
```

### Task 10: BlockTree\Serializer

**Files:**
- Create: `src/BlockTree/Serializer.php`
- Create: `tests/phpunit/BlockTree/SerializerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Parser;
use PedimentAi\BlockTree\Serializer;

class SerializerTest extends \WP_UnitTestCase {
    public function test_serializes_single_block(): void {
        $markup = ( new Serializer() )->serialize( [
            [
                'name'        => 'pediment/hero',
                'attributes'  => [ 'headline' => 'Hi' ],
                'innerBlocks' => [],
            ],
        ] );

        $this->assertStringContainsString( '<!-- wp:pediment/hero', $markup );
        $this->assertStringContainsString( '"headline":"Hi"', $markup );
        $this->assertStringContainsString( '/-->', $markup );
    }

    public function test_serializes_nested_blocks(): void {
        $markup = ( new Serializer() )->serialize( [
            [
                'name'        => 'pediment/faq',
                'attributes'  => [],
                'innerBlocks' => [
                    [ 'name' => 'pediment/faq-item', 'attributes' => [ 'question' => 'Q', 'answer' => 'A' ], 'innerBlocks' => [] ],
                ],
            ],
        ] );

        $this->assertStringContainsString( '<!-- wp:pediment/faq -->',       $markup );
        $this->assertStringContainsString( '<!-- wp:pediment/faq-item',      $markup );
        $this->assertStringContainsString( '<!-- /wp:pediment/faq -->',      $markup );
    }

    public function test_round_trip_via_parser(): void {
        $original = '<!-- wp:pediment/hero {"headline":"Hello"} /-->';
        $tree     = ( new Parser() )->parse( $original );
        $back     = ( new Serializer() )->serialize( $tree );
        $reparsed = ( new Parser() )->parse( $back );

        $this->assertSame( $tree, $reparsed );
    }

    public function test_returns_empty_string_for_empty_tree(): void {
        $this->assertSame( '', ( new Serializer() )->serialize( [] ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter SerializerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/BlockTree/Serializer.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Serializer {
    /**
     * Serialize a JSON tree into Gutenberg block markup.
     *
     * @param array<int, array{name:string, attributes:array, innerBlocks:array}> $tree
     * @return string
     */
    public function serialize( array $tree ): string {
        if ( [] === $tree ) {
            return '';
        }
        $wp_blocks = array_map( [ $this, 'toWpBlock' ], $tree );
        return serialize_blocks( $wp_blocks );
    }

    /**
     * @param array{name:string, attributes:array, innerBlocks:array} $node
     * @return array<string,mixed> WP's parsed-block shape
     */
    private function toWpBlock( array $node ): array {
        $inner_blocks = array_map( [ $this, 'toWpBlock' ], $node['innerBlocks'] ?? [] );
        return [
            'blockName'    => $node['name'],
            'attrs'        => is_array( $node['attributes'] ?? null ) ? $node['attributes'] : [],
            'innerBlocks'  => $inner_blocks,
            'innerHTML'    => '',
            'innerContent' => array_fill( 0, max( 1, count( $inner_blocks ) ), null ),
        ];
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter SerializerTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/BlockTree/Serializer.php tests/phpunit/BlockTree/SerializerTest.php
git commit -m "feat(blocktree): Serializer — JSON tree to Gutenberg markup with round-trip"
```

### Task 11: BlockTree\Validator

**Files:**
- Create: `src/BlockTree/Validator.php`
- Create: `tests/phpunit/BlockTree/ValidatorTest.php`

> **Scope:** v1 validates block-name presence, container/child relationships, and `attributes` being an object. Deep attribute-type validation is intentionally out of scope — WP/React coerce mismatches and the cost of false negatives is low. Validator returns an array of error strings; empty means valid.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\BlockTree;

use PedimentAi\BlockTree\Validator;

class ValidatorTest extends \WP_UnitTestCase {
    private function schema(): array {
        return [
            'pediment/hero' => [
                'description' => 'Hero',
                'attributes'  => [ 'headline' => [ 'type' => 'string' ] ],
                'allowsInnerBlocks' => false,
            ],
            'pediment/faq' => [
                'description'        => 'FAQ',
                'attributes'         => [],
                'allowsInnerBlocks'  => true,
                'allowedChildBlocks' => [ 'pediment/faq-item' ],
            ],
            'pediment/faq-item' => [
                'description' => 'FAQ item',
                'attributes'  => [],
                'allowsInnerBlocks' => false,
            ],
        ];
    }

    public function test_valid_tree_passes(): void {
        $errors = ( new Validator( $this->schema() ) )->validate( [
            [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ],
        ] );
        $this->assertSame( [], $errors );
    }

    public function test_unknown_block_fails(): void {
        $errors = ( new Validator( $this->schema() ) )->validate( [
            [ 'name' => 'pediment/nope', 'attributes' => [], 'innerBlocks' => [] ],
        ] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'pediment/nope', $errors[0] );
    }

    public function test_inner_blocks_on_non_container_fail(): void {
        $errors = ( new Validator( $this->schema() ) )->validate( [
            [
                'name'        => 'pediment/hero',
                'attributes'  => [],
                'innerBlocks' => [
                    [ 'name' => 'pediment/faq-item', 'attributes' => [], 'innerBlocks' => [] ],
                ],
            ],
        ] );
        $this->assertNotEmpty( $errors );
    }

    public function test_disallowed_child_fails(): void {
        $errors = ( new Validator( $this->schema() ) )->validate( [
            [
                'name'        => 'pediment/faq',
                'attributes'  => [],
                'innerBlocks' => [
                    [ 'name' => 'pediment/hero', 'attributes' => [], 'innerBlocks' => [] ],
                ],
            ],
        ] );
        $this->assertNotEmpty( $errors );
    }

    public function test_attributes_not_object_fails(): void {
        $errors = ( new Validator( $this->schema() ) )->validate( [
            [ 'name' => 'pediment/hero', 'attributes' => 'oops', 'innerBlocks' => [] ],
        ] );
        $this->assertNotEmpty( $errors );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ValidatorTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/BlockTree/Validator.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\BlockTree;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Validator {
    /**
     * @param array<string, array<string,mixed>> $schema  blockName => { attributes, allowsInnerBlocks, allowedChildBlocks? }
     */
    public function __construct( private readonly array $schema ) {}

    /**
     * @param array<int, array<string,mixed>> $tree
     * @return string[] errors; empty means valid
     */
    public function validate( array $tree, string $path = '' ): array {
        $errors = [];
        foreach ( $tree as $i => $node ) {
            $here = $path . '[' . $i . ']';

            $name = isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : '';
            if ( '' === $name ) {
                $errors[] = $here . ': missing block name';
                continue;
            }

            if ( ! isset( $this->schema[ $name ] ) ) {
                $errors[] = sprintf( '%s: unknown block "%s"', $here, $name );
                continue;
            }

            if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
                $errors[] = $here . ': attributes must be an object';
            }

            $spec   = $this->schema[ $name ];
            $inner  = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ? $node['innerBlocks'] : [];
            $allows = ! empty( $spec['allowsInnerBlocks'] );

            if ( ! empty( $inner ) && ! $allows ) {
                $errors[] = sprintf( '%s: block "%s" does not allow inner blocks', $here, $name );
                continue;
            }

            if ( ! empty( $spec['allowedChildBlocks'] ) ) {
                foreach ( $inner as $j => $child ) {
                    $cname = isset( $child['name'] ) ? (string) $child['name'] : '';
                    if ( '' !== $cname && ! in_array( $cname, (array) $spec['allowedChildBlocks'], true ) ) {
                        $errors[] = sprintf( '%s.innerBlocks[%d]: "%s" not in allowedChildBlocks of "%s"', $here, $j, $cname, $name );
                    }
                }
            }

            if ( ! empty( $inner ) ) {
                $errors = array_merge( $errors, $this->validate( $inner, $here . '.innerBlocks' ) );
            }
        }
        return $errors;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ValidatorTest
```

Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/BlockTree/Validator.php tests/phpunit/BlockTree/ValidatorTest.php
git commit -m "feat(blocktree): Validator — checks name presence and parent/child rules"
```

### Task 12: Anthropic\SchemaBuilder

**Files:**
- Create: `src/Anthropic/SchemaBuilder.php`
- Create: `tests/phpunit/Anthropic/SchemaBuilderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\SchemaBuilder;

class SchemaBuilderTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Wipe any cached schema.
        delete_transient( 'pediment_ai_schema' );

        // Register a fake starter block for the test.
        register_block_type( 'pediment/test-block', [
            'attributes'  => [ 'foo' => [ 'type' => 'string', 'default' => '' ] ],
            'description' => 'A test block.',
        ] );
    }

    public function tearDown(): void {
        unregister_block_type( 'pediment/test-block' );
        delete_transient( 'pediment_ai_schema' );
        parent::tearDown();
    }

    public function test_includes_starter_blocks(): void {
        $schema = ( new SchemaBuilder() )->build();
        $this->assertArrayHasKey( 'pediment/test-block', $schema['blocks'] );
        $this->assertSame( 'A test block.', $schema['blocks']['pediment/test-block']['description'] );
    }

    public function test_includes_curated_core_blocks(): void {
        $schema = ( new SchemaBuilder() )->build();
        $this->assertArrayHasKey( 'core/paragraph', $schema['blocks'] );
        $this->assertArrayHasKey( 'core/heading',   $schema['blocks'] );
    }

    public function test_excludes_unrelated_blocks(): void {
        // Pretend a totally unrelated block exists.
        register_block_type( 'someplugin/widget', [ 'attributes' => [], 'description' => 'x' ] );
        $schema = ( new SchemaBuilder() )->build();
        $this->assertArrayNotHasKey( 'someplugin/widget', $schema['blocks'] );
        unregister_block_type( 'someplugin/widget' );
    }

    public function test_caches_result_in_transient(): void {
        $builder = new SchemaBuilder();
        $first   = $builder->build();
        $cached  = get_transient( 'pediment_ai_schema' );
        $this->assertNotFalse( $cached );
        $this->assertSame( $first, $cached );
    }

    public function test_invalidate_clears_transient(): void {
        ( new SchemaBuilder() )->build();
        $this->assertNotFalse( get_transient( 'pediment_ai_schema' ) );
        SchemaBuilder::invalidate();
        $this->assertFalse( get_transient( 'pediment_ai_schema' ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter SchemaBuilderTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Anthropic/SchemaBuilder.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SchemaBuilder {
    public const TRANSIENT_KEY = 'pediment_ai_schema';
    private const TRANSIENT_TTL = HOUR_IN_SECONDS;

    private const CORE_ALLOWLIST = [
        'core/paragraph' => [
            'description' => 'A paragraph of body text.',
            'attributes'  => [ 'content' => [ 'type' => 'string' ] ],
            'allowsInnerBlocks' => false,
        ],
        'core/heading' => [
            'description' => 'A heading.',
            'attributes'  => [
                'content' => [ 'type' => 'string' ],
                'level'   => [ 'type' => 'number', 'default' => 2 ],
            ],
            'allowsInnerBlocks' => false,
        ],
        'core/list' => [
            'description' => 'A bulleted or ordered list. Contains core/list-item children.',
            'attributes'  => [ 'ordered' => [ 'type' => 'boolean', 'default' => false ] ],
            'allowsInnerBlocks'  => true,
            'allowedChildBlocks' => [ 'core/list-item' ],
        ],
        'core/list-item' => [
            'description' => 'A single list item.',
            'attributes'  => [ 'content' => [ 'type' => 'string' ] ],
            'allowsInnerBlocks' => false,
        ],
        'core/image' => [
            'description' => 'A standalone image.',
            'attributes'  => [
                'id'  => [ 'type' => 'number' ],
                'url' => [ 'type' => 'string' ],
                'alt' => [ 'type' => 'string' ],
            ],
            'allowsInnerBlocks' => false,
        ],
        'core/separator' => [
            'description' => 'A horizontal separator.',
            'attributes'  => [],
            'allowsInnerBlocks' => false,
        ],
    ];

    public function build( bool $forceFresh = false ): array {
        if ( ! $forceFresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $blocks = self::CORE_ALLOWLIST;

        $registry = \WP_Block_Type_Registry::get_instance();
        foreach ( $registry->get_all_registered() as $name => $type ) {
            if ( ! preg_match( '#^(pediment|client)/#', (string) $name ) ) {
                continue;
            }

            $description = isset( $type->description ) ? (string) $type->description : '';
            $attributes  = isset( $type->attributes ) && is_array( $type->attributes ) ? $type->attributes : [];

            // Block must declare description + explicit attributes to be AI-aware.
            if ( '' === $description ) {
                continue;
            }

            $parent = isset( $type->parent ) && is_array( $type->parent ) ? $type->parent : [];
            $allows_inner = ! empty( $type->supports['__experimentalLayout'] )
                || ! empty( $type->supports['inserter'] )
                || $this->guessAllowsInnerBlocks( (string) $name );

            $blocks[ $name ] = [
                'description'       => $description,
                'attributes'        => $attributes,
                'allowsInnerBlocks' => (bool) $allows_inner,
            ];

            // Mark parent constraints (child blocks).
            if ( ! empty( $parent ) ) {
                $blocks[ $name ]['onlyAllowedAsChildOf'] = $parent;
            }
        }

        // Second pass: derive `allowedChildBlocks` from parent declarations.
        foreach ( $blocks as $name => $info ) {
            if ( empty( $info['onlyAllowedAsChildOf'] ) ) {
                continue;
            }
            foreach ( (array) $info['onlyAllowedAsChildOf'] as $parent ) {
                if ( isset( $blocks[ $parent ] ) ) {
                    $blocks[ $parent ]['allowedChildBlocks'][] = $name;
                    $blocks[ $parent ]['allowsInnerBlocks']    = true;
                }
            }
            unset( $blocks[ $name ]['onlyAllowedAsChildOf'] );
        }

        $schema = [ 'blocks' => $blocks ];
        set_transient( self::TRANSIENT_KEY, $schema, self::TRANSIENT_TTL );
        return $schema;
    }

    public static function invalidate(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    private function guessAllowsInnerBlocks( string $name ): bool {
        // Heuristic: containers commonly named *-list, *-group, *-grid, or known names.
        return in_array( $name, [ 'pediment/faq', 'pediment/prose' ], true );
    }
}
```

- [ ] **Step 4: Hook invalidation in Bootstrap (Tasks 12 + 25 wire this together; for now, add a temporary hook)**

Append to `src/Bootstrap.php`'s `register()` method:

```php
add_action( 'register_block_type_args', static function () {
    \PedimentAi\Anthropic\SchemaBuilder::invalidate();
} );
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter SchemaBuilderTest
```

Expected: 5 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Anthropic/SchemaBuilder.php src/Bootstrap.php tests/phpunit/Anthropic/SchemaBuilderTest.php
git commit -m "feat(anthropic): SchemaBuilder — runtime block schema with transient cache"
```

### Task 13: Anthropic\ToolUseParser

**Files:**
- Create: `src/Anthropic/ToolUseParser.php`
- Create: `tests/phpunit/Anthropic/ToolUseParserTest.php`

> **Anthropic response shape:** the `content` array contains `text`, `tool_use`, `server_tool_use`, and `web_fetch_tool_result` blocks. We pull out our client tool (`emit_page` or `emit_block`) input and any `web_fetch` events.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Anthropic;

use PedimentAi\Anthropic\ToolUseParser;

class ToolUseParserTest extends \WP_UnitTestCase {
    public function test_extracts_emit_page_tool_input(): void {
        $result = ( new ToolUseParser() )->parse( [
            'content' => [
                [ 'type' => 'text', 'text' => 'Here you go.' ],
                [
                    'type'  => 'tool_use',
                    'id'    => 'tu_1',
                    'name'  => 'emit_page',
                    'input' => [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ] ] ],
                ],
            ],
        ] );

        $this->assertSame( 'emit_page', $result['tool'] );
        $this->assertSame( 'pediment/hero', $result['input']['blocks'][0]['name'] );
        $this->assertSame( [], $result['urls_fetched'] );
    }

    public function test_extracts_emit_block_tool_input(): void {
        $result = ( new ToolUseParser() )->parse( [
            'content' => [
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_block', 'input' => [ 'attributes' => [ 'headline' => 'X' ], 'innerBlocks' => [] ] ],
            ],
        ] );

        $this->assertSame( 'emit_block', $result['tool'] );
        $this->assertSame( 'X', $result['input']['attributes']['headline'] );
    }

    public function test_collects_web_fetch_urls(): void {
        $result = ( new ToolUseParser() )->parse( [
            'content' => [
                [ 'type' => 'server_tool_use', 'id' => 'st_1', 'name' => 'web_fetch', 'input' => [ 'url' => 'https://example.com/a' ] ],
                [ 'type' => 'web_fetch_tool_result', 'tool_use_id' => 'st_1', 'content' => [ [ 'type' => 'text', 'text' => '...' ] ] ],
                [ 'type' => 'server_tool_use', 'id' => 'st_2', 'name' => 'web_fetch', 'input' => [ 'url' => 'https://example.com/b' ] ],
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page', 'input' => [ 'blocks' => [] ] ],
            ],
        ] );

        $this->assertSame( [ 'https://example.com/a', 'https://example.com/b' ], $result['urls_fetched'] );
    }

    public function test_returns_null_tool_when_no_tool_use_present(): void {
        $result = ( new ToolUseParser() )->parse( [
            'content' => [ [ 'type' => 'text', 'text' => 'No tool call.' ] ],
        ] );

        $this->assertNull( $result['tool'] );
        $this->assertSame( [], $result['urls_fetched'] );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ToolUseParserTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Anthropic/ToolUseParser.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Anthropic;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ToolUseParser {
    /**
     * @param array<string,mixed> $response Full Anthropic Messages response body.
     * @return array{tool: ?string, input: array<string,mixed>, urls_fetched: string[]}
     */
    public function parse( array $response ): array {
        $tool         = null;
        $input        = [];
        $urls_fetched = [];

        $content = $response['content'] ?? [];
        if ( ! is_array( $content ) ) {
            return [ 'tool' => null, 'input' => [], 'urls_fetched' => [] ];
        }

        foreach ( $content as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            $type = (string) ( $block['type'] ?? '' );

            if ( 'tool_use' === $type && in_array( $block['name'] ?? '', [ 'emit_page', 'emit_block' ], true ) ) {
                $tool  = (string) $block['name'];
                $input = is_array( $block['input'] ?? null ) ? $block['input'] : [];
                continue;
            }

            if ( 'server_tool_use' === $type && ( $block['name'] ?? '' ) === 'web_fetch' ) {
                $url = (string) ( $block['input']['url'] ?? '' );
                if ( '' !== $url ) {
                    $urls_fetched[] = $url;
                }
            }
        }

        return [ 'tool' => $tool, 'input' => $input, 'urls_fetched' => $urls_fetched ];
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ToolUseParserTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Anthropic/ToolUseParser.php tests/phpunit/Anthropic/ToolUseParserTest.php
git commit -m "feat(anthropic): ToolUseParser — extracts tool_use + web_fetch URLs"
```

---

## Phase 3: Jobs (DB + Action Scheduler)

### Task 14: Schema\tables — DB installer

**Files:**
- Create: `src/Schema/tables.php`
- Modify: `plugin.php` (call installer on activation + upgrade)
- Create: `tests/phpunit/Schema/TablesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Schema;

class TablesTest extends \WP_UnitTestCase {
    public function test_tables_exist_after_install(): void {
        \pediment_ai_install_tables();
        global $wpdb;
        $jobs  = $wpdb->prefix . 'pediment_ai_jobs';
        $usage = $wpdb->prefix . 'pediment_ai_usage';
        $this->assertSame( $jobs,  $wpdb->get_var( "SHOW TABLES LIKE '{$jobs}'" ) );
        $this->assertSame( $usage, $wpdb->get_var( "SHOW TABLES LIKE '{$usage}'" ) );
    }

    public function test_install_is_idempotent(): void {
        \pediment_ai_install_tables();
        \pediment_ai_install_tables();
        global $wpdb;
        $this->assertSame( "0", $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pediment_ai_jobs" ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter TablesTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Schema/tables.php**

```php
<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function pediment_ai_install_tables(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $jobs    = $wpdb->prefix . 'pediment_ai_jobs';
    $usage   = $wpdb->prefix . 'pediment_ai_usage';

    $sql_jobs = "CREATE TABLE {$jobs} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        kind varchar(20) NOT NULL,
        status varchar(20) NOT NULL,
        payload longtext NOT NULL,
        events_json longtext NULL,
        result_json longtext NULL,
        error_message text NULL,
        created_at datetime NOT NULL,
        completed_at datetime NULL,
        PRIMARY KEY  (id),
        KEY status_idx (status),
        KEY user_idx (user_id)
    ) {$charset};";

    $sql_usage = "CREATE TABLE {$usage} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        kind varchar(20) NOT NULL,
        model varchar(60) NOT NULL,
        input_tokens int NOT NULL DEFAULT 0,
        output_tokens int NOT NULL DEFAULT 0,
        cache_read_tokens int NOT NULL DEFAULT 0,
        cache_write_tokens int NOT NULL DEFAULT 0,
        web_fetch_count int NOT NULL DEFAULT 0,
        cost_usd decimal(10,6) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY user_idx (user_id),
        KEY created_idx (created_at)
    ) {$charset};";

    dbDelta( $sql_jobs );
    dbDelta( $sql_usage );

    update_option( 'pediment_ai_db_version', PEDIMENT_AI_VERSION );
}
```

- [ ] **Step 4: Wire activation hook in plugin.php**

Add to `plugin.php` before the final `add_action( 'plugins_loaded', ... )`:

```php
require_once __DIR__ . '/src/Schema/tables.php';
register_activation_hook( PEDIMENT_AI_PLUGIN_FILE, 'pediment_ai_install_tables' );
add_action( 'plugins_loaded', static function () {
    if ( get_option( 'pediment_ai_db_version' ) !== PEDIMENT_AI_VERSION ) {
        pediment_ai_install_tables();
    }
}, 5 );
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter TablesTest
```

Expected: 2 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Schema/tables.php plugin.php tests/phpunit/Schema/TablesTest.php
git commit -m "feat(schema): wp_pediment_ai_jobs + wp_pediment_ai_usage tables"
```

### Task 15: Jobs\JobStore

**Files:**
- Create: `src/Jobs/JobStore.php`
- Create: `tests/phpunit/Jobs/JobStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Jobs;

use PedimentAi\Jobs\JobStore;

class JobStoreTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wpdb;
        $wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_jobs" );
    }

    public function test_create_returns_id_and_stores_payload(): void {
        $store = new JobStore();
        $id    = $store->create( 1, 'compose', [ 'prompt' => 'Hi' ] );
        $this->assertGreaterThan( 0, $id );
        $job = $store->getById( $id );
        $this->assertSame( 1, $job['user_id'] );
        $this->assertSame( 'compose', $job['kind'] );
        $this->assertSame( 'queued',  $job['status'] );
        $this->assertSame( 'Hi',      $job['payload']['prompt'] );
    }

    public function test_update_status(): void {
        $store = new JobStore();
        $id = $store->create( 1, 'compose', [] );
        $store->updateStatus( $id, 'composing' );
        $this->assertSame( 'composing', $store->getById( $id )['status'] );
    }

    public function test_append_event_collects_urls(): void {
        $store = new JobStore();
        $id = $store->create( 1, 'compose', [] );
        $store->appendEvent( $id, [ 'url_fetched' => 'https://example.com/a' ] );
        $store->appendEvent( $id, [ 'url_fetched' => 'https://example.com/b' ] );
        $events = $store->getById( $id )['events'];
        $this->assertCount( 2, $events );
        $this->assertSame( 'https://example.com/a', $events[0]['url_fetched'] );
    }

    public function test_complete_with_result(): void {
        $store = new JobStore();
        $id = $store->create( 1, 'compose', [] );
        $store->complete( $id, [ 'blocks' => [] ] );
        $job = $store->getById( $id );
        $this->assertSame( 'complete', $job['status'] );
        $this->assertSame( [],         $job['result']['blocks'] );
        $this->assertNotNull( $job['completed_at'] );
    }

    public function test_fail_with_error(): void {
        $store = new JobStore();
        $id = $store->create( 1, 'compose', [] );
        $store->fail( $id, 'API down' );
        $job = $store->getById( $id );
        $this->assertSame( 'error',    $job['status'] );
        $this->assertSame( 'API down', $job['error_message'] );
    }

    public function test_get_by_id_returns_null_for_missing(): void {
        $this->assertNull( ( new JobStore() )->getById( 99999 ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter JobStoreTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Jobs/JobStore.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class JobStore {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pediment_ai_jobs';
    }

    public function create( int $user_id, string $kind, array $payload ): int {
        global $wpdb;
        $wpdb->insert( $this->table, [
            'user_id'     => $user_id,
            'kind'        => $kind,
            'status'      => 'queued',
            'payload'     => wp_json_encode( $payload ),
            'events_json' => wp_json_encode( [] ),
            'created_at'  => current_time( 'mysql', true ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public function updateStatus( int $id, string $status ): void {
        global $wpdb;
        $wpdb->update( $this->table, [ 'status' => $status ], [ 'id' => $id ] );
    }

    public function appendEvent( int $id, array $event ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT events_json FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) { return; }
        $events = json_decode( (string) $row['events_json'], true );
        if ( ! is_array( $events ) ) { $events = []; }
        $events[] = $event;
        $wpdb->update( $this->table, [ 'events_json' => wp_json_encode( $events ) ], [ 'id' => $id ] );
    }

    public function complete( int $id, array $result ): void {
        global $wpdb;
        $wpdb->update( $this->table, [
            'status'       => 'complete',
            'result_json'  => wp_json_encode( $result ),
            'completed_at' => current_time( 'mysql', true ),
        ], [ 'id' => $id ] );
    }

    public function fail( int $id, string $message ): void {
        global $wpdb;
        $wpdb->update( $this->table, [
            'status'        => 'error',
            'error_message' => $message,
            'completed_at'  => current_time( 'mysql', true ),
        ], [ 'id' => $id ] );
    }

    public function getById( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) { return null; }
        return [
            'id'            => (int) $row['id'],
            'user_id'       => (int) $row['user_id'],
            'kind'          => (string) $row['kind'],
            'status'        => (string) $row['status'],
            'payload'       => json_decode( (string) $row['payload'], true ) ?: [],
            'events'        => json_decode( (string) ( $row['events_json'] ?? '[]' ), true ) ?: [],
            'result'        => $row['result_json'] ? ( json_decode( (string) $row['result_json'], true ) ?: [] ) : null,
            'error_message' => $row['error_message'] ? (string) $row['error_message'] : null,
            'created_at'    => (string) $row['created_at'],
            'completed_at'  => $row['completed_at'] ? (string) $row['completed_at'] : null,
        ];
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter JobStoreTest
```

Expected: 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/JobStore.php tests/phpunit/Jobs/JobStoreTest.php
git commit -m "feat(jobs): JobStore — CRUD on wp_pediment_ai_jobs"
```

### Task 16: Jobs\ComposeJob — Action Scheduler worker

**Files:**
- Create: `src/Anthropic/ProviderInterface.php`
- Modify: `src/Anthropic/Client.php` (implement interface)
- Create: `src/Jobs/ComposeJob.php`
- Modify: `src/Bootstrap.php` (register Action Scheduler hook)
- Create: `tests/phpunit/Jobs/ComposeJobTest.php`

- [ ] **Step 1: Create the provider interface**

`src/Anthropic/ProviderInterface.php`:

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Anthropic;

interface ProviderInterface {
    /**
     * @param array<string,mixed> $args Anthropic Messages request body.
     * @return array<string,mixed>|\WP_Error
     */
    public function messages( array $args );
}
```

Update `src/Anthropic/Client.php` class header to `final class Client implements ProviderInterface`.

- [ ] **Step 2: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Jobs;

use PedimentAi\Anthropic\ProviderInterface;
use PedimentAi\Jobs\ComposeJob;
use PedimentAi\Jobs\JobStore;

class StubProvider implements ProviderInterface {
    public array $sentArgs = [];
    public function __construct( private readonly array $response ) {}
    public function messages( array $args ) {
        $this->sentArgs = $args;
        return $this->response;
    }
}

class ComposeJobTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wpdb;
        $wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_jobs" );
        \PedimentAi\Anthropic\SchemaBuilder::invalidate();
    }

    public function test_successful_compose_writes_result_and_urls(): void {
        $store    = new JobStore();
        $provider = new StubProvider( [
            'content' => [
                [ 'type' => 'server_tool_use', 'id' => 'st_1', 'name' => 'web_fetch', 'input' => [ 'url' => 'https://acme.example/' ] ],
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page',
                  'input' => [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Hi' ], 'innerBlocks' => [] ] ] ] ],
            ],
            'usage' => [ 'input_tokens' => 100, 'output_tokens' => 50 ],
            'model' => 'claude-sonnet-4-6',
        ] );

        register_block_type( 'pediment/hero', [ 'attributes' => [ 'headline' => [ 'type' => 'string' ] ], 'description' => 'Hero' ] );

        $id = $store->create( 1, 'compose', [ 'prompt' => 'Make a landing page', 'page_type' => 'landing' ] );
        ( new ComposeJob( $store, $provider ) )->run( $id );

        $job = $store->getById( $id );
        $this->assertSame( 'complete', $job['status'] );
        $this->assertSame( 'pediment/hero', $job['result']['blocks'][0]['name'] );
        $this->assertContains( 'https://acme.example/', array_column( $job['events'], 'url_fetched' ) );

        unregister_block_type( 'pediment/hero' );
    }

    public function test_failed_validation_writes_error(): void {
        $store    = new JobStore();
        $provider = new StubProvider( [
            'content' => [
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page',
                  'input' => [ 'blocks' => [ [ 'name' => 'pediment/nope', 'attributes' => [], 'innerBlocks' => [] ] ] ] ],
            ],
            'usage' => [ 'input_tokens' => 1, 'output_tokens' => 1 ],
            'model' => 'claude-sonnet-4-6',
        ] );

        $id = $store->create( 1, 'compose', [ 'prompt' => 'x' ] );
        ( new ComposeJob( $store, $provider ) )->run( $id );

        $job = $store->getById( $id );
        $this->assertSame( 'error', $job['status'] );
        $this->assertStringContainsString( 'pediment/nope', $job['error_message'] );
    }

    public function test_wp_error_from_provider_marks_job_error(): void {
        $store    = new JobStore();
        $provider = new class implements ProviderInterface {
            public function messages( array $args ) { return new \WP_Error( 'down', 'API down' ); }
        };

        $id = $store->create( 1, 'compose', [ 'prompt' => 'x' ] );
        ( new ComposeJob( $store, $provider ) )->run( $id );

        $job = $store->getById( $id );
        $this->assertSame( 'error', $job['status'] );
        $this->assertStringContainsString( 'API down', $job['error_message'] );
    }
}
```

- [ ] **Step 3: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ComposeJobTest
```

Expected: FAIL.

- [ ] **Step 4: Implement src/Jobs/ComposeJob.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Jobs;

use PedimentAi\Anthropic\ProviderInterface;
use PedimentAi\Anthropic\SchemaBuilder;
use PedimentAi\Anthropic\ToolUseParser;
use PedimentAi\BlockTree\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ComposeJob {
    public function __construct(
        private readonly JobStore $store,
        private readonly ProviderInterface $provider
    ) {}

    public function run( int $job_id ): void {
        $job = $this->store->getById( $job_id );
        if ( ! $job ) { return; }
        $this->store->updateStatus( $job_id, 'composing' );

        $schema = ( new SchemaBuilder() )->build();
        $request = $this->buildRequest( $job, $schema );

        $response = $this->provider->messages( $request );
        if ( is_wp_error( $response ) ) {
            $this->store->fail( $job_id, $response->get_error_message() );
            return;
        }

        $parsed = ( new ToolUseParser() )->parse( $response );
        foreach ( $parsed['urls_fetched'] as $url ) {
            $this->store->appendEvent( $job_id, [ 'url_fetched' => $url ] );
        }

        if ( null === $parsed['tool'] ) {
            $this->store->fail( $job_id, 'Model did not call the emit tool.' );
            return;
        }

        $tree = $parsed['input']['blocks'] ?? [];
        if ( ! is_array( $tree ) ) {
            $this->store->fail( $job_id, 'Model emitted an invalid block tree.' );
            return;
        }

        $errors = ( new Validator( $schema['blocks'] ) )->validate( $tree );
        if ( ! empty( $errors ) ) {
            $this->store->fail( $job_id, 'Validation failed: ' . implode( '; ', $errors ) );
            return;
        }

        $this->store->complete( $job_id, [
            'blocks'       => $tree,
            'urls_fetched' => $parsed['urls_fetched'],
            'usage'        => $response['usage']  ?? null,
            'model'        => $response['model']  ?? null,
        ] );

        do_action( 'pediment_ai_job_completed', $job_id, $response, $job['kind'] );
    }

    private function buildRequest( array $job, array $schema ): array {
        return [
            'model'      => apply_filters( 'pediment_ai_model_' . $job['kind'], 'claude-sonnet-4-6' ),
            'max_tokens' => 4096,
            'tools'      => [
                [ 'type' => 'web_fetch_20250910', 'name' => 'web_fetch' ],
                [
                    'name'         => 'emit_page',
                    'description'  => 'Emit the final page as a block tree.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'blocks' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'name'        => [ 'type' => 'string', 'enum' => array_keys( $schema['blocks'] ) ],
                                        'attributes'  => [ 'type' => 'object' ],
                                        'innerBlocks' => [ 'type' => 'array' ],
                                    ],
                                    'required'   => [ 'name', 'attributes' ],
                                ],
                            ],
                        ],
                        'required' => [ 'blocks' ],
                    ],
                ],
            ],
            'tool_choice' => [ 'type' => 'any' ],
            'messages'    => [
                [ 'role' => 'user', 'content' => [
                    [ 'type' => 'text', 'text' => $this->systemBlock( $schema ), 'cache_control' => [ 'type' => 'ephemeral' ] ],
                    [ 'type' => 'text', 'text' => $this->userBlock( $job ) ],
                ] ],
            ],
        ];
    }

    private function systemBlock( array $schema ): string {
        $brand = class_exists( '\\Starter\\Brand' ) ? (string) \Starter\Brand::get( 'brand_name', '' ) : (string) get_option( 'blogname', '' );
        $lines   = [];
        $lines[] = 'You are a page composer for a WordPress block theme.';
        $lines[] = 'Always respond by calling the emit_page tool with a valid block tree.';
        $lines[] = 'Available blocks: ' . implode( ', ', array_keys( $schema['blocks'] ) ) . '.';
        if ( '' !== $brand ) {
            $lines[] = "Brand name: {$brand}.";
        }
        $lines[] = 'You may fetch URLs the user provides or that you decide are relevant for context.';
        return implode( "\n", $lines );
    }

    private function userBlock( array $job ): string {
        $lines = [];
        if ( ! empty( $job['payload']['page_type'] ) ) {
            $lines[] = 'Page type: ' . $job['payload']['page_type'];
        }
        if ( ! empty( $job['payload']['tone'] ) ) {
            $lines[] = 'Tone: ' . $job['payload']['tone'];
        }
        if ( ! empty( $job['payload']['existing_tree'] ) ) {
            $lines[] = 'Existing block tree:';
            $lines[] = wp_json_encode( $job['payload']['existing_tree'] );
            $lines[] = 'Edit instruction:';
        }
        $lines[] = (string) ( $job['payload']['prompt'] ?? '' );
        return implode( "\n\n", $lines );
    }
}
```

- [ ] **Step 5: Register the Action Scheduler hook in Bootstrap**

Append to `src/Bootstrap.php`'s `register()`:

```php
add_action( 'pediment_ai_job_run', static function ( int $job_id ) {
    $store    = new \PedimentAi\Jobs\JobStore();
    $provider = apply_filters( 'pediment_ai_provider', new \PedimentAi\Anthropic\Client(
        (string) ( defined( 'ANTHROPIC_API_KEY' ) ? ANTHROPIC_API_KEY : get_option( 'pediment_ai_api_key', '' ) )
    ) );
    ( new \PedimentAi\Jobs\ComposeJob( $store, $provider ) )->run( $job_id );
}, 10, 1 );
```

- [ ] **Step 6: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ComposeJobTest
```

Expected: 3 tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Anthropic/ProviderInterface.php src/Anthropic/Client.php src/Jobs/ComposeJob.php src/Bootstrap.php tests/phpunit/Jobs/ComposeJobTest.php
git commit -m "feat(jobs): ComposeJob — Action Scheduler worker with web_fetch + validation"
```

---

## Phase 4: REST endpoints

### Task 17: Rest\ComposeController — POST /v1/compose

**Files:**
- Create: `src/Rest/ComposeController.php`
- Modify: `src/Bootstrap.php` (register routes)
- Create: `tests/phpunit/Rest/ComposeControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

class ComposeControllerTest extends \WP_UnitTestCase {
    private \WP_REST_Server $server;

    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        $this->server   = $wp_rest_server;
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
    }

    public function test_returns_job_id_on_success(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/compose' );
        $req->set_param( 'prompt',    'Hello' );
        $req->set_param( 'page_type', 'landing' );
        $res = $this->server->dispatch( $req );
        $this->assertSame( 202, $res->get_status() );
        $this->assertGreaterThan( 0, $res->get_data()['job_id'] );
    }

    public function test_rejects_unauthenticated(): void {
        wp_set_current_user( 0 );
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/compose' );
        $req->set_param( 'prompt', 'x' );
        $res = $this->server->dispatch( $req );
        $this->assertSame( 401, $res->get_status() );
    }

    public function test_rejects_empty_prompt(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/compose' );
        $req->set_param( 'prompt', '' );
        $res = $this->server->dispatch( $req );
        $this->assertSame( 400, $res->get_status() );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ComposeControllerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Rest/ComposeController.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Rest;

use PedimentAi\Jobs\JobStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ComposeController {
    public const NAMESPACE = 'pediment-ai/v1';

    public function register(): void {
        register_rest_route( self::NAMESPACE, '/compose', [
            'methods'             => 'POST',
            'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
            'callback'            => [ $this, 'handle' ],
        ] );
    }

    public function handle( \WP_REST_Request $request ) {
        $prompt    = trim( (string) $request->get_param( 'prompt' ) );
        $page_type = sanitize_key( (string) $request->get_param( 'page_type' ) );
        $tone      = sanitize_text_field( (string) $request->get_param( 'tone' ) );

        if ( '' === $prompt ) {
            return new \WP_Error( 'pediment_ai_invalid', __( 'Prompt is required.', 'pediment-ai' ), [ 'status' => 400 ] );
        }

        $store = new JobStore();
        $job_id = $store->create( get_current_user_id(), 'compose', [
            'prompt'    => $prompt,
            'page_type' => $page_type ?: 'other',
            'tone'      => $tone,
        ] );

        as_schedule_single_action( time(), 'pediment_ai_job_run', [ $job_id ], 'pediment-ai' );

        return new \WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }
}
```

- [ ] **Step 4: Register the route in Bootstrap**

```php
add_action( 'rest_api_init', static function () {
    ( new \PedimentAi\Rest\ComposeController() )->register();
} );
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter ComposeControllerTest
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/ComposeController.php src/Bootstrap.php tests/phpunit/Rest/ComposeControllerTest.php
git commit -m "feat(rest): POST /v1/compose enqueues compose job"
```

### Task 18: Rest\EditController — POST /v1/edit

**Files:**
- Create: `src/Rest/EditController.php`
- Modify: `src/Bootstrap.php`
- Create: `tests/phpunit/Rest/EditControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

class EditControllerTest extends \WP_UnitTestCase {
    private \WP_REST_Server $server;

    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        $this->server   = $wp_rest_server;
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
    }

    public function test_accepts_block_tree_and_returns_job_id(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/edit' );
        $req->set_param( 'instruction', 'Add a CTA' );
        $req->set_param( 'tree', [
            [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Old' ], 'innerBlocks' => [] ],
        ] );
        $res = $this->server->dispatch( $req );
        $this->assertSame( 202, $res->get_status() );
        $this->assertGreaterThan( 0, $res->get_data()['job_id'] );
    }

    public function test_rejects_empty_instruction(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/edit' );
        $req->set_param( 'instruction', '' );
        $req->set_param( 'tree', [] );
        $this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
    }

    public function test_rejects_non_array_tree(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/edit' );
        $req->set_param( 'instruction', 'x' );
        $req->set_param( 'tree', 'not an array' );
        $this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter EditControllerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Rest/EditController.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Rest;

use PedimentAi\Jobs\JobStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EditController {
    public function register(): void {
        register_rest_route( ComposeController::NAMESPACE, '/edit', [
            'methods'             => 'POST',
            'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
            'callback'            => [ $this, 'handle' ],
        ] );
    }

    public function handle( \WP_REST_Request $request ) {
        $instruction = trim( (string) $request->get_param( 'instruction' ) );
        $tree        = $request->get_param( 'tree' );

        if ( '' === $instruction ) {
            return new \WP_Error( 'pediment_ai_invalid', __( 'Instruction is required.', 'pediment-ai' ), [ 'status' => 400 ] );
        }
        if ( ! is_array( $tree ) ) {
            return new \WP_Error( 'pediment_ai_invalid', __( 'Tree must be an array.', 'pediment-ai' ), [ 'status' => 400 ] );
        }

        $store = new JobStore();
        $job_id = $store->create( get_current_user_id(), 'edit', [
            'prompt'        => $instruction,
            'existing_tree' => $tree,
        ] );

        as_schedule_single_action( time(), 'pediment_ai_job_run', [ $job_id ], 'pediment-ai' );

        return new \WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }
}
```

- [ ] **Step 4: Register the route in Bootstrap**

Inside the existing `rest_api_init` callback:

```php
( new \PedimentAi\Rest\EditController() )->register();
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter EditControllerTest
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/EditController.php src/Bootstrap.php tests/phpunit/Rest/EditControllerTest.php
git commit -m "feat(rest): POST /v1/edit enqueues edit job"
```

### Task 19: Rest\RefineController — POST /v1/refine (synchronous)

**Files:**
- Create: `src/Rest/RefineController.php`
- Modify: `src/Bootstrap.php`
- Create: `tests/phpunit/Rest/RefineControllerTest.php`

> **Synchronous.** Refines target 1–3s; polling overhead isn't worth it. Inline provider call, attributes returned directly.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Anthropic\ProviderInterface;

class RefineControllerTest extends \WP_UnitTestCase {
    private \WP_REST_Server $server;

    public function setUp(): void {
        parent::setUp();
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        $this->server   = $wp_rest_server;
        \PedimentAi\Anthropic\SchemaBuilder::invalidate();

        add_filter( 'pediment_ai_provider', function () {
            return new class implements ProviderInterface {
                public function messages( array $args ) {
                    return [
                        'content' => [
                            [ 'type' => 'tool_use', 'id' => 't', 'name' => 'emit_block',
                              'input' => [ 'attributes' => [ 'headline' => 'Refined!' ], 'innerBlocks' => [] ] ],
                        ],
                        'usage' => [ 'input_tokens' => 5, 'output_tokens' => 3 ],
                        'model' => 'claude-haiku-4-5',
                    ];
                }
            };
        } );

        register_block_type( 'pediment/hero', [
            'attributes'  => [ 'headline' => [ 'type' => 'string' ] ],
            'description' => 'Hero',
        ] );

        do_action( 'rest_api_init' );
        wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );
    }

    public function tearDown(): void {
        unregister_block_type( 'pediment/hero' );
        remove_all_filters( 'pediment_ai_provider' );
        parent::tearDown();
    }

    public function test_returns_refined_attributes(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/refine' );
        $req->set_param( 'blockName',    'pediment/hero' );
        $req->set_param( 'attributes',   [ 'headline' => 'Old' ] );
        $req->set_param( 'innerBlocks',  [] );
        $req->set_param( 'instruction',  'Punchier' );

        $res = $this->server->dispatch( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'Refined!', $res->get_data()['attributes']['headline'] );
    }

    public function test_rejects_unknown_block(): void {
        $req = new \WP_REST_Request( 'POST', '/pediment-ai/v1/refine' );
        $req->set_param( 'blockName',   'pediment/unknown' );
        $req->set_param( 'attributes',  [] );
        $req->set_param( 'instruction', 'x' );
        $this->assertSame( 400, $this->server->dispatch( $req )->get_status() );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter RefineControllerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Rest/RefineController.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Rest;

use PedimentAi\Anthropic\Client;
use PedimentAi\Anthropic\SchemaBuilder;
use PedimentAi\Anthropic\ToolUseParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RefineController {
    public function register(): void {
        register_rest_route( ComposeController::NAMESPACE, '/refine', [
            'methods'             => 'POST',
            'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
            'callback'            => [ $this, 'handle' ],
        ] );
    }

    public function handle( \WP_REST_Request $request ) {
        $block_name  = (string) $request->get_param( 'blockName' );
        $attributes  = $request->get_param( 'attributes' );
        $inner       = $request->get_param( 'innerBlocks' );
        $instruction = trim( (string) $request->get_param( 'instruction' ) );

        if ( '' === $instruction ) {
            return new \WP_Error( 'pediment_ai_invalid', __( 'Instruction is required.', 'pediment-ai' ), [ 'status' => 400 ] );
        }

        $schema = ( new SchemaBuilder() )->build();
        if ( ! isset( $schema['blocks'][ $block_name ] ) ) {
            return new \WP_Error( 'pediment_ai_invalid', __( 'Unknown block.', 'pediment-ai' ), [ 'status' => 400 ] );
        }

        $spec     = $schema['blocks'][ $block_name ];
        $provider = apply_filters( 'pediment_ai_provider', new Client(
            (string) ( defined( 'ANTHROPIC_API_KEY' ) ? ANTHROPIC_API_KEY : get_option( 'pediment_ai_api_key', '' ) )
        ) );

        $response = $provider->messages( [
            'model'      => apply_filters( 'pediment_ai_model_refine', 'claude-haiku-4-5' ),
            'max_tokens' => 2048,
            'tools'      => [ [
                'name'         => 'emit_block',
                'description'  => 'Emit the refined block attributes.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'attributes'  => [ 'type' => 'object' ],
                        'innerBlocks' => [ 'type' => 'array' ],
                    ],
                    'required' => [ 'attributes' ],
                ],
            ] ],
            'tool_choice' => [ 'type' => 'tool', 'name' => 'emit_block' ],
            'messages'    => [
                [ 'role' => 'user', 'content' => [
                    [ 'type' => 'text', 'text' =>
                        "Refine this block: {$block_name}\n" .
                        'Description: ' . ( $spec['description'] ?? '' ) . "\n" .
                        'Current attributes: ' . wp_json_encode( $attributes ?: new \stdClass() ) . "\n" .
                        ( is_array( $inner ) && ! empty( $inner ) ? 'Inner blocks: ' . wp_json_encode( $inner ) . "\n" : '' ) .
                        "Instruction: {$instruction}",
                    ],
                ] ],
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = ( new ToolUseParser() )->parse( $response );
        if ( 'emit_block' !== $parsed['tool'] ) {
            return new \WP_Error( 'pediment_ai_no_emit', __( 'Model did not emit a block.', 'pediment-ai' ), [ 'status' => 502 ] );
        }

        return new \WP_REST_Response( [
            'attributes'  => $parsed['input']['attributes']  ?? [],
            'innerBlocks' => $parsed['input']['innerBlocks'] ?? [],
        ], 200 );
    }
}
```

- [ ] **Step 4: Register the route in Bootstrap**

```php
( new \PedimentAi\Rest\RefineController() )->register();
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter RefineControllerTest
```

Expected: 2 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/RefineController.php src/Bootstrap.php tests/phpunit/Rest/RefineControllerTest.php
git commit -m "feat(rest): POST /v1/refine — synchronous single-block refinement"
```

### Task 20: Rest\StatusController — GET /v1/jobs/{id}

**Files:**
- Create: `src/Rest/StatusController.php`
- Modify: `src/Bootstrap.php`
- Create: `tests/phpunit/Rest/StatusControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Rest;

use PedimentAi\Jobs\JobStore;

class StatusControllerTest extends \WP_UnitTestCase {
    private \WP_REST_Server $server;

    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wpdb, $wp_rest_server;
        $wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_jobs" );
        $wp_rest_server = new \WP_REST_Server();
        $this->server   = $wp_rest_server;
        do_action( 'rest_api_init' );
    }

    public function test_returns_polling_shape(): void {
        $user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
        wp_set_current_user( $user_id );

        $store = new JobStore();
        $id    = $store->create( $user_id, 'compose', [] );
        $store->updateStatus( $id, 'composing' );
        $store->appendEvent( $id, [ 'url_fetched' => 'https://x' ] );

        $req = new \WP_REST_Request( 'GET', "/pediment-ai/v1/jobs/{$id}" );
        $res = $this->server->dispatch( $req );

        $this->assertSame( 200, $res->get_status() );
        $body = $res->get_data();
        $this->assertSame( 'composing',    $body['status'] );
        $this->assertSame( [ 'https://x' ], $body['urls_fetched'] );
        $this->assertNull( $body['result'] );
        $this->assertNull( $body['error'] );
    }

    public function test_completed_job_includes_result(): void {
        $user_id = $this->factory->user->create( [ 'role' => 'editor' ] );
        wp_set_current_user( $user_id );

        $store = new JobStore();
        $id    = $store->create( $user_id, 'compose', [] );
        $store->complete( $id, [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [], 'innerBlocks' => [] ] ] ] );

        $req = new \WP_REST_Request( 'GET', "/pediment-ai/v1/jobs/{$id}" );
        $res = $this->server->dispatch( $req );
        $this->assertSame( 'complete', $res->get_data()['status'] );
        $this->assertSame( 'pediment/hero', $res->get_data()['result']['blocks'][0]['name'] );
    }

    public function test_other_user_cannot_read_job(): void {
        $owner = $this->factory->user->create( [ 'role' => 'editor' ] );
        $other = $this->factory->user->create( [ 'role' => 'editor' ] );

        $store = new JobStore();
        $id    = $store->create( $owner, 'compose', [] );

        wp_set_current_user( $other );
        $req = new \WP_REST_Request( 'GET', "/pediment-ai/v1/jobs/{$id}" );
        $this->assertSame( 403, $this->server->dispatch( $req )->get_status() );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter StatusControllerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Rest/StatusController.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Rest;

use PedimentAi\Jobs\JobStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class StatusController {
    public function register(): void {
        register_rest_route( ComposeController::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
            'callback'            => [ $this, 'handle' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );
    }

    public function handle( \WP_REST_Request $request ) {
        $id  = (int) $request->get_param( 'id' );
        $job = ( new JobStore() )->getById( $id );
        if ( ! $job ) {
            return new \WP_Error( 'pediment_ai_not_found', __( 'Job not found.', 'pediment-ai' ), [ 'status' => 404 ] );
        }
        if ( $job['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'pediment_ai_forbidden', __( 'Not your job.', 'pediment-ai' ), [ 'status' => 403 ] );
        }

        $urls = array_values( array_filter( array_column( $job['events'], 'url_fetched' ) ) );
        return new \WP_REST_Response( [
            'status'        => $job['status'],
            'urls_fetched'  => $urls,
            'progress_note' => $this->progressNote( $job['status'], $urls ),
            'result'        => $job['result'],
            'error'         => $job['error_message'],
        ], 200 );
    }

    private function progressNote( string $status, array $urls ): ?string {
        if ( 'queued' === $status )    { return 'Queued…'; }
        if ( 'composing' === $status ) {
            return [] === $urls ? 'Composing…' : 'Composing (fetched ' . count( $urls ) . ' URL' . ( count( $urls ) === 1 ? '' : 's' ) . ')';
        }
        if ( 'error' === $status )    { return 'Error'; }
        if ( 'complete' === $status ) { return 'Done'; }
        return null;
    }
}
```

- [ ] **Step 4: Register the route in Bootstrap**

```php
( new \PedimentAi\Rest\StatusController() )->register();
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter StatusControllerTest
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Rest/StatusController.php src/Bootstrap.php tests/phpunit/Rest/StatusControllerTest.php
git commit -m "feat(rest): GET /v1/jobs/{id} status endpoint for polling"
```

---

## Phase 5: Mock provider + fixtures

### Task 21: Mock\MockProvider

**Files:**
- Create: `src/Mock/MockProvider.php`
- Modify: `src/Bootstrap.php` (swap provider when PEDIMENT_AI_MOCK=true)
- Create: `tests/phpunit/Mock/MockProviderTest.php`

> **Behavior:** MockProvider reads from `src/Mock/fixtures/*.json` and returns an Anthropic-shaped response containing a tool_use block. Selection rules:
> - For compose: picks `compose-<page_type>.json`, falling back to `compose-landing.json`.
> - For refine: picks `refine-<blockName-suffix>.json`, falling back to `refine-hero.json`.
> - For edit: picks `edit-<keyword>.json` matching the instruction, falling back to `edit-shorten.json`.

- [ ] **Step 1: Write the failing test (uses fixtures from Task 22 — write a minimal one inline first)**

```php
<?php
namespace PedimentAi\Tests\Mock;

use PedimentAi\Mock\MockProvider;

class MockProviderTest extends \WP_UnitTestCase {
    private string $fixturesDir;

    public function setUp(): void {
        parent::setUp();
        $this->fixturesDir = sys_get_temp_dir() . '/pediment-ai-fixtures-' . uniqid();
        mkdir( $this->fixturesDir, 0777, true );
        file_put_contents( $this->fixturesDir . '/compose-landing.json', wp_json_encode( [
            'content' => [
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_page',
                  'input' => [ 'blocks' => [ [ 'name' => 'pediment/hero', 'attributes' => [ 'headline' => 'Mock landing' ], 'innerBlocks' => [] ] ] ] ],
            ],
            'usage' => [ 'input_tokens' => 0, 'output_tokens' => 0 ],
            'model' => 'mock',
        ] ) );
        file_put_contents( $this->fixturesDir . '/refine-hero.json', wp_json_encode( [
            'content' => [
                [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'emit_block',
                  'input' => [ 'attributes' => [ 'headline' => 'Mock refined' ], 'innerBlocks' => [] ] ],
            ],
            'usage' => [ 'input_tokens' => 0, 'output_tokens' => 0 ],
            'model' => 'mock',
        ] ) );
    }

    public function tearDown(): void {
        array_map( 'unlink', glob( $this->fixturesDir . '/*.json' ) );
        rmdir( $this->fixturesDir );
        parent::tearDown();
    }

    public function test_returns_compose_fixture(): void {
        $provider = new MockProvider( $this->fixturesDir );
        $response = $provider->messages( [
            'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Page type: landing' ] ] ] ],
            'tools'    => [ [ 'name' => 'emit_page' ] ],
        ] );
        $this->assertSame( 'Mock landing', $response['content'][0]['input']['blocks'][0]['attributes']['headline'] );
    }

    public function test_returns_refine_fixture(): void {
        $provider = new MockProvider( $this->fixturesDir );
        $response = $provider->messages( [
            'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Refine this block: pediment/hero' ] ] ] ],
            'tools'    => [ [ 'name' => 'emit_block' ] ],
        ] );
        $this->assertSame( 'Mock refined', $response['content'][0]['input']['attributes']['headline'] );
    }

    public function test_falls_back_to_default_compose_fixture(): void {
        $provider = new MockProvider( $this->fixturesDir );
        $response = $provider->messages( [
            'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => 'Page type: spaceships' ] ] ] ],
            'tools'    => [ [ 'name' => 'emit_page' ] ],
        ] );
        $this->assertSame( 'Mock landing', $response['content'][0]['input']['blocks'][0]['attributes']['headline'] );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter MockProviderTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Mock/MockProvider.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Mock;

use PedimentAi\Anthropic\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MockProvider implements ProviderInterface {
    public function __construct( private readonly string $fixturesDir ) {}

    public function messages( array $args ) {
        $text  = $this->concatenateUserText( $args );
        $tools = array_column( $args['tools'] ?? [], 'name' );

        if ( in_array( 'emit_block', $tools, true ) ) {
            $fixture = $this->resolveRefineFixture( $text );
        } else {
            $fixture = $this->resolveComposeOrEditFixture( $text );
        }

        $path = $this->fixturesDir . '/' . $fixture . '.json';
        if ( ! file_exists( $path ) ) {
            return new \WP_Error( 'pediment_ai_mock_missing', "Missing fixture: {$fixture}" );
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $data ) ) {
            return new \WP_Error( 'pediment_ai_mock_invalid', "Invalid fixture: {$fixture}" );
        }
        return $data;
    }

    private function concatenateUserText( array $args ): string {
        $out = '';
        foreach ( $args['messages'] ?? [] as $msg ) {
            foreach ( (array) ( $msg['content'] ?? [] ) as $part ) {
                if ( is_array( $part ) && 'text' === ( $part['type'] ?? '' ) ) {
                    $out .= "\n" . (string) ( $part['text'] ?? '' );
                }
            }
        }
        return $out;
    }

    private function resolveComposeOrEditFixture( string $text ): string {
        if ( false !== stripos( $text, 'Edit instruction:' ) || false !== stripos( $text, 'existing block tree' ) ) {
            if ( preg_match( '/\b(add|insert)\b/i', $text ) && false !== stripos( $text, 'faq' ) ) {
                return 'edit-add-faq';
            }
            return 'edit-shorten';
        }

        if ( preg_match( '/Page type:\s*(\w+)/i', $text, $m ) ) {
            $slug = strtolower( $m[1] );
            foreach ( [ 'landing', 'about', 'services', 'contact' ] as $known ) {
                if ( $slug === $known ) {
                    return 'compose-' . $known;
                }
            }
        }
        return 'compose-landing';
    }

    private function resolveRefineFixture( string $text ): string {
        if ( preg_match( '/starter\/([a-z\-]+)/i', $text, $m ) ) {
            $candidate = 'refine-' . strtolower( $m[1] );
            $path      = $this->fixturesDir . '/' . $candidate . '.json';
            if ( file_exists( $path ) ) {
                return $candidate;
            }
        }
        return 'refine-hero';
    }
}
```

- [ ] **Step 4: Swap the provider in Bootstrap when mock mode is on**

Append to `src/Bootstrap.php`'s `register()`:

```php
add_filter( 'pediment_ai_provider', static function ( $default ) {
    if ( defined( 'PEDIMENT_AI_MOCK' ) && PEDIMENT_AI_MOCK ) {
        return new \PedimentAi\Mock\MockProvider( __DIR__ . '/Mock/fixtures' );
    }
    return $default;
} );
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter MockProviderTest
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Mock/MockProvider.php src/Bootstrap.php tests/phpunit/Mock/MockProviderTest.php
git commit -m "feat(mock): MockProvider with fixture-based response selection"
```

### Task 22: Fixture files

**Files:**
- Create: `src/Mock/fixtures/compose-landing.json`
- Create: `src/Mock/fixtures/compose-about.json`
- Create: `src/Mock/fixtures/compose-services.json`
- Create: `src/Mock/fixtures/compose-contact.json`
- Create: `src/Mock/fixtures/edit-add-faq.json`
- Create: `src/Mock/fixtures/edit-shorten.json`
- Create: `src/Mock/fixtures/refine-hero.json`
- Create: `src/Mock/fixtures/refine-cta.json`
- Create: `src/Mock/fixtures/refine-faq-item.json`
- Create: `tests/phpunit/Mock/FixturesTest.php`

- [ ] **Step 1: Write the failing test (asserts every fixture exists and parses)**

```php
<?php
namespace PedimentAi\Tests\Mock;

class FixturesTest extends \WP_UnitTestCase {
    private const REQUIRED = [
        'compose-landing', 'compose-about', 'compose-services', 'compose-contact',
        'edit-add-faq', 'edit-shorten',
        'refine-hero', 'refine-cta', 'refine-faq-item',
    ];

    public function test_all_required_fixtures_exist_and_parse(): void {
        $dir = dirname( __DIR__, 2 ) . '/src/Mock/fixtures';
        foreach ( self::REQUIRED as $name ) {
            $path = "{$dir}/{$name}.json";
            $this->assertFileExists( $path, "Missing fixture: {$name}" );
            $data = json_decode( (string) file_get_contents( $path ), true );
            $this->assertIsArray( $data, "Invalid JSON in: {$name}" );
            $this->assertNotEmpty( $data['content'], "Empty content in: {$name}" );
            $tool = null;
            foreach ( $data['content'] as $block ) {
                if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
                    $tool = $block;
                    break;
                }
            }
            $this->assertNotNull( $tool, "Fixture must contain tool_use: {$name}" );
            $expected_tool = str_starts_with( $name, 'refine-' ) ? 'emit_block' : 'emit_page';
            $this->assertSame( $expected_tool, $tool['name'] );
        }
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter FixturesTest
```

Expected: FAIL (no fixtures exist).

- [ ] **Step 3: Write src/Mock/fixtures/compose-landing.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    {
      "type": "tool_use",
      "id": "tu_mock_landing",
      "name": "emit_page",
      "input": {
        "blocks": [
          { "name": "pediment/hero", "attributes": { "variant": "centered", "headline": "Welcome", "subheadline": "A clear, benefit-led promise.", "ctaText": "Get started", "ctaUrl": "/contact" }, "innerBlocks": [] },
          { "name": "pediment/cta",  "attributes": { "title": "Ready to start?", "body": "Tell us about your project.", "primaryText": "Contact us", "primaryUrl": "/contact" }, "innerBlocks": [] },
          { "name": "pediment/faq",  "attributes": {}, "innerBlocks": [
            { "name": "pediment/faq-item", "attributes": { "question": "How long does a project take?", "answer": "Most engagements run 4–8 weeks." }, "innerBlocks": [] },
            { "name": "pediment/faq-item", "attributes": { "question": "What's your pricing?", "answer": "Fixed-scope sprints or monthly retainer." }, "innerBlocks": [] }
          ] }
        ]
      }
    }
  ]
}
```

- [ ] **Step 4: Write src/Mock/fixtures/compose-about.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_mock_about", "name": "emit_page", "input": { "blocks": [
      { "name": "pediment/hero", "attributes": { "variant": "default", "headline": "About us", "subheadline": "Who we are and how we work." }, "innerBlocks": [] },
      { "name": "pediment/prose", "attributes": {}, "innerBlocks": [
        { "name": "core/paragraph", "attributes": { "content": "We help product teams ship better marketing sites." }, "innerBlocks": [] }
      ] },
      { "name": "pediment/stat", "attributes": { "value": "40+", "label": "Sites shipped", "context": "since 2021" }, "innerBlocks": [] }
    ] } }
  ]
}
```

- [ ] **Step 5: Write src/Mock/fixtures/compose-services.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_mock_services", "name": "emit_page", "input": { "blocks": [
      { "name": "pediment/hero", "attributes": { "variant": "split", "headline": "Services", "subheadline": "End-to-end product marketing sites." }, "innerBlocks": [] },
      { "name": "pediment/cta",  "attributes": { "title": "What we do", "body": "Strategy, design, build, ship.", "primaryText": "Book a call", "primaryUrl": "/contact" }, "innerBlocks": [] },
      { "name": "pediment/faq",  "attributes": {}, "innerBlocks": [
        { "name": "pediment/faq-item", "attributes": { "question": "Do you redesign existing sites?", "answer": "Yes — half our work is refresh, not greenfield." }, "innerBlocks": [] }
      ] }
    ] } }
  ]
}
```

- [ ] **Step 6: Write src/Mock/fixtures/compose-contact.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_mock_contact", "name": "emit_page", "input": { "blocks": [
      { "name": "pediment/hero", "attributes": { "variant": "centered", "headline": "Contact", "subheadline": "Tell us about your project." }, "innerBlocks": [] },
      { "name": "pediment/contact-form", "attributes": { "includePhone": true }, "innerBlocks": [] }
    ] } }
  ]
}
```

- [ ] **Step 7: Write src/Mock/fixtures/edit-add-faq.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_edit_faq", "name": "emit_page", "input": { "blocks": [
      { "name": "pediment/hero", "attributes": { "headline": "Same hero" }, "innerBlocks": [] },
      { "name": "pediment/faq",  "attributes": {}, "innerBlocks": [
        { "name": "pediment/faq-item", "attributes": { "question": "Newly added question?", "answer": "Newly added answer." }, "innerBlocks": [] }
      ] }
    ] } }
  ]
}
```

- [ ] **Step 8: Write src/Mock/fixtures/edit-shorten.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_edit_shorten", "name": "emit_page", "input": { "blocks": [
      { "name": "pediment/hero", "attributes": { "headline": "Shorter." }, "innerBlocks": [] }
    ] } }
  ]
}
```

- [ ] **Step 9: Write src/Mock/fixtures/refine-hero.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_refine_hero", "name": "emit_block", "input": {
      "attributes": { "headline": "Punchier headline", "subheadline": "Tighter sub.", "ctaText": "Start now", "ctaUrl": "/contact" },
      "innerBlocks": []
    } }
  ]
}
```

- [ ] **Step 10: Write src/Mock/fixtures/refine-cta.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_refine_cta", "name": "emit_block", "input": {
      "attributes": { "title": "Don't wait", "body": "Five minutes to a quote.", "primaryText": "Book", "primaryUrl": "/contact" },
      "innerBlocks": []
    } }
  ]
}
```

- [ ] **Step 11: Write src/Mock/fixtures/refine-faq-item.json**

```json
{
  "model": "mock",
  "usage": { "input_tokens": 0, "output_tokens": 0 },
  "content": [
    { "type": "tool_use", "id": "tu_refine_faq_item", "name": "emit_block", "input": {
      "attributes": { "question": "Tighter question?", "answer": "Tighter answer." },
      "innerBlocks": []
    } }
  ]
}
```

- [ ] **Step 12: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter FixturesTest
```

Expected: 1 test passes (covers all 9 fixtures).

- [ ] **Step 13: Commit**

```bash
git add src/Mock/fixtures/ tests/phpunit/Mock/FixturesTest.php
git commit -m "feat(mock): 9 fixture files for compose, edit, refine flows"
```

---

## Phase 6: Usage telemetry + rate limiting

### Task 23: Usage\Tracker + Pricing

**Files:**
- Create: `src/Usage/Pricing.php`
- Create: `src/Usage/Tracker.php`
- Modify: `src/Bootstrap.php` (hook `pediment_ai_job_completed`)
- Create: `tests/phpunit/Usage/TrackerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Usage;

use PedimentAi\Usage\Tracker;

class TrackerTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        \pediment_ai_install_tables();
        global $wpdb;
        $wpdb->query( "TRUNCATE {$wpdb->prefix}pediment_ai_usage" );
    }

    public function test_records_a_call(): void {
        $tracker = new Tracker();
        $tracker->record( 1, 'compose', [
            'model' => 'claude-sonnet-4-6',
            'usage' => [ 'input_tokens' => 1000, 'output_tokens' => 500, 'cache_read_input_tokens' => 100 ],
        ], 2 );

        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}pediment_ai_usage", ARRAY_A );
        $this->assertSame( 'compose', $row['kind'] );
        $this->assertSame( '1000',    $row['input_tokens'] );
        $this->assertSame( '500',     $row['output_tokens'] );
        $this->assertSame( '100',     $row['cache_read_tokens'] );
        $this->assertSame( '2',       $row['web_fetch_count'] );
        $this->assertGreaterThan( 0.0, (float) $row['cost_usd'] );
    }

    public function test_month_to_date_totals(): void {
        $tracker = new Tracker();
        $tracker->record( 1, 'compose', [ 'model' => 'claude-sonnet-4-6', 'usage' => [ 'input_tokens' => 1000, 'output_tokens' => 500 ] ], 0 );
        $tracker->record( 1, 'refine',  [ 'model' => 'claude-haiku-4-5',  'usage' => [ 'input_tokens' => 300,  'output_tokens' => 100 ] ], 0 );

        $totals = $tracker->totalsThisMonth();
        $this->assertSame( 1300, $totals['input_tokens'] );
        $this->assertSame( 600,  $totals['output_tokens'] );
        $this->assertGreaterThan( 0.0, $totals['cost_usd'] );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter TrackerTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Usage/Pricing.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Pricing {
    /**
     * USD per 1M tokens. Update when Anthropic pricing changes.
     * @var array<string, array{input:float, output:float, cache_read:float, cache_write:float}>
     */
    private const TABLE = [
        'claude-sonnet-4-6' => [ 'input' => 3.00,  'output' => 15.00, 'cache_read' => 0.30,  'cache_write' => 3.75 ],
        'claude-haiku-4-5'  => [ 'input' => 0.80,  'output' => 4.00,  'cache_read' => 0.08,  'cache_write' => 1.00 ],
        'claude-opus-4-7'   => [ 'input' => 15.00, 'output' => 75.00, 'cache_read' => 1.50,  'cache_write' => 18.75 ],
    ];

    public const WEB_FETCH_USD_PER_CALL = 0.01;

    public static function estimate(
        string $model,
        int $input_tokens,
        int $output_tokens,
        int $cache_read = 0,
        int $cache_write = 0,
        int $web_fetch_count = 0
    ): float {
        $rates = self::TABLE[ $model ] ?? self::TABLE['claude-sonnet-4-6'];
        $cost  = 0.0;
        $cost += ( $input_tokens  / 1_000_000 ) * $rates['input'];
        $cost += ( $output_tokens / 1_000_000 ) * $rates['output'];
        $cost += ( $cache_read    / 1_000_000 ) * $rates['cache_read'];
        $cost += ( $cache_write   / 1_000_000 ) * $rates['cache_write'];
        $cost += $web_fetch_count * self::WEB_FETCH_USD_PER_CALL;
        return round( $cost, 6 );
    }
}
```

- [ ] **Step 4: Implement src/Usage/Tracker.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tracker {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pediment_ai_usage';
    }

    public function record( int $user_id, string $kind, array $response, int $web_fetch_count = 0 ): void {
        global $wpdb;

        $model        = (string) ( $response['model'] ?? 'unknown' );
        $usage        = (array)  ( $response['usage'] ?? [] );
        $input        = (int)    ( $usage['input_tokens']                ?? 0 );
        $output       = (int)    ( $usage['output_tokens']               ?? 0 );
        $cache_read   = (int)    ( $usage['cache_read_input_tokens']     ?? 0 );
        $cache_write  = (int)    ( $usage['cache_creation_input_tokens'] ?? 0 );

        $cost = Pricing::estimate( $model, $input, $output, $cache_read, $cache_write, $web_fetch_count );

        $wpdb->insert( $this->table, [
            'user_id'            => $user_id,
            'kind'               => $kind,
            'model'              => $model,
            'input_tokens'       => $input,
            'output_tokens'      => $output,
            'cache_read_tokens'  => $cache_read,
            'cache_write_tokens' => $cache_write,
            'web_fetch_count'    => $web_fetch_count,
            'cost_usd'           => $cost,
            'created_at'         => current_time( 'mysql', true ),
        ] );
    }

    public function totalsThisMonth(): array {
        global $wpdb;
        $since = gmdate( 'Y-m-01 00:00:00' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE( SUM( input_tokens ),       0 ) AS input_tokens,
                COALESCE( SUM( output_tokens ),      0 ) AS output_tokens,
                COALESCE( SUM( cache_read_tokens ),  0 ) AS cache_read_tokens,
                COALESCE( SUM( cache_write_tokens ), 0 ) AS cache_write_tokens,
                COALESCE( SUM( web_fetch_count ),    0 ) AS web_fetch_count,
                COALESCE( SUM( cost_usd ),           0 ) AS cost_usd
            FROM {$this->table}
            WHERE created_at >= %s",
            $since
        ), ARRAY_A );

        return [
            'input_tokens'       => (int)   $row['input_tokens'],
            'output_tokens'      => (int)   $row['output_tokens'],
            'cache_read_tokens'  => (int)   $row['cache_read_tokens'],
            'cache_write_tokens' => (int)   $row['cache_write_tokens'],
            'web_fetch_count'    => (int)   $row['web_fetch_count'],
            'cost_usd'           => (float) $row['cost_usd'],
        ];
    }
}
```

- [ ] **Step 5: Hook into job completion (Bootstrap)**

Append to `src/Bootstrap.php`'s `register()`:

```php
add_action( 'pediment_ai_job_completed', static function ( int $job_id, array $response, string $kind ) {
    $job = ( new \PedimentAi\Jobs\JobStore() )->getById( $job_id );
    if ( ! $job ) { return; }
    $fetched = count( $job['result']['urls_fetched'] ?? [] );
    ( new \PedimentAi\Usage\Tracker() )->record( $job['user_id'], $kind, $response, $fetched );
}, 10, 3 );
```

- [ ] **Step 6: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter TrackerTest
```

Expected: 2 tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Usage/Pricing.php src/Usage/Tracker.php src/Bootstrap.php tests/phpunit/Usage/TrackerTest.php
git commit -m "feat(usage): Tracker + Pricing with month-to-date totals"
```

### Task 24: Usage\RateLimiter

**Files:**
- Create: `src/Usage/RateLimiter.php`
- Modify: `src/Rest/ComposeController.php` + `EditController.php` + `RefineController.php` (check rate limit in permission_callback)
- Create: `tests/phpunit/Usage/RateLimiterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Usage;

use PedimentAi\Usage\RateLimiter;

class RateLimiterTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        $this->user_id = $this->factory->user->create();
    }
    private int $user_id;

    public function test_allows_below_limit(): void {
        $limiter = new RateLimiter( [ 'compose' => 3 ] );
        for ( $i = 0; $i < 3; $i++ ) {
            $this->assertTrue( $limiter->consume( $this->user_id, 'compose' ) );
        }
    }

    public function test_rejects_at_limit(): void {
        $limiter = new RateLimiter( [ 'compose' => 2 ] );
        $limiter->consume( $this->user_id, 'compose' );
        $limiter->consume( $this->user_id, 'compose' );
        $this->assertFalse( $limiter->consume( $this->user_id, 'compose' ) );
    }

    public function test_separate_buckets_per_kind(): void {
        $limiter = new RateLimiter( [ 'compose' => 1, 'refine' => 5 ] );
        $limiter->consume( $this->user_id, 'compose' );
        $this->assertFalse( $limiter->consume( $this->user_id, 'compose' ) );
        $this->assertTrue(  $limiter->consume( $this->user_id, 'refine' ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter RateLimiterTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Usage/RateLimiter.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Usage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RateLimiter {
    public const DEFAULTS = [ 'compose' => 30, 'edit' => 30, 'refine' => 200 ];
    public const WINDOW_SECONDS = HOUR_IN_SECONDS;

    /** @param array<string,int> $limits */
    public function __construct( private readonly array $limits = self::DEFAULTS ) {}

    public function consume( int $user_id, string $kind ): bool {
        $key   = $this->key( $user_id, $kind );
        $count = (int) get_transient( $key );
        $limit = $this->limits[ $kind ] ?? self::DEFAULTS[ $kind ] ?? 0;
        if ( $limit > 0 && $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, self::WINDOW_SECONDS );
        return true;
    }

    public function remaining( int $user_id, string $kind ): int {
        $count = (int) get_transient( $this->key( $user_id, $kind ) );
        $limit = $this->limits[ $kind ] ?? self::DEFAULTS[ $kind ] ?? 0;
        return max( 0, $limit - $count );
    }

    private function key( int $user_id, string $kind ): string {
        return "pediment_ai_rl_{$user_id}_{$kind}";
    }
}
```

- [ ] **Step 4: Enforce in REST controllers**

Modify each of `ComposeController::handle()`, `EditController::handle()`, `RefineController::handle()` to start with:

```php
$limits = (array) get_option( 'pediment_ai_rate_limits', \PedimentAi\Usage\RateLimiter::DEFAULTS );
if ( ! ( new \PedimentAi\Usage\RateLimiter( $limits ) )->consume( get_current_user_id(), '<kind>' ) ) {
    return new \WP_Error( 'pediment_ai_rate_limited', __( 'Rate limit reached. Try again later.', 'pediment-ai' ), [ 'status' => 429 ] );
}
```

Substitute `<kind>` with `'compose'`, `'edit'`, or `'refine'` in each controller.

- [ ] **Step 5: Run the tests (including controllers)**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter "RateLimiterTest|ComposeControllerTest|EditControllerTest|RefineControllerTest"
```

Expected: all pass; controllers still pass because default limits are well above 1 call.

- [ ] **Step 6: Commit**

```bash
git add src/Usage/RateLimiter.php src/Rest/ComposeController.php src/Rest/EditController.php src/Rest/RefineController.php tests/phpunit/Usage/RateLimiterTest.php
git commit -m "feat(usage): per-user RateLimiter enforced on all REST endpoints"
```

---

## Phase 7: Settings page

### Task 25: Settings\Page + OptionsStore

**Files:**
- Create: `src/Settings/OptionsStore.php`
- Create: `src/Settings/Page.php`
- Modify: `src/Bootstrap.php` (register settings page)
- Create: `tests/phpunit/Settings/OptionsStoreTest.php`

> **API key encryption.** Keys are encrypted at rest using a key derived from `wp_salt('auth')`. If the key is set via the `ANTHROPIC_API_KEY` constant in `.env`, the plugin reads from there and ignores the options entry entirely — recommended for production. The settings UI exists for admins who don't have file-level access.

- [ ] **Step 1: Write the failing OptionsStore test**

```php
<?php
namespace PedimentAi\Tests\Settings;

use PedimentAi\Settings\OptionsStore;

class OptionsStoreTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        delete_option( 'pediment_ai_settings' );
    }

    public function test_set_and_get_api_key_round_trips(): void {
        $store = new OptionsStore();
        $store->setApiKey( 'sk-ant-test123' );
        $this->assertSame( 'sk-ant-test123', $store->getApiKey() );
    }

    public function test_stored_key_is_not_plaintext_in_option(): void {
        $store = new OptionsStore();
        $store->setApiKey( 'sk-ant-test123' );
        $raw = get_option( 'pediment_ai_settings' );
        $this->assertIsString( $raw['api_key_encrypted'] ?? null );
        $this->assertNotSame( 'sk-ant-test123', $raw['api_key_encrypted'] );
    }

    public function test_get_api_key_returns_empty_when_unset(): void {
        $this->assertSame( '', ( new OptionsStore() )->getApiKey() );
    }

    public function test_models_and_mock_toggle_persist(): void {
        $store = new OptionsStore();
        $store->set( 'model_compose', 'claude-opus-4-7' );
        $store->set( 'mock_mode',     true );
        $this->assertSame( 'claude-opus-4-7', $store->get( 'model_compose' ) );
        $this->assertTrue( $store->get( 'mock_mode' ) );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter OptionsStoreTest
```

Expected: FAIL.

- [ ] **Step 3: Implement src/Settings/OptionsStore.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OptionsStore {
    public const OPTION = 'pediment_ai_settings';

    public function getApiKey(): string {
        if ( defined( 'ANTHROPIC_API_KEY' ) && '' !== (string) ANTHROPIC_API_KEY ) {
            return (string) ANTHROPIC_API_KEY;
        }
        $opts = $this->all();
        $enc  = (string) ( $opts['api_key_encrypted'] ?? '' );
        return '' === $enc ? '' : $this->decrypt( $enc );
    }

    public function setApiKey( string $plain ): void {
        $opts = $this->all();
        $opts['api_key_encrypted'] = '' === $plain ? '' : $this->encrypt( $plain );
        update_option( self::OPTION, $opts );
    }

    public function get( string $key, $default = null ) {
        $all = $this->all();
        return $all[ $key ] ?? $default;
    }

    public function set( string $key, $value ): void {
        $opts = $this->all();
        $opts[ $key ] = $value;
        update_option( self::OPTION, $opts );
    }

    public function all(): array {
        $raw = get_option( self::OPTION, [] );
        return is_array( $raw ) ? $raw : [];
    }

    private function encrypt( string $plain ): string {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            return base64_encode( $plain ); // best-effort obfuscation
        }
        $key   = $this->cipherKey();
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = sodium_crypto_secretbox( $plain, $nonce, $key );
        return base64_encode( $nonce . $ct );
    }

    private function decrypt( string $blob ): string {
        $raw = base64_decode( $blob, true );
        if ( false === $raw ) {
            return '';
        }
        if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
            return $raw; // matches base64-only encrypt() fallback
        }
        $key   = $this->cipherKey();
        $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plain = sodium_crypto_secretbox_open( $ct, $nonce, $key );
        return false === $plain ? '' : (string) $plain;
    }

    private function cipherKey(): string {
        return substr( hash( 'sha256', wp_salt( 'auth' ) . '|pediment-ai', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }
}
```

- [ ] **Step 4: Implement src/Settings/Page.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Settings;

use PedimentAi\Usage\RateLimiter;
use PedimentAi\Usage\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Page {
    public const SLUG = 'pediment-ai';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenu' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
    }

    public function addMenu(): void {
        add_options_page(
            __( 'Pediment AI', 'pediment-ai' ),
            __( 'Pediment AI', 'pediment-ai' ),
            'manage_options',
            self::SLUG,
            [ $this, 'render' ]
        );
    }

    public function registerSettings(): void {
        register_setting( 'pediment_ai_group', OptionsStore::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ] ] );
        register_setting( 'pediment_ai_group', 'pediment_ai_rate_limits', [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitizeLimits' ] ] );
    }

    public function sanitize( $input ): array {
        $store = new OptionsStore();
        $out   = $store->all();

        if ( isset( $input['api_key'] ) && '' !== (string) $input['api_key'] ) {
            $store->setApiKey( (string) $input['api_key'] );
            $out = $store->all();
        }
        if ( isset( $input['model_compose'] ) ) { $out['model_compose'] = sanitize_text_field( (string) $input['model_compose'] ); }
        if ( isset( $input['model_refine'] ) )  { $out['model_refine']  = sanitize_text_field( (string) $input['model_refine'] ); }
        $out['mock_mode'] = ! empty( $input['mock_mode'] );
        return $out;
    }

    public function sanitizeLimits( $input ): array {
        $limits = RateLimiter::DEFAULTS;
        foreach ( [ 'compose', 'edit', 'refine' ] as $k ) {
            if ( isset( $input[ $k ] ) && (int) $input[ $k ] > 0 ) {
                $limits[ $k ] = (int) $input[ $k ];
            }
        }
        return $limits;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $store   = new OptionsStore();
        $limits  = (array) get_option( 'pediment_ai_rate_limits', RateLimiter::DEFAULTS );
        $usage   = ( new Tracker() )->totalsThisMonth();
        $env_key = defined( 'ANTHROPIC_API_KEY' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pediment AI Settings', 'pediment-ai' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'pediment_ai_group' ); ?>

                <h2><?php esc_html_e( 'API key', 'pediment-ai' ); ?></h2>
                <?php if ( $env_key ) : ?>
                    <p><?php esc_html_e( 'Loaded from ANTHROPIC_API_KEY env constant. Field below is ignored.', 'pediment-ai' ); ?></p>
                <?php endif; ?>
                <input type="password" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[api_key]" value="" autocomplete="off" placeholder="<?php esc_attr_e( 'Set or update Anthropic key', 'pediment-ai' ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Stored encrypted using wp_salt-derived key.', 'pediment-ai' ); ?></p>

                <h2><?php esc_html_e( 'Models', 'pediment-ai' ); ?></h2>
                <p>
                    <label>Compose / Edit
                        <input type="text" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[model_compose]" value="<?php echo esc_attr( (string) $store->get( 'model_compose', 'claude-sonnet-4-6' ) ); ?>" class="regular-text" />
                    </label>
                </p>
                <p>
                    <label>Refine
                        <input type="text" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[model_refine]" value="<?php echo esc_attr( (string) $store->get( 'model_refine', 'claude-haiku-4-5' ) ); ?>" class="regular-text" />
                    </label>
                </p>

                <h2><?php esc_html_e( 'Mock mode', 'pediment-ai' ); ?></h2>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( OptionsStore::OPTION ); ?>[mock_mode]" value="1" <?php checked( (bool) $store->get( 'mock_mode', false ) ); ?> />
                    <?php esc_html_e( 'Return fixture responses instead of calling Anthropic. For development.', 'pediment-ai' ); ?>
                </label>

                <h2><?php esc_html_e( 'Rate limits (per user per hour)', 'pediment-ai' ); ?></h2>
                <?php foreach ( [ 'compose', 'edit', 'refine' ] as $k ) : ?>
                    <p><label><?php echo esc_html( ucfirst( $k ) ); ?>: <input type="number" min="1" name="pediment_ai_rate_limits[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( (string) ( $limits[ $k ] ?? RateLimiter::DEFAULTS[ $k ] ) ); ?>" /></label></p>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Usage this month', 'pediment-ai' ); ?></h2>
            <ul>
                <li>Input tokens: <?php echo esc_html( number_format_i18n( $usage['input_tokens'] ) ); ?></li>
                <li>Output tokens: <?php echo esc_html( number_format_i18n( $usage['output_tokens'] ) ); ?></li>
                <li>Cache reads: <?php echo esc_html( number_format_i18n( $usage['cache_read_tokens'] ) ); ?></li>
                <li>Web fetches: <?php echo esc_html( number_format_i18n( $usage['web_fetch_count'] ) ); ?></li>
                <li>Estimated cost: $<?php echo esc_html( number_format_i18n( $usage['cost_usd'], 4 ) ); ?></li>
            </ul>
        </div>
        <?php
    }
}
```

- [ ] **Step 5: Wire into Bootstrap and model filters**

Append to `src/Bootstrap.php`'s `register()`:

```php
( new \PedimentAi\Settings\Page() )->register();

add_filter( 'pediment_ai_model_compose', static function ( $default ) {
    $val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_compose', '' );
    return '' !== $val ? $val : $default;
} );
add_filter( 'pediment_ai_model_edit', static function ( $default ) {
    $val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_compose', '' );
    return '' !== $val ? $val : $default;
} );
add_filter( 'pediment_ai_model_refine', static function ( $default ) {
    $val = ( new \PedimentAi\Settings\OptionsStore() )->get( 'model_refine', '' );
    return '' !== $val ? $val : $default;
} );
```

Also update the mock-mode check in the existing provider filter:

```php
add_filter( 'pediment_ai_provider', static function ( $default ) {
    $mock_const   = defined( 'PEDIMENT_AI_MOCK' ) && PEDIMENT_AI_MOCK;
    $mock_setting = (bool) ( new \PedimentAi\Settings\OptionsStore() )->get( 'mock_mode', false );
    if ( $mock_const || $mock_setting ) {
        return new \PedimentAi\Mock\MockProvider( PEDIMENT_AI_PLUGIN_DIR . '/src/Mock/fixtures' );
    }
    return $default;
} );
```

- [ ] **Step 6: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter OptionsStoreTest
```

Expected: 4 tests pass.

- [ ] **Step 7: Manual smoke test**

```bash
open http://localhost:8888/wp-admin/options-general.php?page=pediment-ai
```

Expected: settings page renders; saving a key persists; toggling mock mode persists.

- [ ] **Step 8: Commit**

```bash
git add src/Settings/ src/Bootstrap.php tests/phpunit/Settings/OptionsStoreTest.php
git commit -m "feat(settings): OptionsStore (encrypted key) + admin settings page"
```

---

## Phase 8: Editor sidebar

### Task 26: DocumentPanel

**Files:**
- Create: `editor/DocumentPanel.tsx`
- Create: `editor/index.tsx` (replace stub)
- Create: `editor/styles.scss`
- Modify: `src/Bootstrap.php` (enqueue editor script)

- [ ] **Step 1: Write editor/index.tsx**

```tsx
import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import './styles.scss';

registerPlugin('pediment-ai-document-panel', {
  render: DocumentPanel,
});
```

- [ ] **Step 2: Write editor/DocumentPanel.tsx**

```tsx
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ComposeModal from './ComposeModal';
import EditModal from './EditModal';

type Mode = 'idle' | 'compose' | 'edit';

export default function DocumentPanel() {
  const [mode, setMode] = useState<Mode>('idle');

  return (
    <>
      <PluginDocumentSettingPanel name="pediment-ai" title="AI" className="pediment-ai__panel">
        <Button variant="primary"   onClick={() => setMode('compose')} style={{ marginRight: 8 }}>
          {__('Compose with AI', 'pediment-ai')}
        </Button>
        <Button variant="secondary" onClick={() => setMode('edit')}>
          {__('Edit with AI', 'pediment-ai')}
        </Button>
      </PluginDocumentSettingPanel>

      {mode === 'compose' && <ComposeModal onClose={() => setMode('idle')} />}
      {mode === 'edit'    && <EditModal    onClose={() => setMode('idle')} />}
    </>
  );
}
```

- [ ] **Step 3: Write editor/styles.scss**

```scss
.pediment-ai {
  &__panel    { /* container */ }
  &__pills    { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  &__pill     { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #f0f0f1; border-radius: 12px; font-size: 11px; color: #1d2327; }
  &__progress { display: flex; align-items: center; gap: 8px; padding: 12px 0; }
  &__error    { color: #b32d2e; margin-top: 8px; }
}
```

- [ ] **Step 4: Enqueue from Bootstrap**

Append to `src/Bootstrap.php`'s `register()`:

```php
add_action( 'enqueue_block_editor_assets', static function () {
    $asset_path = PEDIMENT_AI_PLUGIN_DIR . '/build/index.asset.php';
    if ( ! file_exists( $asset_path ) ) { return; }
    $asset = include $asset_path;
    wp_enqueue_script(
        'pediment-ai-editor',
        PEDIMENT_AI_PLUGIN_URL . 'build/index.js',
        $asset['dependencies'] ?? [],
        $asset['version']      ?? PEDIMENT_AI_VERSION,
        true
    );
    wp_enqueue_style(
        'pediment-ai-editor',
        PEDIMENT_AI_PLUGIN_URL . 'build/index.css',
        [],
        $asset['version']      ?? PEDIMENT_AI_VERSION
    );
} );
```

- [ ] **Step 5: Build and smoke test**

```bash
npm run build
npx wp-env start
open http://localhost:8888/wp-admin/post-new.php?post_type=page
```

Expected: page editor opens; "AI" panel appears in the document sidebar with two buttons. Clicking them produces a JS error (modals not yet implemented — fixed in Task 27).

- [ ] **Step 6: Commit**

```bash
git add editor/index.tsx editor/DocumentPanel.tsx editor/styles.scss src/Bootstrap.php
git commit -m "feat(editor): DocumentPanel with Compose + Edit buttons"
```

### Task 27: ComposeModal + EditModal

**Files:**
- Create: `editor/ComposeModal.tsx`
- Create: `editor/EditModal.tsx`
- Create: `editor/hooks/useApiClient.ts`

- [ ] **Step 1: Write editor/hooks/useApiClient.ts**

```ts
import apiFetch from '@wordpress/api-fetch';

export type JobResponse = { job_id: number };
export type JobStatus = {
  status: 'queued' | 'composing' | 'complete' | 'error';
  urls_fetched: string[];
  progress_note: string | null;
  result: { blocks: any[]; urls_fetched: string[] } | null;
  error: string | null;
};
export type RefineResponse = { attributes: Record<string, any>; innerBlocks: any[] };

export async function postCompose(body: { prompt: string; page_type: string; tone: string }) {
  return apiFetch<JobResponse>({ path: '/pediment-ai/v1/compose', method: 'POST', data: body });
}
export async function postEdit(body: { instruction: string; tree: any[] }) {
  return apiFetch<JobResponse>({ path: '/pediment-ai/v1/edit', method: 'POST', data: body });
}
export async function postRefine(body: { blockName: string; attributes: any; innerBlocks: any[]; instruction: string }) {
  return apiFetch<RefineResponse>({ path: '/pediment-ai/v1/refine', method: 'POST', data: body });
}
export async function getJob(id: number) {
  return apiFetch<JobStatus>({ path: `/pediment-ai/v1/jobs/${id}`, method: 'GET' });
}
```

- [ ] **Step 2: Write editor/ComposeModal.tsx**

```tsx
import { Modal, Button, TextareaControl, SelectControl, RadioControl, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { postCompose } from './hooks/useApiClient';
import useJobPolling from './hooks/useJobPolling';
import SourcePills from './SourcePills';

export default function ComposeModal({ onClose }: { onClose: () => void }) {
  const [prompt,    setPrompt]    = useState('');
  const [pageType,  setPageType]  = useState('landing');
  const [tone,      setTone]      = useState('');
  const [target,    setTarget]    = useState<'replace' | 'insert'>('replace');
  const [jobId,     setJobId]     = useState<number | null>(null);
  const [submitErr, setSubmitErr] = useState<string | null>(null);

  const { status, urls, progressNote, result, error } = useJobPolling(jobId);

  const submit = async () => {
    setSubmitErr(null);
    try {
      const { job_id } = await postCompose({ prompt, page_type: pageType, tone });
      setJobId(job_id);
    } catch (e: any) {
      setSubmitErr(e?.message ?? 'Request failed');
    }
  };

  if (result) {
    const blockTree = result.blocks;
    const markup    = serializeTree(blockTree);
    const parsed    = parse(markup);

    if (target === 'replace') {
      dispatch('core/block-editor').resetBlocks(parsed);
    } else {
      dispatch('core/block-editor').insertBlocks(parsed);
    }
    onClose();
    return null;
  }

  return (
    <Modal title={__('Compose with AI', 'pediment-ai')} onRequestClose={onClose} className="pediment-ai__modal">
      {jobId === null && (
        <>
          <TextareaControl
            label={__('Prompt', 'pediment-ai')}
            value={prompt}
            onChange={setPrompt}
            rows={5}
            placeholder={__('Describe the page you want…', 'pediment-ai')}
          />
          <SelectControl
            label={__('Page type', 'pediment-ai')}
            value={pageType}
            options={[
              { label: 'Landing',  value: 'landing' },
              { label: 'About',    value: 'about' },
              { label: 'Services', value: 'services' },
              { label: 'Contact',  value: 'contact' },
              { label: 'Other',    value: 'other' },
            ]}
            onChange={setPageType}
          />
          <TextareaControl
            label={__('Tone (optional)', 'pediment-ai')}
            value={tone}
            onChange={setTone}
            rows={2}
          />
          <RadioControl
            label={__('What to do with the result', 'pediment-ai')}
            selected={target}
            options={[
              { label: 'Replace current page',    value: 'replace' },
              { label: 'Insert at cursor',        value: 'insert' },
            ]}
            onChange={(v) => setTarget(v as 'replace' | 'insert')}
          />
          {submitErr && <p className="pediment-ai__error">{submitErr}</p>}
          <Button variant="primary" onClick={submit} disabled={!prompt.trim()}>
            {__('Compose', 'pediment-ai')}
          </Button>
        </>
      )}

      {jobId !== null && status !== 'complete' && status !== 'error' && (
        <div className="pediment-ai__progress">
          <Spinner />
          <span>{progressNote ?? 'Working…'}</span>
        </div>
      )}
      {urls.length > 0 && <SourcePills urls={urls} />}
      {status === 'error' && <p className="pediment-ai__error">{error ?? __('Compose failed.', 'pediment-ai')}</p>}
    </Modal>
  );
}

function serializeTree(tree: any[]): string {
  return tree.map(serializeOne).join('\n\n');
}
function serializeOne(node: any): string {
  const attrs = Object.keys(node.attributes || {}).length ? ' ' + JSON.stringify(node.attributes) : '';
  const inner = (node.innerBlocks || []).map(serializeOne).join('\n');
  if (!inner) {
    return `<!-- wp:${node.name}${attrs} /-->`;
  }
  return `<!-- wp:${node.name}${attrs} -->\n${inner}\n<!-- /wp:${node.name} -->`;
}
```

- [ ] **Step 3: Write editor/EditModal.tsx**

```tsx
import { Modal, Button, TextareaControl, Spinner, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { select, dispatch } from '@wordpress/data';
import { parse, serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { postEdit } from './hooks/useApiClient';
import useJobPolling from './hooks/useJobPolling';
import SourcePills from './SourcePills';

export default function EditModal({ onClose }: { onClose: () => void }) {
  const [instruction, setInstruction] = useState('');
  const [jobId,        setJobId]       = useState<number | null>(null);
  const [submitErr,    setSubmitErr]   = useState<string | null>(null);

  const { status, urls, progressNote, result, error } = useJobPolling(jobId);

  const submit = async () => {
    setSubmitErr(null);
    const currentBlocks = (select('core/block-editor') as any).getBlocks();
    const tree = blocksToTree(currentBlocks);
    try {
      const { job_id } = await postEdit({ instruction, tree });
      setJobId(job_id);
    } catch (e: any) {
      setSubmitErr(e?.message ?? 'Request failed');
    }
  };

  if (result) {
    const markup = serializeTree(result.blocks);
    (dispatch('core/block-editor') as any).resetBlocks(parse(markup));
    onClose();
    return null;
  }

  return (
    <Modal title={__('Edit with AI', 'pediment-ai')} onRequestClose={onClose}>
      <Notice status="warning" isDismissible={false}>
        {__('This will replace your current page. Use Undo (Cmd/Ctrl+Z) to revert.', 'pediment-ai')}
      </Notice>

      {jobId === null && (
        <>
          <TextareaControl
            label={__('Instruction', 'pediment-ai')}
            value={instruction}
            onChange={setInstruction}
            rows={4}
            placeholder={__('Add an FAQ section, shorten the hero, change CTA to…', 'pediment-ai')}
          />
          {submitErr && <p className="pediment-ai__error">{submitErr}</p>}
          <Button variant="primary" onClick={submit} disabled={!instruction.trim()}>
            {__('Edit', 'pediment-ai')}
          </Button>
        </>
      )}

      {jobId !== null && status !== 'complete' && status !== 'error' && (
        <div className="pediment-ai__progress"><Spinner /><span>{progressNote ?? 'Working…'}</span></div>
      )}
      {urls.length > 0 && <SourcePills urls={urls} />}
      {status === 'error' && <p className="pediment-ai__error">{error ?? __('Edit failed.', 'pediment-ai')}</p>}
    </Modal>
  );
}

function blocksToTree(blocks: any[]): any[] {
  return blocks.map((b) => ({
    name: b.name,
    attributes: b.attributes ?? {},
    innerBlocks: blocksToTree(b.innerBlocks ?? []),
  }));
}
function serializeTree(tree: any[]): string {
  return tree.map(serializeOne).join('\n\n');
}
function serializeOne(node: any): string {
  const attrs = Object.keys(node.attributes || {}).length ? ' ' + JSON.stringify(node.attributes) : '';
  const inner = (node.innerBlocks || []).map(serializeOne).join('\n');
  if (!inner) { return `<!-- wp:${node.name}${attrs} /-->`; }
  return `<!-- wp:${node.name}${attrs} -->\n${inner}\n<!-- /wp:${node.name} -->`;
}
```

- [ ] **Step 4: Build and smoke test (uses Mock fixtures since PEDIMENT_AI_MOCK=true)**

```bash
npm run build
npx wp-env start
open http://localhost:8888/wp-admin/post-new.php?post_type=page
```

Expected: Click "Compose with AI" → modal opens → enter prompt → submit → modal shows progress → result inserts blocks (from `compose-landing.json`).

- [ ] **Step 5: Commit**

```bash
git add editor/ComposeModal.tsx editor/EditModal.tsx editor/hooks/
git commit -m "feat(editor): Compose + Edit modals wired to REST + polling"
```

### Task 28: useJobPolling hook

**Files:**
- Create: `editor/hooks/useJobPolling.ts`

> **Note:** Already referenced in Tasks 27, 29. Implementing the hook here makes the modals/refine work end-to-end.

- [ ] **Step 1: Write editor/hooks/useJobPolling.ts**

```ts
import { useEffect, useRef, useState } from '@wordpress/element';
import { getJob, JobStatus } from './useApiClient';

const POLL_MS = 750;

export type PollResult = {
  status: JobStatus['status'] | 'idle';
  urls: string[];
  progressNote: string | null;
  result: JobStatus['result'];
  error: string | null;
};

export default function useJobPolling(jobId: number | null): PollResult {
  const [state, setState] = useState<PollResult>({
    status: 'idle',
    urls: [],
    progressNote: null,
    result: null,
    error: null,
  });
  const timer = useRef<number | null>(null);

  useEffect(() => {
    if (jobId === null) {
      setState({ status: 'idle', urls: [], progressNote: null, result: null, error: null });
      return;
    }

    let cancelled = false;

    const tick = async () => {
      try {
        const job = await getJob(jobId);
        if (cancelled) { return; }
        setState({
          status: job.status,
          urls: job.urls_fetched ?? [],
          progressNote: job.progress_note,
          result: job.result,
          error: job.error,
        });
        if (job.status === 'complete' || job.status === 'error') {
          if (timer.current !== null) { window.clearInterval(timer.current); }
          return;
        }
      } catch (e: any) {
        if (cancelled) { return; }
        setState((s) => ({ ...s, status: 'error', error: e?.message ?? 'Polling failed' }));
        if (timer.current !== null) { window.clearInterval(timer.current); }
      }
    };

    tick(); // immediate first poll
    timer.current = window.setInterval(tick, POLL_MS);

    return () => {
      cancelled = true;
      if (timer.current !== null) { window.clearInterval(timer.current); }
    };
  }, [jobId]);

  return state;
}
```

- [ ] **Step 2: Build, run E2E smoke (covered formally in Task 32)**

```bash
npm run build
```

Expected: no build errors.

- [ ] **Step 3: Commit**

```bash
git add editor/hooks/useJobPolling.ts
git commit -m "feat(editor): useJobPolling hook with 750ms cadence + cleanup"
```

### Task 29: BlockPanel + RefineActions

**Files:**
- Create: `editor/BlockPanel.tsx`
- Create: `editor/RefineActions.tsx`
- Modify: `editor/index.tsx` (register block-controls filter)

- [ ] **Step 1: Write editor/RefineActions.tsx**

```tsx
import { Button, TextareaControl, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { postRefine } from './hooks/useApiClient';

const QUICK_ACTIONS: Record<string, { label: string; instruction: string }[]> = {
  'pediment/hero': [
    { label: 'Punchier',         instruction: 'Make it punchier and more benefit-led.' },
    { label: 'Different angle',  instruction: 'Try a completely different angle.' },
  ],
  'pediment/cta': [
    { label: 'More urgent',      instruction: 'Make it more urgent.' },
    { label: 'Shorter',          instruction: 'Make it shorter.' },
  ],
  'pediment/faq-item': [
    { label: 'Tighter answer',   instruction: 'Make the answer tighter.' },
  ],
};

export default function RefineActions({ clientId, name, attributes, innerBlocks }: {
  clientId: string;
  name: string;
  attributes: Record<string, any>;
  innerBlocks: any[];
}) {
  const [custom,  setCustom]  = useState('');
  const [loading, setLoading] = useState(false);
  const [err,     setErr]     = useState<string | null>(null);

  const trigger = async (instruction: string) => {
    setErr(null); setLoading(true);
    try {
      const res = await postRefine({ blockName: name, attributes, innerBlocks: innerBlocksToTree(innerBlocks), instruction });
      (dispatch('core/block-editor') as any).updateBlockAttributes(clientId, res.attributes);
      if (Array.isArray(res.innerBlocks)) {
        const parsed = treeToInnerBlocks(res.innerBlocks);
        (dispatch('core/block-editor') as any).replaceInnerBlocks(clientId, parsed);
      }
    } catch (e: any) {
      setErr(e?.message ?? 'Refine failed');
    } finally {
      setLoading(false);
    }
  };

  const quick = QUICK_ACTIONS[name] ?? [];

  return (
    <div>
      {quick.map((qa) => (
        <Button key={qa.label} variant="secondary" onClick={() => trigger(qa.instruction)} disabled={loading} style={{ marginRight: 6, marginBottom: 6 }}>
          {qa.label}
        </Button>
      ))}
      <TextareaControl label={__('Custom instruction', 'pediment-ai')} value={custom} onChange={setCustom} rows={2} />
      <Button variant="primary" onClick={() => trigger(custom)} disabled={loading || !custom.trim()}>
        {__('Refine', 'pediment-ai')}
      </Button>
      {loading && <Spinner />}
      {err && <p className="pediment-ai__error">{err}</p>}
    </div>
  );
}

function innerBlocksToTree(blocks: any[]): any[] {
  return blocks.map((b) => ({ name: b.name, attributes: b.attributes ?? {}, innerBlocks: innerBlocksToTree(b.innerBlocks ?? []) }));
}
function treeToInnerBlocks(tree: any[]): any[] {
  const { createBlock } = (window as any).wp.blocks;
  return tree.map((node: any) => createBlock(node.name, node.attributes ?? {}, treeToInnerBlocks(node.innerBlocks ?? [])));
}
```

- [ ] **Step 2: Write editor/BlockPanel.tsx**

```tsx
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import RefineActions from './RefineActions';

const STARTER_BLOCKS = /^starter\//;

export function registerRefinePanel() {
  const withRefine = createHigherOrderComponent((BlockEdit: any) => (props: any) => {
    if (!STARTER_BLOCKS.test(props.name)) {
      return <BlockEdit {...props} />;
    }
    return (
      <>
        <BlockEdit {...props} />
        <InspectorControls>
          <PanelBody title={__('AI refine', 'pediment-ai')} initialOpen={false}>
            <RefineActions
              clientId={props.clientId}
              name={props.name}
              attributes={props.attributes}
              innerBlocks={(props as any).innerBlocks ?? []}
            />
          </PanelBody>
        </InspectorControls>
      </>
    );
  }, 'withPedimentAiRefine');

  addFilter('editor.BlockEdit', 'pediment-ai/refine-panel', withRefine);
}
```

- [ ] **Step 3: Wire into editor/index.tsx**

Update `editor/index.tsx`:

```tsx
import { registerPlugin } from '@wordpress/plugins';
import DocumentPanel from './DocumentPanel';
import { registerRefinePanel } from './BlockPanel';
import './styles.scss';

registerRefinePanel();

registerPlugin('pediment-ai-document-panel', {
  render: DocumentPanel,
});
```

- [ ] **Step 4: Build and smoke test**

```bash
npm run build
npx wp-env start
```

Open the editor, select a starter block — Inspector panel shows "AI refine" with quick actions. Click one → attributes update.

- [ ] **Step 5: Commit**

```bash
git add editor/BlockPanel.tsx editor/RefineActions.tsx editor/index.tsx
git commit -m "feat(editor): BlockPanel + RefineActions — per-block AI refine"
```

### Task 30: SourcePills

**Files:**
- Create: `editor/SourcePills.tsx`

- [ ] **Step 1: Write editor/SourcePills.tsx**

```tsx
import { __ } from '@wordpress/i18n';

export default function SourcePills({ urls }: { urls: string[] }) {
  if (!urls.length) { return null; }
  return (
    <div className="pediment-ai__pills">
      <span style={{ marginRight: 4, fontSize: 11, color: '#646970' }}>
        {__('Sources:', 'pediment-ai')}
      </span>
      {urls.map((url, i) => (
        <a key={`${url}-${i}`} className="pediment-ai__pill" href={url} target="_blank" rel="noreferrer">
          {hostOf(url)}
        </a>
      ))}
    </div>
  );
}

function hostOf(url: string): string {
  try { return new URL(url).host.replace(/^www\./, ''); }
  catch { return url; }
}
```

- [ ] **Step 2: Build (already referenced in ComposeModal/EditModal — this completes the component)**

```bash
npm run build
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add editor/SourcePills.tsx
git commit -m "feat(editor): SourcePills component rendering web_fetch URLs"
```

---

## Phase 9: WP-CLI utility

### Task 31: `wp pediment-ai dump-schema`

**Files:**
- Create: `wp-cli/DumpSchemaCommand.php`
- Modify: `plugin.php` (register command)
- Create: `tests/phpunit/Cli/DumpSchemaCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace PedimentAi\Tests\Cli;

use PedimentAi\Cli\DumpSchemaCommand;

class DumpSchemaCommandTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        \PedimentAi\Anthropic\SchemaBuilder::invalidate();
        register_block_type( 'pediment/test', [ 'attributes' => [ 'x' => [ 'type' => 'string' ] ], 'description' => 'T' ] );
    }

    public function tearDown(): void {
        unregister_block_type( 'pediment/test' );
        parent::tearDown();
    }

    public function test_writes_schema_to_specified_path(): void {
        $path = sys_get_temp_dir() . '/pediment-ai-schema-' . uniqid() . '.json';
        ( new DumpSchemaCommand() )->__invoke( [], [ 'output' => $path ] );

        $this->assertFileExists( $path );
        $data = json_decode( (string) file_get_contents( $path ), true );
        $this->assertArrayHasKey( 'pediment/test', $data['blocks'] );
        unlink( $path );
    }
}
```

- [ ] **Step 2: Run and verify fail**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter DumpSchemaCommandTest
```

Expected: FAIL.

- [ ] **Step 3: Implement wp-cli/DumpSchemaCommand.php**

```php
<?php
declare(strict_types=1);

namespace PedimentAi\Cli;

use PedimentAi\Anthropic\SchemaBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DumpSchemaCommand {
    /**
     * Dumps the runtime block schema to a JSON file.
     *
     * ## OPTIONS
     *
     * [--output=<path>]
     * : File path to write to. Defaults to plugin's schema/blocks.json.
     *
     * @when after_wp_load
     */
    public function __invoke( array $args, array $assoc_args ): void {
        $schema = ( new SchemaBuilder() )->build( true );
        $path   = isset( $assoc_args['output'] )
            ? (string) $assoc_args['output']
            : PEDIMENT_AI_PLUGIN_DIR . '/schema/blocks.json';

        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0777, true );
        }
        file_put_contents( $path, wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        if ( class_exists( '\\WP_CLI' ) ) {
            \WP_CLI::success( "Schema written to {$path} (" . count( $schema['blocks'] ) . ' blocks)' );
        }
    }
}
```

- [ ] **Step 4: Register in plugin.php**

Append before the `add_action( 'plugins_loaded', ... )`:

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/wp-cli/DumpSchemaCommand.php';
    \WP_CLI::add_command( 'pediment-ai dump-schema', \PedimentAi\Cli\DumpSchemaCommand::class );
}
```

- [ ] **Step 5: Run the tests**

```bash
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit --filter DumpSchemaCommandTest
```

Expected: 1 test passes.

- [ ] **Step 6: Smoke test the CLI**

```bash
npx wp-env run cli --env-cwd=wp-content/plugins/pediment-ai "wp pediment-ai dump-schema --output=/tmp/schema.json"
cat /tmp/schema.json | head -20
```

Expected: prints JSON with `blocks` object.

- [ ] **Step 7: Commit**

```bash
git add wp-cli/DumpSchemaCommand.php plugin.php tests/phpunit/Cli/DumpSchemaCommandTest.php
git commit -m "feat(cli): wp pediment-ai dump-schema writes runtime schema to JSON"
```

---

## Phase 10: End-to-end tests (mock mode)

### Task 32: E2E specs

**Files:**
- Create: `tests/e2e/utils.ts`
- Create: `tests/e2e/compose.spec.ts`
- Create: `tests/e2e/edit.spec.ts`
- Create: `tests/e2e/refine.spec.ts`

> **Mock mode:** `.wp-env.json` sets `PEDIMENT_AI_MOCK=true`, so all flows return fixtures deterministically. No Anthropic key needed.

- [ ] **Step 1: Write tests/e2e/utils.ts**

```ts
import { Page } from '@playwright/test';

export async function login(page: Page) {
  await page.goto('/wp-login.php');
  await page.fill('input#user_login', 'admin');
  await page.fill('input#user_pass', 'password');
  await page.click('input#wp-submit');
  await page.waitForURL(/wp-admin/);
}

export async function openNewPage(page: Page, title: string) {
  await page.goto('/wp-admin/post-new.php?post_type=page');
  const welcome = page.getByRole('button', { name: /close dialog/i });
  if (await welcome.count()) { await welcome.first().click(); }
  await page.getByRole('textbox', { name: /add title/i }).fill(title);
}

export async function openDocumentSidebar(page: Page) {
  const btn = page.getByRole('button', { name: /document sidebar|settings/i });
  if (await btn.count()) { await btn.first().click(); }
}
```

- [ ] **Step 2: Write tests/e2e/compose.spec.ts**

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('compose with AI inserts blocks from mock fixture', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Compose E2E');

  await page.getByRole('button', { name: /compose with ai/i }).click();
  await page.getByRole('textbox', { name: /prompt/i }).fill('A landing page for an agency');
  await page.getByRole('button', { name: /^compose$/i }).click();

  await expect(page.locator('.wp-block-pediment-hero')).toBeVisible({ timeout: 15_000 });
});
```

- [ ] **Step 3: Write tests/e2e/edit.spec.ts**

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('edit with AI replaces page content', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Edit E2E');

  // Seed a hero block to edit.
  await page.locator('.editor-styles-wrapper').click();
  await page.keyboard.type('/hero');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: /edit with ai/i }).click();
  await page.getByRole('textbox', { name: /instruction/i }).fill('add an faq');
  await page.getByRole('button', { name: /^edit$/i }).click();

  await expect(page.locator('.wp-block-pediment-faq')).toBeVisible({ timeout: 15_000 });
});
```

- [ ] **Step 4: Write tests/e2e/refine.spec.ts**

```ts
import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('refine updates a single block', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Refine E2E');

  await page.locator('.editor-styles-wrapper').click();
  await page.keyboard.type('/hero');
  await page.keyboard.press('Enter');

  // Select the hero block.
  await page.locator('.wp-block-pediment-hero').first().click();

  // Open the AI refine panel.
  await page.getByRole('button', { name: /^ai refine$/i }).click();
  await page.getByRole('textbox', { name: /custom instruction/i }).fill('Make it punchier');
  await page.getByRole('button', { name: /^refine$/i }).click();

  await expect(page.locator('.wp-block-pediment-hero h1')).toContainText(/punchier/i, { timeout: 10_000 });
});
```

- [ ] **Step 5: Build, run E2E**

```bash
( cd ../pediment && npm run build )
npx wp-env start
npm run build
npm run e2e
```

Expected: 3 new tests pass (plus the original smoke test).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/
git commit -m "test(e2e): compose, edit, refine flows against mock fixtures"
```

---

## Phase 11: Documentation

### Task 33: README + docs/prompts.md + docs/privacy.md

**Files:**
- Modify: `README.md` (replace skeleton)
- Create: `docs/prompts.md`
- Create: `docs/privacy.md`

- [ ] **Step 1: Replace README.md**

```markdown
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
- **Refine.** Select any starter block → Inspector → "AI refine" → quick actions or custom instruction → attributes update.

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

```bash
composer install
npm install
( cd ../pediment && npm install && npm run build )
npm run build
npm run env:start    # http://localhost:8888 (admin/password)
```

Mock mode is on by default in `.wp-env.json` (`PEDIMENT_AI_MOCK=true`), so the plugin returns fixture responses instead of calling Anthropic. Toggle off in plugin settings to test against real Anthropic.

See [docs/prompts.md](docs/prompts.md) for prompt tuning and [docs/privacy.md](docs/privacy.md) for data-handling disclosures clients should include in their privacy policies.
```

- [ ] **Step 2: Write docs/prompts.md**

```markdown
# Tuning prompts

The system prompt sent on Compose/Edit is assembled in `Jobs/ComposeJob::systemBlock()`. To override per-deploy, hook the `pediment_ai_system_prompt` filter (added in v0.2 — for v0.1 modify ComposeJob directly).

## What goes in

- Hard rules ("always call emit_page", "use only registered blocks").
- The list of available block names.
- Brand context (brand name + voice/tone from Brand Settings).
- Permission to use web_fetch.

## What stays out

Don't bake page-type-specific guidance into the system prompt. Page-type signals come from the user message (`Page type: landing`) — the model handles routing.

## Few-shot examples

v0.1 doesn't include few-shot examples. If output quality on a specific page type is weak, add a 1-2 example exchange before the user message in `Jobs/ComposeJob::buildRequest()`. Keep examples short — they balloon token usage.

## Tone

Tone arrives via the user message. The default tone if unset is the brand `voice_tone` from Brand Settings. If both are empty, the model uses its default voice.
```

- [ ] **Step 3: Write docs/privacy.md**

```markdown
# Privacy / GDPR disclosure

When this plugin runs, the following data is sent to Anthropic:

- The editor's prompt text (Compose / Edit / Refine).
- Brand Settings values: brand name, tagline, voice/tone.
- For Edit and Refine: the current block tree (including all editorial copy) of the page being edited.
- For Compose / Edit: URLs the model decides to fetch via `web_fetch`. Anthropic fetches these from its own infrastructure.

No customer data, contact-form submissions, user accounts, or commerce data are sent.

## Recommended privacy-policy paragraph (German clients)

> Unsere Website nutzt zur Inhalts­erstellung im Backend einen KI-Dienst der Anthropic, PBC (548 Market St, San Francisco, CA 94104, USA). Bei der Nutzung der Funktion werden die Eingabe des Redakteurs sowie Marken­einstellungen unserer Website an Anthropic übertragen. Eine Verarbeitung erfolgt auf Grundlage unseres berechtigten Interesses gemäß Art. 6 Abs. 1 lit. f DSGVO. Datenübermittlung in die USA erfolgt auf Basis der EU-Standard­vertrags­klauseln.

## Recommended privacy-policy paragraph (English)

> Our website uses an AI service from Anthropic, PBC (548 Market St, San Francisco, CA 94104, USA) for internal content drafting. When this feature is used, the editor's prompt and our brand settings are transmitted to Anthropic. Processing is based on our legitimate interest under Art. 6(1)(f) GDPR. Transfers to the US rely on the EU Standard Contractual Clauses.

## Disable

Toggle "Mock mode" on in Settings → Pediment AI, or unset `ANTHROPIC_API_KEY` and clear the key in settings. The plugin's UI remains visible but cannot reach Anthropic.
```

- [ ] **Step 4: Commit**

```bash
git add README.md docs/
git commit -m "docs: README + prompt-tuning + GDPR disclosure"
```

---

## Phase 12: Release

### Task 34: Tag v0.1.0 + Composer resolution

**Files:** none.

- [ ] **Step 1: Verify the full matrix passes locally**

```bash
( cd ../pediment && npm run build )
npx wp-env start
composer install
npm ci
npm run build
composer lint
npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai phpunit
npm run e2e
```

Expected: all green.

- [ ] **Step 2: Bump version metadata**

In `plugin.php`, ensure `Version: 0.1.0`. In `package.json`, ensure `"version": "0.1.0"`. In `composer.json`, no version field (Composer reads tags).

- [ ] **Step 3: Push and tag**

```bash
gh repo create bergert/pediment-ai --private --source=. --remote=origin --push
gh run watch    # wait for CI green
git tag v0.1.0
git push origin v0.1.0
gh release create v0.1.0 --title "v0.1.0 — initial release" --notes "First versioned release of pediment-ai. Compose, Edit, Refine in mock mode out of the box."
```

- [ ] **Step 4: Verify Composer resolution**

```bash
mkdir -p /tmp/pediment-ai-resolve
cd /tmp/pediment-ai-resolve
cat > composer.json <<'EOF'
{
  "name": "test/resolve",
  "repositories": [
    { "type": "vcs", "url": "git@github.com:bergert/pediment-ai.git" }
  ],
  "require": { "bergert/pediment-ai": "^0.1" },
  "minimum-stability": "dev",
  "prefer-stable": true
}
EOF
composer install --no-interaction
ls vendor/bergert/pediment-ai/plugin.php
```

Expected: resolves at v0.1.0; `plugin.php` exists.

- [ ] **Step 5: Clean up**

```bash
rm -rf /tmp/pediment-ai-resolve
```

Plan B complete. Move on to Plan C (client template) — `wp-client-template`.

---

## Self-review notes

- **Spec coverage:** Tasks 1–34 cover the spec's full v1 scope for the AI plugin: scaffold, Anthropic client, block-tree parse/serialize/validate, runtime schema discovery + caching, tool-use parsing for `emit_page` / `emit_block` / `web_fetch`, jobs table + Action Scheduler worker, REST endpoints for compose/edit/refine/status with rate limiting, mock provider with 9 fixtures, usage tracker + pricing, encrypted settings store, full editor sidebar (DocumentPanel/Compose/Edit/BlockPanel/Refine/SourcePills/useJobPolling), WP-CLI dump-schema, three E2E specs in mock mode, README + prompts + privacy docs, v0.1.0 release.
- **Type consistency:** the `ProviderInterface` is used uniformly by `Client` (Task 8/16), `MockProvider` (Task 21), and all REST controllers (filtered via `pediment_ai_provider`). Block-tree shape `{name, attributes, innerBlocks}` is consistent across Parser/Serializer/Validator/ComposeJob/SchemaBuilder. Job lifecycle states `queued → composing → complete|error` are honored across JobStore, ComposeJob, StatusController, and the `useJobPolling` hook. Option keys (`pediment_ai_settings`, `pediment_ai_rate_limits`, `pediment_ai_db_version`) are reused consistently.
- **No placeholders:** every task contains real code, real tests, and exact commands. No "TBD" / "Similar to Task N" references.
- **Plan A dependency:** the wp-env config in Task 3 mounts the sibling theme; CI workflow in Task 7 checks out both repos. E2E in Task 32 relies on the theme's blocks being built and registered. Plan A v0.1.0 must be installable (Composer or wp-env mount) before Plan B can run.
- **Open items deferred to execution:** Composer distribution mechanism (matches Plan A's open call — GitHub VCS repo with `composer/installers` is the default plan). Pricing constants (Task 23) need a final refresh against current Anthropic prices before release. Few-shot examples for system prompts (mentioned in `docs/prompts.md`) — add when output quality on a specific page type proves weak in practice.
