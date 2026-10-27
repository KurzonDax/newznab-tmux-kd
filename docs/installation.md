# Native installation on Ubuntu

This guide installs NNTmux directly on an Ubuntu host. For the Docker
development environment, use [DOCKER.md](../DOCKER.md) instead. The supplied
Docker image (`docker/8.5/Dockerfile`) is built on Ubuntu 24.04 with PHP 8.5
from the `ondrej/php` PPA, and is a useful reference for package names.

The examples install into `/var/www/nntmux`, the path `.env.example` uses for
NZB, cover and temporary directories.

## Requirements

- PHP 8.4 or newer (`composer.json` requires `^8.4`). Composer checks the
  required extensions: bcmath, ctype, curl, exif, ffi, fileinfo, filter, gd,
  hash, iconv, intl, json, libxml, mbstring, mysqlnd, openssl, pcntl, pcre, pdo,
  pdo_mysql, posix, session, simplexml, sockets, sodium, spl, xmlwriter and zlib.
  The default `.env.example` also needs the redis extension (`REDIS_CLIENT=phpredis`)
  and imagick (`IMAGE_DRIVER=imagick`).
- Worker CLI PHP must permit `FFI::cdef()` (`ffi.enable=preload` or `true`) and
  expose Linux `/proc`; workers use the kernel subreaper facility to terminate
  and reap timed-out work.
- MariaDB. The Compose templates pin `mariadb:12.3`.
- Redis.
- Manticore Search. The Compose templates pin `manticoresearch/manticore:28.4.4`.
- Node.js 24 and npm, to build the frontend (`docker/8.5/Dockerfile` uses Node 24).
- Composer 2, nginx, tmux and Supervisor.
- Post-processing tools, at the paths `.env.example` gives: `unrar`, `unzip`,
  `ffmpeg`, `mediainfo`, `timeout` and the libmagic database
  (`UNRAR_PATH`, `UNZIP_PATH`, `FFMPEG_PATH`, `MEDIAINFO_PATH`, `TIMEOUT_PATH`,
  `MAGIC_FILE_PATH`). `wavpack` (`wvunpack`) is needed for previews of 32-bit
  float WavPack releases.

## 1. Install packages

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.5-cli php8.5-fpm php8.5-bcmath php8.5-curl php8.5-ffi \
    php8.5-gd php8.5-intl php8.5-mbstring php8.5-mysql php8.5-xml php8.5-zip \
    php8.5-redis php8.5-imagick
sudo apt install mariadb-server redis-server nginx supervisor tmux git unzip \
    unrar ffmpeg mediainfo wavpack
```

Install Composer 2 and Node.js 24 from their official distributions, and
Manticore Search from the Manticore apt repository:

```bash
wget https://repo.manticoresearch.com/manticore-repo.noarch.deb
sudo dpkg -i manticore-repo.noarch.deb
sudo apt update
sudo apt install manticore
```

If the Manticore package fails while starting `manticore.service`, see
[manticore-ubuntu-package.md](manticore-ubuntu-package.md).

## 2. Create the database

```sql
CREATE DATABASE nntmux CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nntmux'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON nntmux.* TO 'nntmux'@'localhost';
```

For tuning a large installation, see
[releases-table-optimization.md](releases-table-optimization.md) and
[search-performance.md](search-performance.md).

## 3. Get the code and configure `.env`

```bash
sudo install -d -o "$USER" -g www-data /var/www/nntmux
git clone https://github.com/KurzonDax/newznab-tmux-kd.git /var/www/nntmux
cd /var/www/nntmux
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

