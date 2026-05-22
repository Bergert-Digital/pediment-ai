# Extensibility Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two extensibility filters to the AI plugin so a child theme can register blocks under its own namespace and customize the system prompt without forking the plugin.

**Architecture:** Two `apply_filters()` wrappers around existing logic. No structural change.

- `pediment_ai_block_namespaces` — defaults to `['pediment', 'client']`, used to build the namespace regex in `SchemaBuilder::build()`.
- `pediment_ai_system_prompt` — wraps the return value of `PromptBuilder::systemPrompt()`, receives the prompt string and the block schema as parameters.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, PHPUnit + `WP_UnitTestCase`.

---

## File Structure

- **Modify:** `src/Anthropic/SchemaBuilder.php` (line 82 — the namespace regex)
- **Modify:** `src/Chat/PromptBuilder.php` (line 34 — `systemPrompt()` return)
- **Modify:** `tests/phpunit/Anthropic/SchemaBuilderTest.php` (add filter tests)
- **Modify:** `tests/phpunit/Chat/PromptBuilderTest.php` (add filter test)

---

## Task 1: Add failing test for `pediment_ai_block_namespaces` filter

**Files:**
- Modify: `tests/phpunit/Anthropic/SchemaBuilderTest.php`

- [ ] **Step 1: Read the existing test file to understand its conventions**

Open `tests/phpunit/Anthropic/SchemaBuilderTest.php` and note: how does it register/unregister blocks for each test? Is there a setUp/tearDown? Does it call `SchemaBuilder::invalidate()` between tests? Match those conventions in the new test.

- [ ] **Step 2: Append a new test method**

Add inside the existing test class:

```php
public function test_block_namespaces_filter_extends_allowlist() {
    \PedimentAi\Anthropic\SchemaBuilder::invalidate();

    register_block_type(
        'acme/promo-banner',
        array(
            'description' => 'A promotional banner.',
            'attributes'  => array( 'text' => array( 'type' => 'string' ) ),
        )
    );

    add_filter( 'pediment_ai_block_namespaces', function ( $namespaces ) {
        $namespaces[] = 'acme';
        return $namespaces;
    } );

    $schema = ( new \PedimentAi\Anthropic\SchemaBuilder() )->build( true );

    $this->assertArrayHasKey( 'acme/promo-banner', $schema['blocks'] );
    $this->assertSame( 'A promotional banner.', $schema['blocks']['acme/promo-banner']['description'] );

    unregister_block_type( 'acme/promo-banner' );
}

public function test_block_namespaces_default_excludes_unknown_namespaces() {
    \PedimentAi\Anthropic\SchemaBuilder::invalidate();

    register_block_type(
        'thirdparty/widget',
        array(
            'description' => 'Should be ignored by default.',
            'attributes'  => array(),
        )
    );

    $schema = ( new \PedimentAi\Anthropic\SchemaBuilder() )->build( true );

    $this->assertArrayNotHasKey( 'thirdparty/widget', $schema['blocks'] );

    unregister_block_type( 'thirdparty/widget' );
}
```

- [ ] **Step 3: Run the test to verify the filter test fails**

Run: `vendor/bin/phpunit --filter test_block_namespaces_filter_extends_allowlist`
Expected: FAIL — `acme/promo-banner` is not in the schema because the regex `'#^(pediment|client)/#'` is hardcoded.

Run: `vendor/bin/phpunit --filter test_block_namespaces_default_excludes_unknown_namespaces`
Expected: PASS — establishes the baseline behavior we preserve.

---

## Task 2: Implement `pediment_ai_block_namespaces` filter

**Files:**
- Modify: `src/Anthropic/SchemaBuilder.php` (line 82)

- [ ] **Step 1: Replace the hardcoded regex with a filtered namespace list**

In `src/Anthropic/SchemaBuilder.php`, find:

```php
foreach ( $registry->get_all_registered() as $name => $type ) {
    if ( ! preg_match( '#^(pediment|client)/#', (string) $name ) ) {
        continue;
    }
```

Replace with:

```php
/**
 * Filter the block namespaces that the AI plugin discovers.
 *
 * @param array<int,string> $namespaces Namespace prefixes (without trailing slash).
 */
$namespaces = (array) apply_filters( 'pediment_ai_block_namespaces', array( 'pediment', 'client' ) );
$pattern    = '#^(' . implode( '|', array_map( 'preg_quote', $namespaces ) ) . ')/#';

foreach ( $registry->get_all_registered() as $name => $type ) {
    if ( ! preg_match( $pattern, (string) $name ) ) {
        continue;
    }
```

- [ ] **Step 2: Run the filter test**

Run: `vendor/bin/phpunit --filter test_block_namespaces_filter_extends_allowlist`
Expected: PASS.

- [ ] **Step 3: Run the full Anthropic test suite to check for regressions**

Run: `vendor/bin/phpunit --testsuite plugin --filter Anthropic`
Expected: All Anthropic tests pass — `ClientStreamTest`, `ClientTest`, `SchemaBuilderTest`, `ToolUseParserTest`.

- [ ] **Step 4: Commit**

```bash
git add src/Anthropic/SchemaBuilder.php tests/phpunit/Anthropic/SchemaBuilderTest.php
git commit -m "feat(schema): make AI block namespaces filterable via pediment_ai_block_namespaces"
```

---

## Task 3: Add failing test for `pediment_ai_system_prompt` filter

**Files:**
- Modify: `tests/phpunit/Chat/PromptBuilderTest.php`

