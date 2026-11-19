# MotionForge — AI-Powered Video Production Studio

*Create. Animate. Render.*

Laravel 13 · Blade · Tailwind 4 · Alpine/Livewire 4 · SQLite · Node.js + headless Chrome (Canvas 2D) · FFmpeg

## Local preview login

| Email | Password |
|---|---|
| `admin@motionforge.local` | `mf-b8b4ea53ac17` |

This account is a local development admin. Change the password (Settings › Security) or delete the account before deploying anywhere.

## Run locally

```bash
composer install && npm install
php artisan migrate --seed      # SQLite database at database/database.sqlite
composer dev                    # web server, queue listener, logs, Vite
```

Open http://127.0.0.1:8000. If you change `APP_KEY` or other `.env` values while `composer dev` is running, restart it: running processes keep the environment they started with.

Rendering needs FFmpeg, FFprobe, Node and Chrome. Point MotionForge at them in `.env` when they are not on `PATH` / at the default Chrome location, for example:

```
# Linux
MOTIONFORGE_FFMPEG=/usr/bin/ffmpeg
MOTIONFORGE_FFPROBE=/usr/bin/ffprobe
MOTIONFORGE_CHROME=/usr/bin/google-chrome
# Windows (single quotes: backslashes are escapes inside double quotes)
MOTIONFORGE_CHROME='C:\Program Files\Google\Chrome\Application\chrome.exe'
```

Server setup (nginx, Supervisor workers, scheduler) and Windows notes: [docs/deployment.md](docs/deployment.md). Architecture: [docs/architecture.md](docs/architecture.md).

## Tests

```bash
php artisan test                         # everything, incl. real Chrome + FFmpeg renders (~3 min)
php artisan test --exclude-group=render  # fast suite, AI calls mocked
```

AI providers are mocked with `Http::fake` in the suite; passing tests say nothing about live provider behaviour.

## AI providers

Add your own API keys in **Settings › AI Providers** (Claude, Gemini, OpenAI, Kimi). Keys are encrypted at rest, never shown again, and tested with a real authenticated request before a connection is marked connected. The **template director** strategy works without any key.

Native video generation: Gemini Omni (`gemini-omni-1.2-flash`). OpenAI's Sora/Videos API was shut down on 2026-09-24, so OpenAI is used for planning and code only.

## Kikitoons engine

The skill file specifies the engine but doesn't include it (`core.js`, `bg.js`, `engine.js` live in the claude.ai Project "video"). MotionForge ships a **Kikitoons-compatible engine** in `engine/kikitoons-compat/` that implements the documented API. To use the original engine, copy its files to a folder and set `KIKITOONS_ENGINE_PATH` to that folder.