Edit `.env`. At minimum set the database (`DB_*`), the admin account the
installer creates (`ADMIN_USER`, `ADMIN_PASS`, `ADMIN_EMAIL`), `APP_URL`, and
your Usenet provider (`NNTP_PROVIDER_1_*`, see
[nntp-providers.md](architecture/nntp-providers.md)). The README's
[Configuration](../README.md#configuration) section lists the common keys.
Remove or replace the `COMPOSER_AUTH` placeholder line.

## 4. Install

```bash
php artisan nntmux:install
```

`nntmux:install` is for a new installation only. It runs
`migrate:fresh --seed`, which drops every table in the database, then checks the
NZB, cover and temporary paths, links `/etc/manticoresearch/manticore.conf` to
the project's `config/manticore.conf` (asking for sudo), creates the admin
account from `ADMIN_USER` / `ADMIN_PASS` / `ADMIN_EMAIL`, generates `APP_KEY`,
and writes `_install/install.lock`. A second run stops at that lock file.

Then create the search tables and build the frontend:

```bash
php artisan manticore:create-indexes
npm ci
npm run build
```

`npm run build` runs `php artisan typescript:transform` first, so it needs a
working `.env`.

## 5. Permissions

The web server and the workers must be able to write `storage`,
`bootstrap/cache` and the NZB, cover and temporary paths from `.env`
(`PATH_TO_NZBS`, `COVERS_PATH`, `TEMP_UNRAR_PATH`, `TEMP_UNZIP_PATH`):

```bash
sudo chgrp -R www-data storage bootstrap/cache public/covers
sudo find storage bootstrap/cache public/covers -type d -exec chmod 2775 {} +
sudo find storage bootstrap/cache public/covers -type f -exec chmod 0664 {} +
sudo chgrp www-data .env && chmod 0640 .env
```

## 6. Web server

Point nginx's document root at `/var/www/nntmux/public` and pass PHP to
PHP-FPM. `docker/8.5/nginx.conf` is a working example (it uses
`client_max_body_size 100m` and the `php8.5-fpm` socket).

## 7. Scheduler and queue workers

The scheduler runs backups, search-index repair, health checks, account expiry
and other housekeeping (`routes/console.php`). Add one cron entry:

```cron
* * * * * cd /var/www/nntmux && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows what it runs.

`.env.example` sets `QUEUE_CONNECTION=sync`, which runs queued jobs inline. To
run them in the background, set `QUEUE_CONNECTION=redis` and run Laravel
Horizon under Supervisor. With `HORIZON_QUEUES` unset, Horizon consumes every
queue the application dispatches to; set it only to restrict them.

```dotenv
QUEUE_CONNECTION=redis
```

```ini
; /etc/supervisor/conf.d/nntmux-horizon.conf
[program:nntmux-horizon]
command=php /var/www/nntmux/artisan horizon
directory=/var/www/nntmux
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/nntmux/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
php artisan horizon:status
```

Run one Horizon master per installation. The `/horizon` dashboard is limited to
administrators with two-factor authentication. After a deploy, run
`php artisan horizon:terminate` so Supervisor restarts it on the new code.

## 8. Configure and start indexing

1. Sign in as the admin account and work through **Admin → Settings**
   (`/admin/settings`); the sections are described in
   [configuration.md](configuration.md).
2. Activate the newsgroups to index under **Admin → Groups** (`/admin/group-list`).
3. Start the processing engine:

   ```bash
   php artisan tmux:start
   php artisan tmux:attach      # watch it; detach with Ctrl-b d
   php artisan tmux:stop
   ```

The scheduler runs `tmux:health-check --auto-restart` every 30 minutes.

## Upgrading

`php artisan nntmux:all` runs the whole upgrade. It puts the site into
maintenance mode and stops tmux if they are running, pulls with git
(`nntmux:git`), runs Composer (`nntmux:composer`) and migrations (`nntmux:db`),
runs `npm ci` and `npm run build`, clears the view cache and rebuilds the route
cache, appends to `.env` any key from `.env.example` that `.env` lacks (with the
example value), then restores maintenance mode and tmux to how it found them. `--skip-git`,
`--skip-composer`, `--skip-npm` and `--skip-db` skip a step.

Before upgrading, back up the database ([backups.md](backups.md)). After
upgrading, review the keys `nntmux:all` appended to `.env`, since they carry the
example values. After upgrading, run
`php artisan horizon:terminate` if you use Horizon. Some changes need extra
steps; they are described in their own guides, such as
[releases-table-optimization.md](releases-table-optimization.md) and
[manticore-28-upgrade.md](manticore-28-upgrade.md).
