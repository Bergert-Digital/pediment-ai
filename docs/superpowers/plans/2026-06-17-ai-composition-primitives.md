# AI Composition Primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the AI composer build section types the library lacks (pricing tables, feature grids, logo walls, comparisons) by composing from `core/columns`/`core/column` primitives, instead of a freeform custom-HTML escape hatch.

**Architecture:** Two additive changes. (1) Add `core/columns` and `core/column` to `SchemaBuilder`'s `CORE_ALLOWLIST`, following the existing `core/list`/`core/list-item` precedent — the existing `Validator` rules then cover them with no Validator code change. (2) Add a composition-guidance paragraph to `PromptBuilder::systemPrompt()` that establishes bespoke-block-first, compose-only-as-fallback, and let-the-theme-style-it.

**Tech Stack:** PHP 8.1, WordPress block editor, PHPUnit (WP_UnitTestCase) run inside `wp-env`.

## Global Constraints

- PHP 8.1; `declare(strict_types=1)` at the top of every PHP file (already present in target files).
- Coding standards: phpcs must pass with **zero warnings** (CI fails on warnings). Run `composer lint` before committing.
- No color/spacing/font literals introduced in code or prompt examples — rely on theme tokens.
- Work directly on the `development` branch; no feature branch/worktree.
- PHPUnit runs only inside wp-env. Base command (run from repo root `/Users/jonas/Entwicklung/pediment-ai`):
  `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit`
  (Start the env first if needed: `npm run env:start`.)
- The new pair must mirror the existing literal declarations exactly: a container declares `allowedChildBlocks`; a parent-locked child declares `requiresParent`. Do NOT touch `src/BlockTree/Validator.php` — it already enforces unknown-block, empty-container, requiresParent-at-depth-0, and allowedChildBlocks membership.

---

### Task 1: Add `core/columns` + `core/column` to the allowlist

**Files:**
- Modify: `src/Anthropic/SchemaBuilder.php` (the `CORE_ALLOWLIST` constant, currently lines 23-86; insert the new pair after the `core/group` entry)
- Test: `tests/phpunit/Anthropic/SchemaBuilderTest.php` (schema shape)
- Test: `tests/phpunit/BlockTree/ValidatorTest.php` (validator behavior against the new pair)

**Interfaces:**
- Consumes: nothing new.
- Produces: schema entries `core/columns` (`allowedChildBlocks: ['core/column']`, `allowsInnerBlocks: true`) and `core/column` (`requiresParent: ['core/columns']`, `allowsInnerBlocks: true`). Task 2 references these names in prompt-guidance assertions but does not import the schema.

- [ ] **Step 1: Write the failing SchemaBuilder test**

Add to `tests/phpunit/Anthropic/SchemaBuilderTest.php` (inside the class, after `test_core_group_is_allowlisted_with_inner_blocks`):

```php
	public function test_columns_pair_is_allowlisted(): void {
		$blocks = ( new SchemaBuilder() )->build( true )['blocks'];

		$this->assertArrayHasKey( 'core/columns', $blocks );
		$this->assertTrue( $blocks['core/columns']['allowsInnerBlocks'] );
		$this->assertSame( [ 'core/column' ], $blocks['core/columns']['allowedChildBlocks'] );

		$this->assertArrayHasKey( 'core/column', $blocks );
		$this->assertTrue( $blocks['core/column']['allowsInnerBlocks'] );
		$this->assertSame( [ 'core/columns' ], $blocks['core/column']['requiresParent'] );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit --filter test_columns_pair_is_allowlisted`
Expected: FAIL — `Failed asserting that an array has the key 'core/columns'`.

- [ ] **Step 3: Add the pair to `CORE_ALLOWLIST`**

In `src/Anthropic/SchemaBuilder.php`, insert this block immediately after the `core/group` entry (after its closing `],` near line 85, still inside the `CORE_ALLOWLIST` array):

