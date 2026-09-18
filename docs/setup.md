# Setup

## Stack

| Component | Version / driver | Source |
| --- | --- | --- |
| PHP | `^8.3` required, `php:8.4-fpm` in Docker | `composer.json`, `docker/Dockerfile` |
| Laravel | `^13.17` | `composer.json` |
| Database | MySQL 8.4 | `docker-compose.yml` |
| Cache | Redis (`CACHE_STORE=redis`) | `.env.example`, `config/cache.php` |
| Queue | Redis (`QUEUE_CONNECTION=redis`) | `.env.example`, `config/queue.php` |
| Auth | Laravel Passport `^13.8`, `auth:api` guard driver `passport` | `config/auth.php` |
| API docs | Scribe `^5.11`, theme `scalar`, type `external_laravel` | `config/scribe.php` |
| Tests | PHPUnit `^12.5` (SQLite in-memory) | `phpunit.xml` |

## Quick start with Docker

```bash
cp .env.example .env
make setup
```

`make setup` (see `Makefile`) builds the images, starts the containers, and then runs, inside the
`app` container:

```
composer install
php artisan key:generate
php artisan migrate --force
php artisan passport:keys --force
php artisan passport:client --personal --name="Order Inventory Personal Access Client" --no-interaction
php artisan db:seed
php artisan scribe:generate
```

followed by permission fixes for `storage`, `bootstrap/cache`, `vendor`, and the Passport key files.

Services defined in `docker-compose.yml`:

| Service | Purpose | Host port |
| --- | --- | --- |
| `app` | PHP-FPM application container | — |
| `nginx` | HTTP entry point | `8080` → 80 |
| `queue` | Dedicated worker: `php artisan queue:work redis --sleep=1 --tries=3 --timeout=90` | — |
| `scheduler` | Runs `php artisan schedule:work` (prunes expired idempotency keys) | — |
| `mysql` | MySQL 8.4, database `order_inventory` | `3307` → 3306 |
| `redis` | Redis 7 (cache + queue) | `6380` → 6379 |

`mysql` and `redis` both declare health checks, and the application containers wait for both to
report healthy before starting.

API base URL: `http://localhost:8080/api/v1`.

### Scheduler

The `scheduler` container runs `php artisan schedule:work` automatically. To run it by hand
instead — for example outside Docker — use:

```bash
php artisan schedule:work
```

The only scheduled task (`routes/console.php`) prunes expired idempotency keys daily:

```php
Schedule::command('model:prune', ['--model' => [\App\Models\IdempotencyKey::class]])->daily();
```

`IdempotencyKey::prunable()` selects rows whose `expires_at` has passed (keys are stored with a
24-hour retention window by the middleware).

### Other make targets

```bash
make up              # start containers
make down            # stop containers
make restart         # down + up
make shell           # bash inside the app container
make migrate         # php artisan migrate
make fresh           # migrate:fresh --seed + recreate the personal access client
make seed            # php artisan db:seed
make test            # php artisan test
make docs            # php artisan scribe:generate
make queue           # run a queue worker in the foreground
make passport        # regenerate Passport keys/client and fix their permissions
make fix-permissions # re-apply storage/vendor/oauth key permissions
make logs            # docker compose logs -f
```

## Local setup without Docker

Requires PHP 8.3+, Composer, a MySQL 8 database, and Redis.

```bash
cp .env.example .env
composer install

# point .env at your local services
#   DB_HOST=127.0.0.1  DB_PORT=3306  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...
#   REDIS_HOST=127.0.0.1  REDIS_PORT=6379

php artisan key:generate
php artisan migrate

# Passport signing keys + a personal access client (required for token issuance)
php artisan passport:keys --force
php artisan passport:client --personal --name="Order Inventory Personal Access Client"

php artisan db:seed
php artisan scribe:generate
php artisan serve
```

Then, in separate terminals:

```bash
php artisan queue:work redis --sleep=1 --tries=3 --timeout=90   # mail + report-warming jobs
php artisan schedule:work                                        # prunes expired idempotency keys
```

If you run without Redis, set `CACHE_STORE=database` and `QUEUE_CONNECTION=database` — the cache
design does not depend on tag support (see [Caching](caching.md)).

## Environment configuration

Defaults in `.env.example` are Docker-oriented:

| Key | Default | Notes |
| --- | --- | --- |
| `APP_URL` | `http://localhost:8080` | Used as Scribe's `base_url` |
| `DB_CONNECTION` / `DB_HOST` | `mysql` / `mysql` | `mysql` is the compose service name |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `order_inventory` / `order_inventory` / `secret` | Matches the `mysql` service env |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | The `queue` container consumes this connection |
| `REDIS_HOST` / `REDIS_CLIENT` | `redis` / `phpredis` | phpredis is compiled into the image |
| `MAIL_MAILER` | `log` | Order status mail is written to the log by default |

---

[← Back to the README](../README.md)
