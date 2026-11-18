# Deploying on a shared VPS

Author: Abdelrhman Shalby

Target: Ubuntu 24.04, 2 vCPU, 8 GB RAM, with **other sites already running**. Stack:
Nginx, PHP-FPM 8.3, Postgres 16, Supervisor (queue worker), cron (scheduler).

> **Status: NOT RUN.** These steps were written from what the app needs and have not
> been executed on the server. Run them one at a time and read each output before the
> next step. Nothing here should be pasted as one block.

## Rules that protect the other sites

- Everything for this app lives under its own Linux user, its own PHP-FPM pool, its own
  Postgres database and role, its own Nginx server block, its own Supervisor program and
  its own crontab.
- Never edit another site's files, the default Nginx site, `nginx.conf`, the default
  `www` FPM pool, `postgresql.conf` or `pg_hba.conf`.
- Always `nginx -t` before `systemctl reload nginx`. Use **reload**, never restart, for
  Nginx and PHP-FPM.
- Do not run `apt upgrade` or `apt autoremove` as part of this deploy. Install only the
  packages that step 1 shows are missing.
- No Docker is needed on the server for this app.

Placeholders used below: `APP_HOST` = the subdomain (for example
`promptedit.example.com`), `REPO_URL` = the public GitHub repository.

## 0. Before touching the server

- Point a DNS `A` record for `APP_HOST` at the server's IP and wait until
  `dig +short APP_HOST` returns it.
- Create a **new Pixelforge API key for production**. Never use it anywhere else (no
  curl, no local `.env`): `/v1/usage` is per key and the admin report is compared with it.

## 1. Look first (read-only)

```bash
lsb_release -ds
php -v; php -m | grep -Ei 'pdo_pgsql|pgsql|mbstring|xml|curl|zip|intl'
ls /etc/php/                     # which PHP versions exist
systemctl list-units --type=service --state=running | grep -Ei 'nginx|apache|php|postgres|supervisor'
sudo ss -ltnp | grep -E ':(80|443|5432)\s'
ls /etc/nginx/sites-enabled/
psql --version; sudo -u postgres psql -c '\l'    # only if Postgres is installed on the host
node -v; composer --version
free -h; df -h /
```

Decide from the output:

| Finding | Action |
|---|---|
| Nginx serves ports 80/443 | Continue as written |
| Apache or a Docker proxy (Traefik, Caddy, nginx-proxy) serves 80/443 | **Stop.** The server block in step 6 does not apply; the vhost must be added to that proxy instead |
| PHP 8.3 with FPM is installed | Reuse it, add only a new pool (step 5) |
| PHP 8.3 is missing | `sudo apt install php8.3-fpm php8.3-cli` plus the extensions below. This adds a new PHP version next to the existing ones; it does not change them |
| Postgres 16 runs on the host | Reuse it, add only a database and a role (step 3) |
| Postgres runs only inside Docker for another project | Do not reuse that container. Install the host package (it listens on 127.0.0.1:5432; if that port is taken, stop and decide a port first) |
| Less than about 1 GB of free RAM | Stop and decide first. This app needs roughly 300 MB (FPM pool, one worker, its share of Postgres) |

Packages the app needs, install only the missing ones:

```bash
sudo apt install php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl \
                 postgresql supervisor certbot python3-certbot-nginx git unzip
```

Composer, if missing: install it from getcomposer.org into `/usr/local/bin/composer`.

## 2. Isolated Linux user

```bash
sudo adduser --system --group --home /srv/promptedit --shell /bin/bash promptedit
sudo chmod 750 /srv/promptedit
sudo usermod -aG promptedit www-data      # lets Nginx read the public files, nothing else changes for www-data
```

## 3. Own Postgres database and role

```bash
sudo -u postgres psql
```
```sql
CREATE ROLE promptedit LOGIN PASSWORD 'choose-a-long-random-password';
CREATE DATABASE promptedit OWNER promptedit;
REVOKE ALL ON DATABASE promptedit FROM PUBLIC;
\q
```

The role owns only its own database and has no rights on any other.

## 4. Code and configuration

Node is only needed to build the frontend. If `node -v` is missing or older than 22,
install it for this user only with nvm (it does not touch the system):

```bash
sudo -iu promptedit
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash   # then log in again
nvm install 22
```

As `promptedit`:

```bash
git clone REPO_URL /srv/promptedit/app
cd /srv/promptedit/app
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env
```

Values to set in `.env` (names only; values come from you and from the Pixelforge dashboard):

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://APP_HOST` |
| `LOG_LEVEL` | `info` |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `5432` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from step 3 |
| `SESSION_SECURE_COOKIE` | `true` (add this line) |
| `PIXELFORGE_API_KEY` | the production key |
| `PIXELFORGE_WEBHOOK_SECRET` | the production key's `whsec_...` secret |
| `DEMO_USER_EMAIL`, `DEMO_USER_PASSWORD` | the reviewer demo account |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | the reviewer admin account |