- [ ] **Step 1: Read the existing test file to learn its constructor conventions for PromptBuilder**

Open `tests/phpunit/Chat/PromptBuilderTest.php` and note how PromptBuilder is instantiated (the `$blockSchema` constructor argument shape).

- [ ] **Step 2: Append a new test method**

Add inside the existing test class:

```php
public function test_system_prompt_is_filterable() {
    add_filter( 'pediment_ai_system_prompt', function ( $prompt, $schema ) {
        $this->assertIsString( $prompt );
        $this->assertIsArray( $schema );
        return $prompt . "\n\nAcme brand voice: confident and concise.";
    }, 10, 2 );

    $builder = new \PedimentAi\Chat\PromptBuilder( array(
        'pediment/hero' => array(
            'description'       => 'A hero block.',
            'attributes'        => array(),
            'allowsInnerBlocks' => false,
        ),
    ) );

    $prompt = $builder->systemPrompt();

    $this->assertStringContainsString( 'Acme brand voice: confident and concise.', $prompt );
    $this->assertStringContainsString( 'pediment/hero', $prompt, 'Original prompt content must still be present.' );
}
```

- [ ] **Step 3: Run the new test to verify it fails**

Run: `vendor/bin/phpunit --filter test_system_prompt_is_filterable`
Expected: FAIL — the filter is never applied, so `Acme brand voice` is not in the prompt.

---

## Task 4: Implement `pediment_ai_system_prompt` filter

**Files:**
- Modify: `src/Chat/PromptBuilder.php` (line 34)

- [ ] **Step 1: Wrap the return value of `systemPrompt()` in `apply_filters()`**

In `src/Chat/PromptBuilder.php`, find:

```php
public function systemPrompt(): string {
    $lines   = [];
    $lines[] = 'You are an AI assistant inside the WordPress block editor...';
    // ... existing logic ...
    return implode( "\n", $lines );
}
```

Change the return line from:

```php
    return implode( "\n", $lines );
```

to:

```php
    $prompt = implode( "\n", $lines );

    /**
     * Filter the system prompt used by the AI plugin for chat turns.
     *
     * @param string                              $prompt      Composed system prompt.
     * @param array<string,array<string,mixed>>   $blockSchema The block schema available to this turn.
     */
    return (string) apply_filters( 'pediment_ai_system_prompt', $prompt, $this->blockSchema );
}
```

- [ ] **Step 2: Run the filter test**

Run: `vendor/bin/phpunit --filter test_system_prompt_is_filterable`
Expected: PASS.

- [ ] **Step 3: Run the full Chat test suite**

Run: `vendor/bin/phpunit --testsuite plugin --filter Chat`
Expected: All Chat tests pass — `ConversationStoreTest`, `PromptBuilderTest`, `ToolsTest`, `TurnRunnerTest`, `VirtualTreeTest`.

- [ ] **Step 4: Commit**

```bash
git add src/Chat/PromptBuilder.php tests/phpunit/Chat/PromptBuilderTest.php
git commit -m "feat(prompt): expose pediment_ai_system_prompt filter for child-theme customization"
```

---

## Task 5: Document the extension surface

**Files:**
- Modify: `docs/` — find the closest existing doc to extend (likely `docs/prompts.md` or similar); else create `docs/extending.md`

- [ ] **Step 1: Pick the doc location**

Run: `ls docs/`

If a doc named `extending.md`, `filters.md`, or similar exists, extend it. Otherwise create `docs/extending.md`.

- [ ] **Step 2: Write the docs**

Add the following content (either appended to an existing doc or as the body of `docs/extending.md`):

````markdown
# Extending pediment-ai from a child theme

The plugin exposes filters so a child theme can register blocks under its own namespace and customize the system prompt without forking the plugin.

## Block discovery namespaces

By default the plugin discovers blocks under the `pediment/` and `client/` namespaces. To allow an additional namespace:

```php
add_filter( 'pediment_ai_block_namespaces', function ( $namespaces ) {
    $namespaces[] = 'acme';
    return $namespaces;
} );
```

Blocks under `acme/*` registered with a non-empty `description` will then appear in the AI's block schema. Remember to call `\PedimentAi\Anthropic\SchemaBuilder::invalidate()` after changing the filter at runtime (e.g. in tests).

## System prompt

Wrap the prompt to inject brand voice, domain examples, or extra constraints:

```php
add_filter( 'pediment_ai_system_prompt', function ( $prompt, $schema ) {
    return $prompt . "\n\nBrand voice: confident, concise, no marketing fluff.";
}, 10, 2 );
```

The filter receives the composed prompt and the block schema (so you can inspect what blocks are available before appending guidance).
````

- [ ] **Step 3: Commit**

```bash
git add docs/
git commit -m "docs(extending): document pediment_ai_block_namespaces and pediment_ai_system_prompt filters"
```

---

## Self-review checklist (run before handing off)

- [ ] `vendor/bin/phpunit` passes end-to-end (whole suite, not just filtered tests).
- [ ] No hardcoded `(pediment|client)` regex remains in `src/Anthropic/SchemaBuilder.php`.
- [ ] `PromptBuilder::systemPrompt()` returns through `apply_filters`.
- [ ] Tests cover: filter adds a namespace, default namespace allowlist still excludes unknown namespaces, filter modifies the prompt while preserving the original content.
- [ ] No changes outside `src/Anthropic/SchemaBuilder.php`, `src/Chat/PromptBuilder.php`, the two test files, and the docs file.
