<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rendering toolchain
    |--------------------------------------------------------------------------
    | engine_path: when set, MotionForge loads the original Kikitoons engine (core.js, bg.js,
    | engine.js) from this directory. Otherwise the bundled Kikitoons-compatible engine is used.
    */
    'engine_path' => env('KIKITOONS_ENGINE_PATH'),
    'compat_engine_path' => base_path('engine/kikitoons-compat'),
    'renderer' => base_path('engine/renderer/render.mjs'),
    'validator' => base_path('engine/tools/validate.mjs'),
    'sfx' => base_path('engine/audio/sfx.mjs'),
    'font' => base_path('engine/fonts/Poppins-Bold.ttf'),
    'node' => env('MOTIONFORGE_NODE', 'node'),
    'ffmpeg' => env('MOTIONFORGE_FFMPEG', 'ffmpeg'),
    'ffprobe' => env('MOTIONFORGE_FFPROBE', 'ffprobe'),
    'chrome' => env('MOTIONFORGE_CHROME', '/usr/bin/google-chrome'),
    // Parallel Chrome pages per scene render. Each uses roughly one CPU core and 250 MB of memory.
    'render_workers' => (int) env('MOTIONFORGE_RENDER_WORKERS', 2),
    // Catches infinite loops in scene code; generous because a busy machine can take seconds per frame.
    'frame_timeout_ms' => (int) env('MOTIONFORGE_FRAME_TIMEOUT_MS', 30000),
    'scene_timeout_sec' => (int) env('MOTIONFORGE_SCENE_TIMEOUT_SEC', 900),
    'disk' => env('MOTIONFORGE_DISK', 'local'),

    /*
    | Data retention for regenerable intermediates (motionforge:cleanup, daily). Admins can override
    | the value at runtime with the `retention.render_cache_days` system setting.
    */
    'retention' => [
        'render_cache_days' => (int) env('MOTIONFORGE_RENDER_CACHE_DAYS', 30),
    ],

    /*
    | Output defaults (Kikitoons skill).
    */
    'defaults' => [
        // YouTube landscape (Kikitoons skill V2); VIDEO_EXECUTION.md may override per project
        'width' => 1920, 'height' => 1080, 'fps' => 30, 'aspect_ratio' => '16:9',
        'loudness_lufs' => -14.0,
        'safe_zone' => ['x' => [96, 1824], 'y' => [54, 1026]],
        'end_card_seconds' => 5.0,
    ],

    /*
    | Upload limits (kilobytes).
    */
    'uploads' => [
        'text_max_kb' => 512,
        'audio_max_kb' => 200 * 1024,
        'image_max_kb' => 10 * 1024,
        'archive_max_kb' => 300 * 1024,
        'archive_max_entries' => 40,
        'archive_max_uncompressed_kb' => 400 * 1024,
    ],

    /*
    | AI request policy.
    */
    'ai' => [
        'timeout' => (int) env('MOTIONFORGE_AI_TIMEOUT', 180),
        'max_code_attempts' => 2,         // initial attempt + one targeted correction
        'expensive_threshold_usd' => 0.50, // single operations estimated above this need approval
    ],

    /*
    | Server-managed credentials. Admins may expose these env keys to all users as shared
    | connections; the key value is never stored in the database.
    */
    'server_keys' => [
        'ANTHROPIC_API_KEY' => env('ANTHROPIC_API_KEY'),
        'GEMINI_API_KEY' => env('GEMINI_API_KEY'),
        'OPENAI_API_KEY' => env('OPENAI_API_KEY'),
        'MOONSHOT_API_KEY' => env('MOONSHOT_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider + model registry (seeded into ai_providers / ai_models; editable in the UI)
    |--------------------------------------------------------------------------
    | Base URLs are fixed here and never taken from user input (SSRF protection). Prices are per
    | 1M tokens (USD) or per generated second of video and were read from the providers' pricing
    | pages on 2026-11-18. `null` means "not verified" and cost estimates are shown as unavailable.
    */
    'providers' => [
        'claude' => [
            'name' => 'Anthropic Claude',
            'base_url' => 'https://api.anthropic.com',
            'docs_url' => 'https://platform.claude.com/docs',
            'env_key' => 'ANTHROPIC_API_KEY',
            'models' => [
                'claude-opus-5-5' => ['name' => 'Claude Opus 5.5', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => 4.00, 'out' => 20.00, 'source' => 'Anthropic model table (cached 2026-11-03)'],
                'claude-sonnet-5-5' => ['name' => 'Claude Sonnet 5.5', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => 2.00, 'out' => 10.00, 'source' => 'Anthropic model table (cached 2026-11-03)'],
                'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'caps' => ['text', 'structured_output', 'scene_planning', 'image_analysis'], 'in' => 0.10, 'out' => 0.50, 'source' => 'Anthropic model table (cached 2026-11-03)'],
                'claude-fable-5-2' => ['name' => 'Claude Fable 5.2', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => 8.00, 'out' => 40.00, 'source' => 'Anthropic model table (cached 2026-11-03)'],
            ],
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'docs_url' => 'https://aistudio.google.com/docs',
            'env_key' => 'GEMINI_API_KEY',
            'models' => [
                'gemini-4-hydrogen' => ['name' => 'Gemini 4 Hydrogen', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => 1.50, 'out' => 7.50, 'source' => 'aistudio.google.com/docs/pricing (2026-11-18)'],
                'gemini-4-carbon' => ['name' => 'Gemini 4 Carbon', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => null, 'out' => null, 'source' => null],
                'gemini-4-argon' => ['name' => 'Gemini 4 Argon', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => null, 'out' => null, 'source' => null],
                // 5,792 output tokens per second of 720p video at $12.00 / 1M tokens = $0.0695 / s
                'gemini-omni-1.2-flash' => ['name' => 'Gemini Omni 1.2 Flash (video)', 'caps' => ['native_video'], 'in' => 1.50, 'out' => 12.00, 'video_per_second' => 0.0695, 'source' => 'aistudio.google.com/docs/pricing (2026-11-18), 720p',
                    'limits' => ['min_seconds' => 3, 'max_seconds' => 15, 'aspect_ratios' => ['9:16', '16:9'], 'resolutions' => ['360p', '720p', '1080p'], 'reference_images' => 6]],
            ],
        ],
        'openai' => [
            'name' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'docs_url' => 'https://developers.openai.com/api/docs',
            'env_key' => 'OPENAI_API_KEY',
            // The Sora 2 / Videos API was shut down on 2026-09-24; no OpenAI model is registered for native_video.
            'models' => [
                'gpt-6.2-astra' => ['name' => 'GPT-6.2 Astra', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code', 'image_analysis'], 'in' => null, 'out' => null, 'source' => null],
                'gpt-6-astra' => ['name' => 'GPT-6 Astra', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code'], 'in' => null, 'out' => null, 'source' => null],
            ],
        ],
        'kimi' => [
            'name' => 'Moonshot Kimi',
            'base_url' => 'https://api.moonshot.ai/v1',
            'docs_url' => 'https://platform.kimi.ai/docs',
            'env_key' => 'MOONSHOT_API_KEY',
            'models' => [
                'kimi-k3.1-pro' => ['name' => 'Kimi K3.1 Pro', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code'], 'in' => null, 'out' => null, 'source' => null],
                'kimi-k3' => ['name' => 'Kimi K3', 'caps' => ['text', 'structured_output', 'scene_planning', 'animation_code'], 'in' => null, 'out' => null, 'source' => null],
            ],
        ],
    ],
];
