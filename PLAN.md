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

### Done 2026-11-18 (second session, Windows 11 machine: PHP 8.4.26, Node 24.16, Chrome 157, FFmpeg via winget)
- [x] Project now also runs on **Windows**. Fixes: `SandboxProcess` keeps the non-secret Windows system variables
      (Node aborted with exit 134 without `SystemRoot`; Chrome refused remote debugging without the real
      `USERPROFILE`); engine scripts import modules through `pathToFileURL` (Windows absolute paths are not valid
      ESM specifiers). Linux behaviour is unchanged.
- [x] **Full render end to end** verified by `tests/Feature/FullRenderPipelineTest.php` on the sync queue with a
      synthetic tone voice: all 8 chain stages succeed, final MP4 duration matches, technical QC passes, thumbnail +
      final SRT recorded, a second render reuses every scene segment. (The old Linux E2E user/project lived only in
      the Linux machine's database; the new database never had them, so there is nothing to delete here.)
- [x] **Automated tests** (spec §19): 39 tests in `tests/Feature` (fixtures in `tests/Concerns`). Mocked AI via
      `Http::fake` (Kimi adapter). Covers permissions, project creation, credential encryption, good/bad key tests,
      rotation, model selection + unsupported capability, usage/cost + idempotency + budgets, SRT parsing,
      storyboard validation, locked narration (service + HTTP), reorder/boundary rules, editor state, player CSP,
      undo/redo, queue dispatching (`Bus::fake`), generator failure handling, invalid-code handling, downloads,
      export QC (real FFmpeg), cleanup. Two real-toolchain tests in group `render`.
      Example tests deleted. `php artisan test --exclude-group=render` ≈ 10 s; full ≈ 2.5 min.
- [x] Housekeeping: `motionforge:cleanup` (daily 03:30, `--dry-run`), `MOTIONFORGE_RENDER_CACHE_DAYS` /
      `retention.render_cache_days` setting; fixed a leak (validator temp dirs were never deleted);
      Lucide trimmed via `scripts/build-icons.mjs` (runs before build/dev): app JS 555 kB → 111 kB.
- [x] Docs: `docs/deployment.md` (Ubuntu + Windows), `docs/architecture.md` rewritten, README updated.

### Done 2026-11-20 — template (free) mode characters act out the narration
- [x] `ActionLexicon` (~37 actions: phone, call, type, read, write, eat, drink, cry, laugh, shout, talk, think, point,
      shock, hug, give, money, door, knock, sleep, wake, sit, walk in/away, run, pace, wave, nod, …) turns narration or
      the scene sheet's Action field into ordered actions + the spoken trigger word. Both template planners use it.
- [x] `director.js` beat system: each action starts ~0.35 s before its word is spoken (CAPS timings), eased hand-offs,
      props (desk/laptop/bowl/notebook, chair, bed + blanket, door, phone glow), other characters react (listen, receive,
      hug back, talk when the narration quotes them). With no actions the character still acts out the emotion.
      `drawSuit` accepts `legs`, so adults walk and sit too. Existing scene code keeps working (phrase fallback).
- Known: `QueueAndJobsTest` has 2 pre-existing failures (tests call `GenerateSceneCodeJob::handle()` without the
  `ProductionWorkflow` argument).

### Remaining work (in order)
1. Scene editor **in a real browser** (server side is tested): player iframe messaging, WaveSurfer waveform,
   Sortable drag (reverts on invalid order), timing ± cue buttons, code edit → ValidateSceneCodeJob.
2. AI path end to end with a real key, once the user adds one: storyboard JSON schema accepted by each provider
   (OpenAI strict mode needs all-required + additionalProperties:false, as the schemas already are), scene code
   quality. Add optional real-provider tests gated by env vars (e.g. `MF_TEST_ANTHROPIC_KEY`); never report mocked
   runs as real. Claude can be mocked through the `ClaudeProvider` client-factory closure.
3. Native video (Mode B) with a Gemini key: submit/poll/download/approve/assemble; check the inline-base64 shape.
4. Hybrid (Mode C): per-scene source toggle + ASS subtitles burned over mixed Canvas/AI clips (needs a Gemini key
   to produce real clips).
5. AI image generation is NOT implemented (capability is listed, no adapter). Add one only after verifying its API,
   or keep it documented as not available.
6. Final report to the user per spec §21: files, decisions, tests run with real output, unresolved issues.

## Known limitations (tell the user; don't hide)
- The engine is a compatible re-implementation, not the original Kikitoons engine.
- Single-file voice alignment uses SRT timings + silence detection (not word-level ASR); word timings inside a cue
  are interpolated by word length.
- The template director's direction is rule-based and simple. It can't write real end-card advice, so it shows a
  placeholder that must be edited.
- Render speed ≈ 150–200 ms/frame per worker (CPU canvas): about 7–10 min for a 2:30 video with 2 workers.
- Python tools from the skill (snap.py, render.py, …) are replaced by Node equivalents (no pip on this machine).
- Local preview login in README (`admin@motionforge.local`) exists only in the Linux machine's database. On a fresh
  database, register in the browser: the first account becomes admin.
