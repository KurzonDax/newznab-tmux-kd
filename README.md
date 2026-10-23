# NNTmux

NNTmux is a Usenet indexer built on Laravel. It scans Usenet, collects headers,
forms them into releases, enriches them with metadata, and serves them through a
web front end, a newznab-compatible API and RSS feeds.

This repository, [KurzonDax/newznab-tmux-kd](https://github.com/KurzonDax/newznab-tmux-kd),
is a fork of [NNTmux](https://github.com/NNTmux/newznab-tmux). NNTmux itself is a
fork of [newznab plus](https://github.com/anth0/nnplus) and
[nZEDb](https://github.com/nZEDb/nZEDb).

- Multi-process header collection, release formation and post-processing, run
  from a tmux session
- Search by name, subject, category and post date
- Local caching of metadata: TV (TVDB, TVMaze, TMDB, Trakt), movies (TMDB, IMDb,
  Trakt, OMDb, Fanart.tv), music (MusicBrainz, AcoustID), books (Google Books,
  ISBNdb, Open Library), games (IGDB, Steam) and anime (AniList)
- Image, audio and video previews
- Frontend: Blade, Alpine.js and Tailwind CSS, built with Vite; the forum preset
  includes Vue, and Laravel Pulse uses Livewire
- Full-text search with ManticoreSearch
- Docker development environment based on Laravel Sail
- newznab-compatible XML API, a JSON API (v2) and RSS feeds

## Table of Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Operations Guides](#operations-guides)
- [Database Setup](#database-setup)
- [Search Engine](#search-engine)
- [Console Commands](#console-commands)
- [IRC Pre Channels](#irc-pre-channels)
- [TV & Movie Processing](#tv--movie-processing)
- [API](#api)
- [Docker & Development](#docker--development)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Prerequisites

- System administration experience; Linux is required for processing workers.
- PHP 8.4+ (`composer.json` requires `^8.4`; the Docker image uses PHP 8.5) with
  the extensions Composer checks, including curl, ffi, gd, intl, mbstring,
  pcntl, pdo_mysql, posix, sockets and xmlwriter. The default `.env.example` also
  needs the redis and imagick extensions. Worker CLI PHP must permit
  `FFI::cdef()` (`ffi.enable=preload` or `true`) and expose Linux `/proc`;
  workers use the kernel subreaper facility to terminate and reap owned
  descendants before replacing timed-out work.
- MariaDB (the Compose templates pin `mariadb:12.3`; PostgreSQL is not supported)
- Redis
- ManticoreSearch (the Compose templates pin `manticoresearch/manticore:28.4.4`)
- Composer 2.x
- Node.js 24 and npm for frontend assets (the Docker image uses Node 24)
- nginx or Apache web server
- tmux, to run the processing engine
- Optional: unrar, unzip, ffmpeg, mediainfo, wavpack (for post-processing)
- `wavpack` is required for previews of 32-bit float WavPack releases; on Ubuntu/Debian, install it with `sudo apt install wavpack` (provides `wvunpack`)

### Recommended Hardware

| Scale | RAM | CPU | Disk |
|-------|-----|-----|------|
| Small (<1M releases) | 16GB | 4 cores | 100GB SSD |
| Medium (1-10M releases) | 32GB | 8 cores | 250GB SSD |
| Large (10M+ releases) | 64GB+ | 16+ cores | 500GB+ NVMe |

## Installation

- **Docker:** follow [DOCKER.md](DOCKER.md).
- **Native Ubuntu install:** follow [docs/installation.md](docs/installation.md),
  which also covers the scheduler, Horizon and upgrades.

### Quick Start

1. Clone the repository:
   ```bash
   git clone https://github.com/KurzonDax/newznab-tmux-kd.git
   cd newznab-tmux-kd
   ```

2. Install PHP dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. Copy the environment file and configure it (see [Configuration](#configuration)):
   ```bash
   cp .env.example .env
   ```

4. Install NNTmux. **For a new installation only:** `nntmux:install` runs
   `migrate:fresh --seed`, which drops every table in the database. It then
   creates the admin account from `ADMIN_USER`, `ADMIN_PASS` and `ADMIN_EMAIL`
   and generates `APP_KEY`.
   ```bash
   php artisan nntmux:install
   ```

5. Create the search tables and build frontend assets:
   ```bash
   php artisan manticore:create-indexes
   npm ci
   npm run build
   ```

6. Make `storage`, `bootstrap/cache` and the NZB, cover and temporary paths from
   `.env` writable by the web server and workers, add the scheduler cron entry,
   and start processing with `php artisan tmux:start`. See
   [docs/installation.md](docs/installation.md) for each step.

## Configuration

### Essential .env Settings

Key names and defaults below are those in `.env.example`.

```env
# Application
APP_NAME=NNTmux
APP_ENV=production
APP_DEBUG=false
APP_URL=

# Database
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nntmux
DB_USERNAME=
DB_PASSWORD=

# Admin account created by nntmux:install
ADMIN_USER=
ADMIN_PASS=
ADMIN_EMAIL=

# Usenet providers (numbered groups; provider 1 is the primary and serves all headers).
# Add NNTP_PROVIDER_2_* for a second backbone -- article fetches fail over to it per article.
# For TLS, typically PORT=563 and SSL=true.
NNTP_PROVIDER_1_NAME=primary
NNTP_PROVIDER_1_HOST=
NNTP_PROVIDER_1_PORT=119
NNTP_PROVIDER_1_SSL=false
NNTP_PROVIDER_1_USERNAME=
NNTP_PROVIDER_1_PASSWORD=
NNTP_PROVIDER_1_CONNECTIONS=1

# Metadata API keys (obtain from the respective services)
OMDB_APIKEY=
TRAKTTV_APIKEY=
FANARTTV_APIKEY=
TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=
GOOGLE_BOOKS_API_KEY=
ISBNDB_API_KEY=
# Leave empty to keep MusicBrainz resolution dormant. Public musicbrainz.org endpoints
# require a maintainer email or URL in MUSICBRAINZ_USER_AGENT_CONTACT.
MUSICBRAINZ_ENDPOINT_URL=
MUSICBRAINZ_USER_AGENT_CONTACT=
# Optional: AcoustID fingerprint lookups for music that MusicBrainz matching leaves
# unresolved. When set, each derived Chromaprint fingerprint and its duration are sent
# to AcoustID, a third-party service (lookup only; the audio itself is never sent).
ACOUSTID_CLIENT_KEY=

# Search Engine
SEARCH_DRIVER=manticore
MANTICORESEARCH_HOST=localhost
MANTICORESEARCH_PORT=9308

# IRC Pre Scraping (optional; set these before running irc:scrape)
SCRAPE_IRC_SERVER=
SCRAPE_IRC_PORT=
SCRAPE_IRC_TLS=
SCRAPE_IRC_USERNAME=
```

`.env.example` ships default keys in `TMDB_APIKEY` and `TVDB_APIKEY`; replace
them with your own. IGDB game lookups use the Twitch application credentials.
The NNTP provider keys are described in
[docs/architecture/nntp-providers.md](docs/architecture/nntp-providers.md).
Most other behaviour is set in the admin settings pages; see
[docs/configuration.md](docs/configuration.md).

### Queue Configuration

`.env.example` sets `QUEUE_CONNECTION=sync`, which runs queued jobs (mail, API
usage records) inline. To run them in the background, switch to Redis and run
Laravel Horizon, listing every queue the application uses in `HORIZON_QUEUES`;
see [docs/installation.md](docs/installation.md#7-scheduler-and-queue-workers).
The Docker image already runs Horizon under Supervisor.

```env
QUEUE_CONNECTION=sync
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Operations Guides

- [Native installation and upgrades](docs/installation.md)
- [Configuration and admin settings](docs/configuration.md)
- [Hardware-accelerated clip encoding](docs/hardware-acceleration.md)
- [Backups](docs/backups.md)
- [ManticoreSearch upgrades](docs/manticore-28-upgrade.md)
- [ManticoreSearch Ubuntu package recovery](docs/manticore-ubuntu-package.md)
- [Search performance](docs/search-performance.md)
- [Release-table optimization](docs/releases-table-optimization.md)
- [Orphaned collection recovery](docs/orphaned-collection-recovery.md)
- [Native Node 24 production upgrade](docs/node24-native-upgrade.md)

Architecture: [indexing pipeline](docs/architecture/indexing-pipeline.md),
[NNTP providers](docs/architecture/nntp-providers.md),
[provider gateway](docs/architecture/provider-gateway.md),
[decision model](docs/architecture/decision-model.md).

## Database Setup

### Initial Setup

`php artisan nntmux:install` creates the schema and seeds the initial data
(categories, groups, settings) on an empty database. On an existing
installation, apply new migrations with:

```bash
php artisan migrate
```

### Database Tuning

For large-scale indexing, proper database tuning is critical. Key settings:

```ini
# /etc/mysql/mariadb.conf.d/99-nntmux.cnf
[mysqld]
innodb_buffer_pool_size = 8G          # 50-70% of available RAM
innodb_log_file_size = 1G
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
query_cache_type = 0
query_cache_size = 0
max_connections = 500
tmp_table_size = 256M
max_heap_table_size = 256M
```

Use [mysqltuner.pl](http://mysqltuner.pl) for recommendations:
```bash
wget https://raw.githubusercontent.com/major/MySQLTuner-perl/master/mysqltuner.pl
perl mysqltuner.pl
```

Create the database with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
(see [docs/installation.md](docs/installation.md#2-create-the-database)).

## Search Engine

NNTmux uses ManticoreSearch for full-text search.

### ManticoreSearch

```bash
# Install ManticoreSearch
wget https://repo.manticoresearch.com/manticore-repo.noarch.deb
sudo dpkg -i manticore-repo.noarch.deb
sudo apt update
sudo apt install manticore

# Configure in .env
SEARCH_DRIVER=manticore
MANTICORESEARCH_HOST=localhost
MANTICORESEARCH_PORT=9308

# Build indexes
php artisan manticore:create-indexes
php artisan nntmux:populate --manticore --releases
```

`php artisan manticore:inspect` reports the server version and whether the table
schemas match the application. The scheduler runs `nntmux:search-repair` and
`nntmux:search-maintain` to keep the index in step with the database; see
[docs/search-performance.md](docs/search-performance.md).

## Console Commands

NNTmux provides numerous Artisan commands for management and maintenance.
`php artisan list` shows them all, and `php artisan help <command>` shows a
command's options.

### Release Processing

```bash
# Start, attach to and stop the tmux processing engine
php artisan tmux:start
php artisan tmux:attach
php artisan tmux:stop

# Form releases for one group (by ID) or all groups
php artisan releases:process

# Run additional post-processing for one release by GUID (or --id=)
php artisan releases:additional <guid>

# Reset post-processing for all releases, or one category with --category=
php artisan nntmux:resetpp
```

### TV & Movie Processing

```bash
# Reprocess TV releases not matched to any show
php artisan tv:reprocess-unmatched --limit=1000

# Reprocess TV releases matched to a show but not to an episode
php artisan tv:reprocess-missing-episodes --video-id=12345

# Rematch one TV release by GUID
php artisan tv:reprocess <guid> --reset

# Reset post-processing for every release of one show
php artisan nntmux:reset-tv-show <videos_id> --dry-run

# Fetch or refresh one movie by IMDb ID
php artisan nntmux:fetch-movie <imdbid>
```

### Database Maintenance

```bash
# Optimize tables
php artisan nntmux:optimize-tables

# Delete releases matching criteria (preview first with --dry-run)
php artisan nntmux:delete-releases --hours-old=8760 --dry-run

# Run or list database backups
php artisan backup:run full
php artisan backup:list

# Delete all Laravel Pulse data
php artisan pulse:clear

# Clear application caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

### User Management

`nntmux:install` creates the first admin account. Manage users afterwards under
**Admin → Users** (`/admin/user-list`); users reset their own passwords from the
forgotten-password page (`/forgottenpassword`).

## IRC Pre Channels

NNTmux can scrape IRC pre channels for early release information with
`php artisan irc:scrape`.

### Configuration

```env
SCRAPE_IRC_SERVER=
SCRAPE_IRC_PORT=
SCRAPE_IRC_TLS=
SCRAPE_IRC_USERNAME=
SCRAPE_IRC_PASSWORD=
```

Set the server, port and TLS flag before running the scraper: `.env.example`
leaves them empty, and an empty value is used as is. The defaults in
`config/irc_settings.php` (`irc.synirc.net`, port 6667, no TLS) apply only when
a key is absent from `.env`. `SCRAPE_IRC_USERNAME` is used as the nickname, real
name and ident; `SCRAPE_IRC_PASSWORD` is only needed for a bouncer such as ZNC.
The channel list (by default `#PreNNTmux`) and the category, title and source
filters are set in `config/irc_settings.php`.

## TV & Movie Processing

NNTmux fetches metadata from multiple sources with fallback support.

### Provider Order

- **TV:** the local database first, then TVDB, TVMaze, TMDB and Trakt, in that
  order (`app/Services/TvProcessing/Pipes/`).
- **Movies:** TMDB, IMDb, Trakt and OMDb, with artwork from Fanart.tv.

External lookups go through the provider gateway, which paces requests and
stops calling a provider that is failing; see
[docs/architecture/provider-gateway.md](docs/architecture/provider-gateway.md).

### Episode Matching

The TV processing pipeline automatically:
- Matches releases to shows by name
- Downloads episode information for matched shows
- Revisits recently matched shows for new episodes (`TV_EPISODE_REVISIT_WINDOW_DAYS`,
  `TV_EPISODE_REVISIT_INTERVAL_HOURS`)

If episodes aren't matching properly:

```bash
# Check what video ID a show has
php artisan tinker
>>> DB::table('videos')->where('title', 'like', '%Show Name%')->get(['id', 'title', 'tmdb']);

# Reprocess that show's releases that have no episode match
php artisan tv:reprocess-missing-episodes --video-id=<ID>
```

## API

NNTmux provides a newznab-compatible API for integration with download clients and media managers (Sonarr, Radarr, etc.).

### Endpoints

The newznab-compatible XML API is served at `/api/v1/api`:

```
GET /api/v1/api?t=caps            # Server capabilities
GET /api/v1/api?t=search&q=       # Search releases
GET /api/v1/api?t=tvsearch        # TV search
GET /api/v1/api?t=movie           # Movie search
GET /api/v1/api?t=music           # Music search
GET /api/v1/api?t=book            # Book search
GET /api/v1/api?t=details&id=     # Release details
GET /api/v1/api?t=get&id=         # Download NZB
```

The JSON API is served under `/api/v2/`, and RSS feeds under `/rss/`. See
[docs/newznab_api_specification.txt](docs/newznab_api_specification.txt) and
[docs/nntmux_api_v2.md](docs/nntmux_api_v2.md).

### API Keys

Users find their API key on their account's API page. Request rate limits are set
per role in the admin panel.

## Docker & Development

### Laravel Sail (Docker)

[DOCKER.md](DOCKER.md) describes the Docker environment and its `make` targets.

```bash
# Start containers
make up

# Run artisan commands
make artisan cmd="migrate"

# Stop containers
make down
```

### Frontend Development

```bash
# Install dependencies
npm install

# Development with hot reload
npm run dev

# Production build
npm run build
```

In a served Docker checkout, build with `make npm-build` instead (see
[DOCKER.md](DOCKER.md)).

### Code Style

```bash
# PHP formatting (Laravel Pint)
./vendor/bin/pint

# CSS/JS/Vue formatting (Prettier)
npm run format
```

### Agent Skills

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

The repository keeps the shared agent skill baseline in `skills-lock.json`. The
generated Linux installer installs those skills globally under `~/.agents/skills`
and bridges them into Codex under `~/.codex/skills`.

Prerequisites: Bash, Node.js/npm (`npx`), and network access to the skill
repositories.

```bash
# Regenerate the installer after changing skills-lock.json
./scripts/generate-global-skills-installer.sh

# Preview global installs and Codex bridge changes
./scripts/install-global-skills.sh --dry-run

# Install globally and create Codex symlinks
./scripts/install-global-skills.sh

# Copy skills into Codex instead of creating symlinks
./scripts/install-global-skills.sh --copy
```

Set `SKILLS_GLOBAL_DIR` or `CODEX_HOME` when using non-default global
directories. The installer never removes or overwrites unmanaged existing
skill paths; resolve reported conflicts manually and rerun it.

## Troubleshooting

### Common Issues

**Releases not being created:**
```bash
# Check for errors in logs
tail -f storage/logs/laravel.log

# Check the tmux session and its monitor pane
php artisan tmux:health-check

# Check group status
php artisan tinker
>>> DB::table('usenet_groups')->where('active', 1)->count();
```

**TV/Movie not matching:**
```bash
# Rematch one TV release
php artisan tv:reprocess <guid> --reset --debug

# Rerun additional post-processing for one release
php artisan releases:additional <guid> --reset
```

API keys are read from `.env`; after changing one, run `php artisan config:clear`
(or `config:cache` if you cache configuration).

**High disk usage from Laravel Pulse:**
```bash
# Check Pulse table sizes
mysql -e "SELECT table_name, ROUND(data_length/1024/1024, 2) as 'Size (MB)' FROM information_schema.tables WHERE table_schema='nntmux' AND table_name LIKE 'pulse%';"

# Delete all Pulse data
php artisan pulse:clear
```

**Permission issues:** see the permission steps in
[docs/installation.md](docs/installation.md#5-permissions); in Docker, run
`make fix-permissions` (see [DOCKER.md](DOCKER.md)).

### Logs

- **Application logs:** `storage/logs/` (`laravel.log` by default, plus per-channel files)
- **Tmux pane logs:** `storage/logs/tmux/`, written only when the `write_logs`
  setting is 1. Neither the seeder nor any admin page sets it, so pane logging is
  off on a standard install; pane output is visible in the session
  (`php artisan tmux:attach`).
- **nginx logs:** `/var/log/nginx/`
- **PHP-FPM logs:** `/var/log/php-fpm/`

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Every change reaches `master` through a
pull request; application changes start from an agreed GitHub issue.

## Support

- **GitHub Issues:** [Report bugs](https://github.com/KurzonDax/newznab-tmux-kd/issues)
- **Upstream NNTmux:** [NNTmux/newznab-tmux](https://github.com/NNTmux/newznab-tmux), whose
  wiki and Discord describe the upstream project rather than this fork

## License

NNTmux is open-source software licensed under the [GPL v3](LICENSE). External libraries include their own licenses in their respective folders.
