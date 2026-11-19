# Deploying MotionForge

Two supported setups: an Ubuntu server (production) and Windows (local development).

## Requirements

| Component | Version / notes |
|---|---|
| PHP | 8.3+ with `pdo_sqlite`, `mbstring`, `openssl`, `zip`, `fileinfo`, `gd`, `intl` |
| Composer | 2.x |
| Node.js | 22+ (renderer, validator and sound design run in Node) |
| Google Chrome | stable channel, used headless by the renderer through `playwright-core` |
| FFmpeg + FFprobe | 6+ with `libx264` and `aac` |
| Fonts | Poppins Bold ships in `engine/fonts/`; nothing to install |

## Ubuntu (24.04 or newer)

### 1. Packages

```bash
sudo apt update
sudo apt install -y nginx php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml \
  php8.4-zip php8.4-gd php8.4-intl php8.4-curl unzip git ffmpeg supervisor
# Node 22 (NodeSource) and Chrome
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y ./google-chrome-stable_current_amd64.deb
```

### 2. Application

```bash
sudo mkdir -p /var/www/motionforge && sudo chown $USER:www-data /var/www/motionforge
git clone https://github.com/yornmeun/motion-forge /var/www/motionforge
cd /var/www/motionforge
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
DB_DATABASE=/var/www/motionforge/database/database.sqlite
MOTIONFORGE_FFMPEG=/usr/bin/ffmpeg
MOTIONFORGE_FFPROBE=/usr/bin/ffprobe
MOTIONFORGE_CHROME=/usr/bin/google-chrome
MOTIONFORGE_RENDER_WORKERS=2
```

Then:

```bash
php artisan migrate --seed --force
php artisan config:cache route:cache view:cache
sudo chown -R www-data:www-data storage bootstrap/cache database
```

Register the first account in the browser: it becomes the administrator. Later accounts are
producers.

### 3. nginx

```nginx
server {
    listen 80;
    server_name your-domain;
    root /var/www/motionforge/public;
    index index.php;
    client_max_body_size 320M;   # archive uploads are capped at 300 MB

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Set `upload_max_filesize = 300M` and `post_max_size = 320M` in `/etc/php/8.4/fpm/php.ini`. Add TLS
with certbot.

### 4. Queue workers (Supervisor)

Rendering and AI work run on the `database` queue. Two workers is a good start on 4+ cores; each
render worker also starts headless Chrome.

```ini
; /etc/supervisor/conf.d/motionforge.conf
[program:motionforge-worker]
command=php /var/www/motionforge/artisan queue:work --sleep=2 --tries=3 --timeout=1900 --max-time=3600
directory=/var/www/motionforge
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=1900
stdout_logfile=/var/log/motionforge-worker.log
```

`--timeout` must exceed the longest job (`GenerateSceneCodeJob`/`GenerateStoryboardJob`: 1800 s).
After every deploy run `php artisan queue:restart`.

### 5. Scheduler

```cron
* * * * * www-data cd /var/www/motionforge && php artisan schedule:run >> /dev/null 2>&1
```

This runs `motionforge:cleanup` daily (see `docs/architecture.md`).

### Optional

- **Redis + Horizon:** install `redis-server` and `php8.4-redis`, set `QUEUE_CONNECTION=redis`,
  `CACHE_STORE=redis`, then `composer require laravel/horizon`. Not required.
- **Docker sandbox for the renderer:** scene code already runs in Chrome with a strict CSP, no
  network and a scrubbed environment. For stronger isolation, run the queue workers in a container
  with no outbound network and a read-only application mount, writing only to `storage/`.
- **Server-managed keys:** `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `OPENAI_API_KEY` and
  `MOONSHOT_API_KEY` in `.env` can be offered to all users as shared connections. Otherwise each user
  adds their own key in Settings › AI Providers.

## Windows (local development)

Tested on Windows 11 with PHP 8.4, Node 24, Chrome 157 and FFmpeg from winget.

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
New-Item database\database.sqlite -ItemType File
php artisan migrate --seed
composer dev
```

`.env` notes:

- Use forward slashes or single quotes for Windows paths. Double-quoted values treat `\` as an
  escape and the app will refuse to start:
  ```dotenv
  DB_DATABASE="D:/path/to/motion-forge/database/database.sqlite"
  MOTIONFORGE_CHROME='C:\Program Files\Google\Chrome\Application\chrome.exe'
  ```
- `ffmpeg`/`ffprobe`/`node` are found on `PATH` when the variables are left at their defaults.
- PowerShell 5's `Set-Content` writes a byte-order mark; save `.env` as UTF-8 *without* BOM.

## Verifying an installation

```bash
php artisan test                      # full suite incl. real render tests (about 3 minutes)
php artisan test --exclude-group=render
php artisan motionforge:cleanup --dry-run
```

The `render` group renders real video with Chrome and FFmpeg; if it passes, the toolchain is
working on that machine.
