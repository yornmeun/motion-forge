# MotionForge architecture

MotionForge keeps web requests, AI calls and rendering separate. Nothing heavy runs inside an HTTP
request: controllers validate, authorise and dispatch queue work, and the UI polls real job state.

```text
Blade / Livewire / Alpine  ──►  controllers + policies  ──►  ProductionWorkflow (gates)
                                                               │
                         ┌─────────────────────────────────────┴───────────────┐
                         ▼                                                     ▼
              AI layer (app/Services/AI)                         database queue (jobs, job_batches)
   AIManager · ProviderRouter · ModelRegistry ·                          │
   BudgetGuard · CostEstimator · UsageTracker                            ▼
   providers: Claude (SDK) · Gemini · OpenAI · Kimi        render pipeline jobs (app/Jobs)
                                                                         │
                                                                         ▼
                                              sandboxed Node renderer (engine/renderer/render.mjs)
                                              headless Chrome (CSP, no network) → FFmpeg
```

## Production gate

Kikitoons and hybrid projects are driven by an approved **production package** (locked story,
narration, SRT, voiceover). `PackageValidator` reports problems and never repairs content. Approval
(`PackageService::approve`) requires a validated package, explicit acknowledgement of warnings, and
records SHA-256 hashes of every input. `PackageService::assertLocked` re-checks those hashes before
every storyboard, scene-generation and render job; any change blocks production until a new package
version is approved.

Scenes are SRT cue ranges. Narration is copied verbatim from the SRT and guarded on every edit
(`StoryboardService::assertNarrationUnchanged`); boundary moves are only allowed on cue boundaries and
reordering may not change narration order.

## AI layer

- `ModelRegistry` — only registry models (seeded from `config/motionforge.php`) are routable.
  Models discovered through a provider's list endpoint are stored without capabilities.
- `ProviderRouter` — manual (credential + model, validated against the capability) or automatic
  selection; fallback only when the project enables it.
- `AIManager::call` — capability check, budget check (`BudgetGuard`), an `ai_requests` row for every
  call (tokens, duration, estimated cost, sanitised errors), idempotency keys so a retried job never
  pays twice, usage records (`UsageTracker`). Keys are decrypted only at call time and scrubbed from
  every message (`AIProviderException::scrub`).
- Credentials use Laravel's `encrypted` cast, are hidden from serialisation and shown only as the last
  four characters. Provider base URLs are fixed in config (no user-supplied URLs).

## Render pipeline

`ProductionWorkflow::startRender` dispatches one chain:

```text
AlignVoiceover → batch(RenderScene per changed scene) → MixAudio → RenderFullVideo
  → GenerateCaption → GenerateThumbnail → RunQualityControl → FinalizeProduction
```

- Scene segments are cached by a content hash (`KikitoonsEngine::segmentHash`): engine files, timing,
  scene code, plan, neighbours with transitions, SRT hash. Unchanged scenes are reused.
- `SandboxProcess` starts every child process (Node, Chrome, FFmpeg) with a scrubbed environment so
  no application secret reaches code that runs AI-written scenes. On Windows it keeps only the
  non-secret system variables those programs need to start (`SystemRoot`, `TEMP`, `USERPROFILE`, …).
- AI scene code is checked statically (`engine/tools/validate.mjs`, AST allowlist built from the
  engine API by `build-api.mjs`), then probed in headless Chrome before it can become current.
- `QualityControl` checks the real output with FFprobe/FFmpeg (resolution, aspect, fps, codecs,
  duration, loudness, true peak, black and frozen frames, subtitle order). Creative review is a human
  checklist and is never auto-passed.

## Engine

`engine/kikitoons-compat/` is a clean-room implementation of the documented Kikitoons API plus the
`mfStage` template director runtime. The original engine is used instead when `KIKITOONS_ENGINE_PATH`
points to a folder containing `core.js`, `bg.js` and `engine.js`.

## Housekeeping

`php artisan motionforge:cleanup` (scheduled daily at 03:30) removes temporary render directories
older than 6 hours, previews of non-current scene versions and scene segments that no longer match
their scene, after `MOTIONFORGE_RENDER_CACHE_DAYS` (default 30; overridable with the
`retention.render_cache_days` system setting). Final videos, thumbnails, captions, SRTs and package
inputs are never touched. Use `--dry-run` to preview.

## Data

SQLite by default (`database/database.sqlite`), database queue and cache. Tables: providers,
credentials, models, projects (+ members), production packages, project files, scenes, scene
versions, scene assets, characters, backgrounds, audio assets, subtitle tracks, generation jobs,
render jobs, render artifacts, quality checks, thumbnails, captions, AI requests, usage records,
activity logs, roles, permissions, system settings. Estimated and confirmed costs are kept separate.

## Tests

`php artisan test` runs the feature suite with mocked AI (`Http::fake`). Two integration tests in
the `render` group exercise the real toolchain (Chrome + FFmpeg) and are skipped automatically when
either is missing: `KikitoonsRenderIntegrationTest` (validate → probe → still → segment → cache) and
`FullRenderPipelineTest` (the whole chain above on the sync queue with a synthetic tone voice).
Run without them: `php artisan test --exclude-group=render`.
