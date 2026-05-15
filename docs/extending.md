# Extending wp-starter-ai from a child theme

The plugin exposes filters so a child theme can register blocks under its own namespace and customise the system prompt without forking the plugin.

## Block discovery namespaces

By default the plugin discovers blocks under the `starter/` and `client/` namespaces. To allow an additional namespace, hook `starter_ai_block_namespaces`:

```php
add_filter( 'starter_ai_block_namespaces', function ( $namespaces ) {
    $namespaces[] = 'acme';
    return $namespaces;
} );
```

Blocks under `acme/*` registered with a non-empty `description` will appear in the AI's block schema. The schema is cached in a transient, so if you add the filter after the plugin has already built the schema, call `\StarterAi\Anthropic\SchemaBuilder::invalidate()` (e.g. in a one-shot WP-CLI command or in test setup) to force re-discovery.

## System prompt

Wrap the prompt to inject brand voice, domain examples, or extra constraints. The filter runs on every chat turn — there is no caching to invalidate.

```php
add_filter( 'starter_ai_system_prompt', function ( $prompt, $schema ) {
    return $prompt . "\n\nBrand voice: confident, concise, no marketing fluff.";
}, 10, 2 );
```

The filter receives the composed prompt string and the block schema array (so you can inspect what blocks are available before appending guidance). Return a string.

## Provider

The AI provider object can be swapped wholesale — useful for testing or pointing at a self-hosted compatible endpoint:

```php
add_filter( 'starter_ai_provider', function ( $provider ) {
    return new MyCustomProvider();
} );
```

The default provider is `\StarterAi\Anthropic\Client`. The built-in `STARTER_AI_MOCK=true` constant (or the mock-mode admin setting) already hooks this filter to swap in `\StarterAi\Mock\MockProvider`; don't add your own hook while that's active.

## Model selection

Use `starter_ai_model_compose` to change the model used for page composition:

```php
add_filter( 'starter_ai_model_compose', function ( $model ) {
    return 'claude-opus-4-5';
} );
```

The filters `starter_ai_model_edit` and `starter_ai_model_refine` follow the same signature and are declared for future use — they will gate edit and refine flows once those endpoints are wired up. **They are not invoked today; a hook on either will have no effect until then.** `starter_ai_model_compose` is already hooked internally to respect the model setting configured on the admin Settings page, so child-theme overrides take precedence only when the admin field is left blank.

## Agentic budget per chat turn

One chat turn is an iterative tool-use loop: each round-trip lets the model emit a batch of block mutations, sees the results, and continues until it has nothing left to do. Two limits bound that loop, both filterable:

```php
// Max model output tokens per round-trip. Too low truncates a batched set of
// block mutations mid-turn and wastes iterations. Default 16384. Keep within
// the configured model's output ceiling (e.g. Sonnet 4.6 supports 64K).
add_filter( 'starter_ai_max_tokens', fn() => 32768 );

// Max tool-use round-trips before the turn fails with `iteration_limit`.
// Raise it for very large multi-section page builds. Default 20.
add_filter( 'starter_ai_max_iterations', fn() => 30 );
```

If a "create a full landing page"-class request reports *Reached maximum tool-use iterations*, the turn ran out of one of these budgets before finishing — raise `starter_ai_max_tokens` first (so each round-trip carries a larger batch), then `starter_ai_max_iterations` for headroom.

## Turn execution mode (streaming vs inline)

A chat turn runs out-of-band via a non-blocking loopback request so the
browser can poll and see streaming. Controls:

```php
// Force synchronous execution (no streaming) — for hosts where loopback
// is blocked. Default is 'auto' (loopback).
add_filter( 'starter_ai_dispatch_mode', fn() => 'inline' );

// Override the loopback origin. Needed in containers (e.g. wp-env) where
// the public host:port is not reachable from inside the container.
add_filter( 'starter_ai_loopback_url', fn() => 'http://127.0.0.1' );
```

Or define `STARTER_AI_LOOPBACK_URL` (constant) for the same effect without a
filter — preferred for wp-env via `.wp-env.override.json`.
