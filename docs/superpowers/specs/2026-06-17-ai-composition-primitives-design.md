# AI Composition Primitives — Design

**Date:** 2026-06-17
**Status:** Approved (pending spec review)

## Problem

The AI composer feels "too limited": the block allowlist lacks whole **section
types** the user wants (pricing tables, feature grids, logo walls, comparison
layouts, timelines). When no pediment block fits, the AI has nothing reasonable
to reach for and either forces an ill-fitting bespoke block or produces a flat
stack of paragraphs.

The tempting fix — a freeform "custom HTML" block where the AI writes arbitrary
markup — was rejected. It breaks the core concept of the feature:

- **Brand drift** — the AI stops composing the design system and improvises one.
- **Safety** — raw HTML in attributes replaces typed, validated attributes with
  a `wp_kses` trust boundary (XSS / markup-breakage surface).
- **Non-editable output** — a hand-built HTML blob is not a real Gutenberg block,
  contradicting the "fully editable in the Site Editor" principle.
- **Hides the signal** — a catch-all absorbs the very data that tells us which
  blocks the library is missing.

## Approach (chosen: composition from primitives)

Give the AI more *legitimate* range instead of an escape hatch. Expose the
structural core blocks — `core/group` (already present), `core/columns`, and
`core/column` — as deliberate composition tools, and teach the AI in the system
prompt to compose missing section types from these plus the existing primitives
(`core/heading`, `core/paragraph`, `core/list`, `core/image`, `core/buttons`).
The theme's `theme.json` tokens and block styles do the brand work.

Every output stays a real, registered, server-validated, fully-editable block.
The bar is "on-brand-enough" (user-confirmed), not "indistinguishable from a
purpose-built block."

### Rejected alternatives

- **`pediment/section` god-block** — a single flexible registered block with fixed
  slots. Concept-safe but real design+build work and prone to becoming
  unmaintainable. Not needed when "on-brand-enough" is the bar.
- **Freeform custom HTML block** — rejected for the reasons above.

## Design

### 1. Allowlist: add `core/columns` and `core/column`

In `src/Anthropic/SchemaBuilder.php`, extend `CORE_ALLOWLIST`. Follow the exact
precedent of the existing `core/list` / `core/list-item` and
`core/buttons` / `core/button` pairs:

- **`core/columns`** — `allowsInnerBlocks: true`, `allowedChildBlocks: ['core/column']`.
  The `allowedChildBlocks` entry makes the validator (a) reject an empty columns
  block via the empty-container guard, and (b) restrict its children to columns
  only. The prompt auto-emits a `[contains: core/column]` hint.
- **`core/column`** — `allowsInnerBlocks: true`, `requiresParent: ['core/columns']`,
  no `allowedChildBlocks` (a column holds arbitrary section content, exactly like
  `core/group`). `requiresParent` makes the validator reject a stray top-level
  column and steer the model to nest columns inside a single `core/columns`
  insert call. The prompt auto-emits a `[child of: core/columns]` hint.

`core/group` is already in the allowlist with no `allowedChildBlocks`, so it
already accepts arbitrary children — no change needed there.

No changes to `Validator.php` are required: the existing rules
(unknown-block, empty-container, requiresParent-at-depth-0, allowedChildBlocks
membership) cover the new blocks once they are declared in the schema.

### 2. Composition guidance in the system prompt

In `src/Chat/PromptBuilder.php::systemPrompt()`, add a guidance paragraph
(consistent with the existing per-pattern guidance blocks) that establishes:

- **Bespoke-first rule.** If a pediment block fits (hero, cta, stat-grid,
  testimonial-grid, faq, media-text, prose, section-head), use it. Composition is
  the **fallback** only for section types the library does not provide.
- **How to compose cleanly.** Build the section inside its band `core/group`
  (the existing band rules still apply); use `core/columns` for multi-column
  layouts with one `core/column` per column, each holding heading/paragraph/
  list/image/buttons; prefer `pediment/section-head` for the section heading;
  keep nesting shallow.
- **Let the theme style it.** Do not set custom colors or spacing on composed
  blocks — rely on inherited `theme.json` tokens so output stays on-brand. Use
  `align: "wide"` on the columns for grid-style rows, consistent with the
  existing wide-content guidance.
- **Stats guard interaction.** The existing prompt already forbids wrapping
  `pediment/stat` in `core/columns`; that rule stands — stats use
  `pediment/stat-grid`. The new guidance must not contradict it.

### 3. Out of scope (YAGNI)

- No freeform HTML block.
- No `pediment/section` block.
- No automated "promote recurring composition → bespoke block" tooling. The
  feedback loop is manual: when the user notices the AI repeatedly composing the
  same section, that is the backlog signal to build a first-class block later.

## Risk & rollback

Additive and low-risk. Worst case the AI produces a slightly generic (but
on-brand and editable) section instead of being stuck. The schema is cached in
the `pediment_ai_schema` transient for an hour; `SchemaBuilder::invalidate()`
clears it after deploy. Rollback is reverting the two files.

## Testing

- **Unit (PHP, parent-repo wp-env per project convention):** assert the built
  schema contains `core/columns` (with `allowedChildBlocks: ['core/column']`) and
  `core/column` (with `requiresParent: ['core/columns']`).
- **Validator:** a top-level `core/column` is rejected; an empty `core/columns`
  is rejected; a `core/columns` containing `core/column` children each holding
  primitives validates clean.
- **Prompt:** the system prompt lists `core/columns` with a `[contains: …]` hint
  and `core/column` with a `[child of: …]` hint, and includes the composition
  guidance paragraph.
- **Manual sanity (in the main checkout, not a worktree):** ask the AI for a
  section type not in the library (e.g. a three-column pricing table) and confirm
  it builds a valid, editable `core/columns` layout rather than failing.
