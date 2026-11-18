# MotionForge architecture

MotionForge keeps web requests, AI calls, and rendering separate.

```text
Blade / controllers → SQLite + database queue → isolated render worker
                           ↓
                    AI provider adapters
```

## Production gate

`kikitoons` projects cannot dispatch scene or render work until a production package is explicitly approved. The gate validates a locked story, narration, SRT, readable voiceover, matching timing, and character references. The narration, SRT source text, and voiceover are immutable after approval; any mismatch is reported instead of repaired.

## Providers

`AIManager` routes by provider slug, while each provider exposes only its supported capability. The model registry is configuration/data-driven, so text, structured output, image, and native-video operations are never interchangeable. Credentials are Laravel-encrypted casts and are not serialized to the browser.

## Renderer boundary

The actual Kikitoons engine is not bundled in the attached skill. A render adapter must receive a verified engine directory through `KIKITOONS_ENGINE_PATH`. Its worker must run without application credentials, network access, or a writable application filesystem. Missing renderer dependencies are a blocking environment state, never a completed render.

## Environment prerequisites

SQLite is the default database and Laravel's database queue is the default local queue. Redis can be enabled later for higher-throughput workers. Kikitoons rendering additionally needs FFmpeg/FFprobe, Playwright plus Chrome, Python with Pillow/NumPy/SciPy, Poppins Bold, and the real engine sources listed in the skill.

## Data roadmap

The initial migration creates `ai_providers`, `ai_credentials`, `ai_models`, `projects`, `production_packages`, `scenes`, and `ai_requests`. It is SQLite-compatible. Subsequent, testable migrations extend this with `project_members`, `project_files`, `scene_versions`, `scene_assets`, `characters`, `character_assets`, `background_assets`, `audio_assets`, `subtitle_tracks`, `generation_jobs`, `render_jobs`, `render_artifacts`, `quality_checks`, `thumbnails`, `captions`, `ai_usage_records`, `project_activity_logs`, `roles`, `permissions`, and `system_settings`. Costs remain separated into estimated and confirmed amounts.