```php
		'core/columns' => [
			'description'        => 'A horizontal row of columns — use for multi-column section layouts the library has no dedicated block for (feature/benefit grids, comparison rows, card rows, logo walls). Holds core/column children. Set "align":"wide" so the row uses the theme\'s wide width.',
			'attributes'         => [],
			'allowsInnerBlocks'  => true,
			'allowedChildBlocks' => [ 'core/column' ],
		],
		'core/column' => [
			'description'       => 'A single column inside core/columns. Put this column\'s content (core/heading, core/paragraph, core/list, core/image, core/buttons, or a nested core/group) in its innerBlocks.',
			'attributes'        => [],
			'allowsInnerBlocks' => true,
			'requiresParent'    => [ 'core/columns' ],
		],
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit --filter test_columns_pair_is_allowlisted`
Expected: PASS.

- [ ] **Step 5: Write the failing Validator tests**

Add to `tests/phpunit/BlockTree/ValidatorTest.php` a schema helper and three tests (inside the class, after `test_full_tree_validate_flags_top_level_orphan_child`):

```php
	private function columnsSchema(): array {
		return [
			'core/columns' => [
				'description'        => 'Columns',
				'attributes'         => [],
				'allowsInnerBlocks'  => true,
				'allowedChildBlocks' => [ 'core/column' ],
			],
			'core/column' => [
				'description'       => 'Column',
				'attributes'        => [],
				'allowsInnerBlocks' => true,
				'requiresParent'    => [ 'core/columns' ],
			],
			'core/paragraph' => [
				'description' => 'Paragraph',
				'attributes'  => [ 'content' => [ 'type' => 'string' ] ],
				'allowsInnerBlocks' => false,
			],
		];
	}

	public function test_rejects_top_level_column(): void {
		$errors = ( new Validator( $this->columnsSchema() ) )->validateNode(
			[ 'name' => 'core/column', 'attributes' => [], 'innerBlocks' => [] ]
		);
		$this->assertNotEmpty( $errors, 'A bare top-level core/column must be rejected.' );
		$joined = implode( ' ', $errors );
		$this->assertStringContainsString( 'core/column', $joined );
		$this->assertStringContainsString( 'core/columns', $joined );
	}

	public function test_rejects_empty_columns_container(): void {
		$errors = ( new Validator( $this->columnsSchema() ) )->validateNode(
			[ 'name' => 'core/columns', 'attributes' => [], 'innerBlocks' => [] ]
		);
		$this->assertNotEmpty( $errors, 'An empty core/columns must be rejected.' );
		$this->assertStringContainsString( 'core/column', implode( ' ', $errors ) );
	}

	public function test_accepts_columns_with_columns_holding_content(): void {
		$errors = ( new Validator( $this->columnsSchema() ) )->validateNode( [
			'name'        => 'core/columns',
			'attributes'  => [],
			'innerBlocks' => [
				[
					'name'        => 'core/column',
					'attributes'  => [],
					'innerBlocks' => [
						[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Left' ], 'innerBlocks' => [] ],
					],
				],
				[
					'name'        => 'core/column',
					'attributes'  => [],
					'innerBlocks' => [
						[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Right' ], 'innerBlocks' => [] ],
					],
				],
			],
		] );
		$this->assertSame( [], $errors, 'A populated columns/column/content tree must validate clean.' );
	}
```

- [ ] **Step 6: Run the Validator tests to verify they pass**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit --filter 'test_rejects_top_level_column|test_rejects_empty_columns_container|test_accepts_columns_with_columns_holding_content'`
Expected: PASS (3 tests). These pass with no Validator change — they confirm the existing rules cover the new pair.

- [ ] **Step 7: Run the full SchemaBuilder + Validator suites and lint**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit tests/phpunit/Anthropic/SchemaBuilderTest.php tests/phpunit/BlockTree/ValidatorTest.php`
Expected: PASS (all tests, no failures).
Run: `composer lint`
Expected: no errors, no warnings.

- [ ] **Step 8: Commit**

