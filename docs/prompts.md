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