Passwords must be at least 12 characters with mixed case, numbers and symbols (the
production password rule of the starter kit applies to registration; use the same
standard for the seeded accounts).

```bash
chmod 640 .env
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force          # creates the demo user ($10 credit) and the admin; safe to repeat
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R g+rX public
exit
```

Note: `npm run build` runs `php artisan` to generate route helpers, so it must run after
`composer install` and after `.env` exists.

## 5. Own PHP-FPM pool

Create `/etc/php/8.3/fpm/pool.d/promptedit.conf` (a new file; do not edit `www.conf`):

```ini
[promptedit]
user = promptedit
group = promptedit
listen = /run/php/promptedit.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[memory_limit] = 128M
```

```bash
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
ls -l /run/php/promptedit.sock
```

## 6. Own Nginx server block

Create `/etc/nginx/sites-available/promptedit` (a new file):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name APP_HOST;

    root /srv/promptedit/app/public;
    index index.php;
    client_max_body_size 2m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/promptedit.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 30s;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/promptedit /etc/nginx/sites-enabled/promptedit
sudo nginx -t && sudo systemctl reload nginx
curl -sI http://APP_HOST/up | head -1          # expect HTTP/1.1 200 OK
```

`server_name` is the only name this block answers for, so no other site is affected.

## 7. HTTPS for this subdomain only

```bash
sudo certbot --nginx -d APP_HOST
sudo nginx -t && sudo systemctl reload nginx
curl -sI https://APP_HOST/up | head -1         # expect 200
curl -sI http://APP_HOST/up | head -1          # expect 301 to https
```

Certbot edits only the server block whose `server_name` matches `-d`. Pixelforge does not
follow redirects, so the webhook URL must be the final `https://` one.

## 8. Queue worker under Supervisor

Create `/etc/supervisor/conf.d/promptedit-worker.conf`:

```ini
[program:promptedit-worker]
command=php /srv/promptedit/app/artisan queue:work --tries=1 --sleep=1 --max-time=3600
directory=/srv/promptedit/app
user=promptedit
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/srv/promptedit/app/storage/logs/worker.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
```

```bash
sudo supervisorctl reread
sudo supervisorctl update                       # starts only the new program
sudo supervisorctl status promptedit-worker     # expect RUNNING
```

Use `reread` + `update`, not `supervisorctl reload`: reload restarts every program on the
server. One worker is enough; job classes set their own retry rules.

## 9. Scheduler through cron

```bash
sudo crontab -u promptedit -e
```
```
* * * * * cd /srv/promptedit/app && php artisan schedule:run >> /dev/null 2>&1
```

Cron starts `schedule:run` once a minute; Laravel repeats the reconciler every 15 seconds
inside that minute. Check after two minutes:

```bash
sudo -u promptedit php /srv/promptedit/app/artisan schedule:list
```

## 10. Pixelforge webhook

In the Pixelforge dashboard, on the **production key**:

1. Webhook URL: `https://APP_HOST/api/webhooks/pixelforge`
2. Send a `ping` and check that the delivery shows response 200.
3. From any machine, a wrong signature must be refused:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://APP_HOST/api/webhooks/pixelforge -d '{}'   # expect 401
```

## 11. Verify

Log in as the demo user and run the matrix in [verify-live.md](verify-live.md). Then leave
a few finished generations on the site (different sizes and qualities, one with reference
images, one `[fail]`, one `[blocked]`) and nothing in flight.

Final check: `/admin/report` shows **Match** for every day.

## Updating

```bash
sudo -iu promptedit
cd /srv/promptedit/app
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart            # the worker finishes its job, then Supervisor starts a fresh one
```

Never run `migrate:fresh` or reset the production database after the first production
generation: usage stays on the Pixelforge key and the report would stop matching. If a
reset is unavoidable, create a new key and use it from then on.

## Removing this app (nothing else is touched)

```bash
sudo supervisorctl stop promptedit-worker && sudo rm /etc/supervisor/conf.d/promptedit-worker.conf && sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u promptedit -r
sudo rm /etc/nginx/sites-enabled/promptedit /etc/nginx/sites-available/promptedit && sudo nginx -t && sudo systemctl reload nginx
sudo certbot delete --cert-name APP_HOST
sudo rm /etc/php/8.3/fpm/pool.d/promptedit.conf && sudo systemctl reload php8.3-fpm
```

The database, the role and `/srv/promptedit` hold data: back up or delete them only on
purpose.
