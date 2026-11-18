# MotionForge — build plan & status

Read this first when resuming. The original brief is the long "MotionForge — AI-Powered Animation & Video
Production Studio" spec (22 sections) plus the `kikitoons-video-maker-v1` skill
(`~/.claude/skills/synced/*/kikitoons-video-maker-v1/SKILL.md`, also `~/Downloads/kikitoons-video-maker-v1.skill`).

## User instructions (keep following these)
- Use **SQLite**, not PostgreSQL (`database/database.sqlite`). Queue: `database` driver (Redis isn't installed).
- **Leave the API key fields as plain inputs.** The user enters their own keys. Never ask for or hard-code keys.
- Focus on building the app. Don't make story content; any test data must be synthetic and cleaned up afterwards.
- Local preview login is in `README.md` (`admin@motionforge.local`).
- The user runs `composer dev` (serve + queue:listen + pail + vite). After any `.env` change they must restart it,
  because running processes keep their old environment (this caused a MissingAppKeyException earlier).

## Environment facts (verified 2026-11-18)
- PHP 8.5, Laravel 13.43, Livewire 4.4, Tailwind 4 (Vite), Node 22, Chrome 148 at /usr/bin/google-chrome.
- FFmpeg/FFprobe 7.0.2 static at `/home/meun/Public/soundex/bin/` (set in `.env` as MOTIONFORGE_FFMPEG/FFPROBE).
- No pip/numpy/scipy, no Redis, no sudo. All engine tooling is Node (playwright-core, acorn) plus FFmpeg.
- Tailwind v4: a class used by other classes through `@apply` must be declared with `@utility` (see `btn` in app.css).
- Chrome crashes (SIGTRAP) if TMPDIR is a long path, so `SandboxProcess` keeps TMPDIR = sys temp dir.

## Verified API facts (official docs, fetched 2026-11-18)
- OpenAI Sora / Videos API **shut down 2026-09-24**, so OpenAI is text/code only (Responses API, `text.format` json_schema).
- Gemini: Interactions API `POST /v1beta/interactions` (`response_format` for JSON); video = `gemini-omni-1.2-flash`
  (3–15 s, 9:16 or 16:9, `background:true`, poll `GET /interactions/{id}`, cancel `POST /interactions/{id}/cancel`,
  $12/1M output tokens, 5,792 tok/s at 720p). Veo 3.1 preview was shut down 2026-10-22.
- Claude: official PHP SDK `anthropic-ai/sdk` 1.10, `outputConfig: ['format' => json_schema]`.
  Server-side model fallbacks deliberately NOT enabled (the spec says fallback only when the user configures it).
- Kimi: `https://api.moonshot.ai/v1/chat/completions`, `response_format` json_schema supported; models kimi-k3.1-pro, kimi-k3.
- Unknown prices are stored as `null` (UI shows "n/a"); never guessed. Registry/prices: `config/motionforge.php`.

## Architecture (where things are)
- `engine/kikitoons-compat/` — clean-room Kikitoons-compatible engine (`core.js`, `bg.js`, `engine.js`) implementing
  the skill's documented API, plus `director.js` (`mfStage`, the template director runtime) and `API.md` (given to AI).
  The original engine is NOT available (it lives in the claude.ai Project "video"). Set `KIKITOONS_ENGINE_PATH` to use it.
- `engine/renderer/render.mjs` — sandboxed renderer (headless Chrome, CSP, no network, per-frame/total timeouts),
  modes video/still/probe, JSON-lines progress → FFmpeg.
- `engine/tools/validate.mjs` — AST allowlist validator for AI scene code; `engine-api.json` comes from `build-api.mjs`
  (re-run `node engine/tools/build-api.mjs` after changing engine globals).
- `engine/audio/sfx.mjs` — synthesised cinematic SFX/music/ambience bed; FFmpeg ducks it under the voice and applies
  2-pass loudnorm to −14 LUFS (`AudioMixer`).
- `app/Services/AI/*` — AIManager (calls, idempotency, usage), ProviderRouter, ModelRegistry, BudgetGuard,
  CostEstimator, UsageTracker, AIResponseValidator, and the 4 providers.
- `app/Services/Production/*` — PackageService/Validator (hash lock), SrtParser, NarrationMatcher, StoryboardBuilder
  (scene = SRT cue range; narration copied verbatim), AI + Template planners, AI code generator + TemplateSceneDirector,
  StoryboardService (versions, narration guard, boundary moves), ProductionWorkflow (gates + dispatch), CaptionWriter.
- `app/Services/Rendering/*` — KikitoonsEngine adapter, StoryAssembler, SandboxProcess (scrubbed env),
  AudioMixer, VideoAssembler, QualityControl, ThumbnailService.
- Jobs in `app/Jobs` (all 12 from the spec + GenerateVideoClipJob/PollVideoClipJob/GenerateCaptionJob).
  Full render = chain: AlignVoiceover → batch(RenderSceneJob per changed scene) → MixAudio → RenderFullVideo →
  GenerateCaption → GenerateThumbnail → RunQualityControl → FinalizeProduction.
- UI: Blade components in `resources/views/components`, project tabs in `resources/views/projects/tabs`,
  scene editor `resources/views/editor/show.blade.php` (Alpine; preview in a sandboxed iframe from
  `SceneEditorController@player`), Livewire `App\Livewire\RenderMonitor`.

## Status

### Done and verified
- [x] Schema (all spec tables), seeders (roles/permissions, provider + model registry, characters, backgrounds)
- [x] Auth (first account = admin), policies, rate limits, CSRF, protected media/download routes
- [x] AI providers settings + credentials (encrypted, masked, real connection test, rotate, limits, revoke)
- [x] Model registry/pricing page, routing, budgets, idempotent AI calls, usage tracking
- [x] Project wizard (5 steps) → package upload → queued validation → approval with warning acknowledgement + hash lock
- [x] Template storyboard + scene code + sandbox validation/probe + previews (verified on a 35-scene real package and
      a 4-scene synthetic package through HTTP + the user's queue worker)
- [x] Fixed: plant silhouette blacked out frames; caption pages; brow angles; TMPDIR crash; trait `$tries` conflict
- [x] All pages return 200 when logged in (dashboard, projects, wizard, tabs, editor, studio, libraries, queue,
      videos, usage, settings)

### In progress (check first when resuming)
- [ ] **Full render end to end through HTTP** for the synthetic E2E project (user `e2e@motionforge.test`, project
      "E2E Pipeline Test"). Check: `php artisan tinker` → `App\Models\RenderJob::latest()->first()` (status/stage/error).
      Last observed: render job "running", stage stuck on "Aligning voice and subtitles" with 0/683 frames for about
      2 minutes. Find out whether the scene batch inside the chain was dispatched (`job_batches`, `jobs` tables,
      `storage/logs/laravel.log`) and whether `queue:listen --tries=1` is processing it.
      If it failed, fix that stage, re-run, then look at `final.mp4`, `thumbnail.png` and the QC results.
      **Afterwards delete the E2E user and project** (and its storage dir `storage/app/private/projects/<id>`).

### Remaining work (in order)
1. Finish/verify the full render (above). Then render from the scene editor ("Render scene") and verify a cached reuse.
2. Scene editor in a real browser: player iframe messaging, WaveSurfer waveform, Sortable reorder (reverts on
   invalid order), undo/redo version switching, timing ± cue, code edit → ValidateSceneCodeJob.
3. Automated tests (spec §19), with mocked AI (`Http::fake`; Claude via the `ClaudeProvider` client factory closure):
   project creation, credential encryption, connection test (good/bad key), model selection + unsupported capability,
   storyboard validation, locked-narration protection, scene generation, queue dispatching (`Bus::fake`),
   rendering failure handling, usage/cost tracking, unauthorized downloads, permissions, final export validation.
   Optional real-provider test gated by env vars (e.g. `MF_TEST_ANTHROPIC_KEY`); never report mocked runs as real.
   Plus a real short Kikitoons render integration test (needs Chrome + FFmpeg; skip if unavailable).
   Delete `tests/Feature/ExampleTest.php` / `tests/Unit/ExampleTest.php` (they reference removed routes).
4. AI path end to end with a real key, once the user adds one: storyboard JSON schema accepted by each provider
   (OpenAI strict mode needs all-required + additionalProperties:false, as the schemas already are), scene code quality.
5. Native video (Mode B) with a Gemini key: submit/poll/download/approve/assemble; check the inline-base64 response shape.
6. Hybrid (Mode C): per-scene source toggle + ASS subtitles burned over mixed Canvas/AI clips.
7. AI image generation is NOT implemented (capability is listed, no adapter). Add one (e.g. Gemini image model)
   only after verifying its API, or keep it documented as not available.
8. Housekeeping: `motionforge:cleanup` scheduled command (old `storage/app/tmp/render/*`, stale previews);
   trim the Lucide bundle (import only the icons used; the bundle is currently >500 kB); data-retention setting.
9. Docs: Ubuntu deployment guide (php-fpm/nginx, Supervisor for `queue:work` ×2, Chrome + FFmpeg + fonts,
   optional Redis/Horizon, optional Docker sandbox for the renderer), update `docs/architecture.md`.
10. Final report to the user per spec §21: files, decisions, tests run with real output, unresolved issues.

## Known limitations (tell the user; don't hide)
- The engine is a compatible re-implementation, not the original Kikitoons engine.
- Single-file voice alignment uses SRT timings + silence detection (not word-level ASR); word timings inside a cue
  are interpolated by word length.
- The template director's direction is rule-based and simple. It can't write real end-card advice, so it shows a
  placeholder that must be edited.
- Render speed ≈ 150–200 ms/frame per worker (CPU canvas): about 7–10 min for a 2:30 video with 2 workers.
- Python tools from the skill (snap.py, render.py, …) are replaced by Node equivalents (no pip on this machine).