```bash
git add src/Anthropic/SchemaBuilder.php tests/phpunit/Anthropic/SchemaBuilderTest.php tests/phpunit/BlockTree/ValidatorTest.php
git commit -m "feat(ai): allow core/columns + core/column for AI composition

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Add composition guidance to the system prompt

**Files:**
- Modify: `src/Chat/PromptBuilder.php` (`systemPrompt()`; insert a guidance line after the media-text paragraph at line 43, before the "Available blocks" line at line 45)
- Test: `tests/phpunit/Chat/PromptBuilderTest.php`

**Interfaces:**
- Consumes: the `core/columns`/`core/column` names produced by Task 1 (referenced as literal strings in the prompt; no code dependency).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing PromptBuilder test**

Add to `tests/phpunit/Chat/PromptBuilderTest.php` (inside the class):

```php
	public function test_system_prompt_explains_composition_from_primitives(): void {
		$pb     = new PromptBuilder( [ 'core/group' => [ 'description' => 'A section container.' ] ] );
		$prompt = $pb->systemPrompt();

		// Bespoke-first: prefer a purpose-built pediment block when one fits.
		$this->assertStringContainsStringIgnoringCase( 'purpose-built pediment block', $prompt );
		// Fallback toolkit: compose from columns when the library has no block.
		$this->assertStringContainsString( 'core/columns', $prompt );
		$this->assertStringContainsString( 'core/column', $prompt );
		$this->assertStringContainsString( 'pediment/section-head', $prompt );
		// Let the theme style it — no custom color/spacing on composed blocks.
		$this->assertStringContainsStringIgnoringCase( 'rely on the theme', $prompt );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit --filter test_system_prompt_explains_composition_from_primitives`
Expected: FAIL — `Failed asserting that '<prompt>' contains "purpose-built pediment block"`.

- [ ] **Step 3: Add the guidance line**

In `src/Chat/PromptBuilder.php`, after the media-text paragraph (the `$lines[] = 'Text beside an image: …';` block ending at line 43) and its trailing `$lines[] = '';` (line 44), insert:

```php
		$lines[] = 'Composing new section types: always prefer a purpose-built pediment block when one fits the content (hero, cta, stat-grid, testimonial-grid, faq, media-text, prose, section-head). ONLY when the library has no block for a section type the page genuinely needs — a pricing table, a feature/benefit grid, a logo wall, a comparison, a process or timeline — compose it from primitives instead of forcing an ill-fitting block or emitting a flat stack of paragraphs. Build the section inside its band core/group; for multi-column layouts emit one core/columns (set "align":"wide") with a core/column per column, and put each column\'s core/heading / core/paragraph / core/list / core/image / core/buttons in that column\'s innerBlocks. Use pediment/section-head for the section heading. Keep nesting shallow. Do NOT set custom colors, font sizes or spacing on composed blocks — rely on the theme\'s own styles so the section stays on-brand. Stats stay the exception: never put pediment/stat in core/columns — use pediment/stat-grid.';
		$lines[] = '';
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit --filter test_system_prompt_explains_composition_from_primitives`
Expected: PASS.

- [ ] **Step 5: Run the full PromptBuilder suite and lint**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit tests/phpunit/Chat/PromptBuilderTest.php`
Expected: PASS (existing tests unaffected — the new line does not break `test_system_prompt_prescribes_theme_respecting_layout`, which asserts `"type":"default"` is absent; the new line contains no such string).
Run: `composer lint`
Expected: no errors, no warnings.

- [ ] **Step 6: Commit**

```bash
git add src/Chat/PromptBuilder.php tests/phpunit/Chat/PromptBuilderTest.php
git commit -m "feat(ai): guide the model to compose section types from primitives

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the complete PHPUnit suite**

Run: `npx wp-env run tests-wordpress --env-cwd=wp-content/plugins/pediment-ai ./vendor/bin/phpunit`
Expected: PASS — no failures, no errors, no regressions.

- [ ] **Step 2: Run lint gates**

Run: `composer lint`
Expected: zero errors, zero warnings.

- [ ] **Step 3: Confirm the schema cache will refresh on deploy**

No action in code — `SchemaBuilder::invalidate()` already runs on block-type registration and the transient TTL is one hour. Note in the PR description that the `pediment_ai_schema` transient must be allowed to expire (or be cleared) for the new blocks to appear if testing against a warm cache.

---

## Notes for the implementer

- Manual sanity check (run in the main checkout, not a worktree, since wp-env here is for automated runs): after deploy, ask the AI for a section type not in the library (e.g. "add a three-column pricing table"). Confirm it produces a valid, editable `core/columns` layout inside a band rather than failing or stacking paragraphs. This is a human check — not part of the automated gates above.
