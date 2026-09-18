# Order Processing & Inventory API

A Laravel REST API for catalog management, stock control with reservations, idempotent order
processing, and order reporting. Built around three invariants:

1. stock can never be oversold, even under concurrent requests;
2. a retried write can never create a second order;
3. every balance change is recorded in an append-only ledger.

## Table of contents

- [Stack](#stack)
- [Quick start with Docker](#quick-start-with-docker)
- [Local setup without Docker](#local-setup-without-docker)
- [Environment configuration](#environment-configuration)
- [Seed data and demo credentials](#seed-data-and-demo-credentials)
- [Frontend console](#frontend-console)
- [Architecture](#architecture)
- [Database schema](#database-schema)
- [Order workflow and concurrency](#order-workflow-and-concurrency)
- [Idempotency](#idempotency)
- [Caching strategy and invalidation](#caching-strategy-and-invalidation)
- [Queues and events](#queues-and-events)
- [Database optimization and indexing](#database-optimization-and-indexing)
- [Authentication, authorization, rate limiting, errors](#authentication-authorization-rate-limiting-errors)
- [API documentation](#api-documentation)
- [Testing](#testing)
- [Key technical decisions](#key-technical-decisions)

---

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

---

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

---

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
design does not depend on tag support (see [Caching](#caching-strategy-and-invalidation)).

---

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

## Seed data and demo credentials

`php artisan db:seed` (run automatically by `make setup`) builds a **1.8 million row** dataset so the
API and console can be judged at realistic scale rather than on a handful of demo rows.

| Table | Rows |
| --- | --- |
| categories | 200 |
| customers | 80,000 |
| products | 120,000 |
| inventory_items | 120,000 (one per product) |
| orders | 150,000 |
| order_items | 322,459 |
| order_status_histories | 359,652 |
| inventory_movements | 651,526 |
| **Total** | **1,803,837** |

Plus one admin user — **`test@example.com` / `password`** (`is_admin = true`). Every seeded order is
created by that user, so `GET /orders` shows them immediately.

Runtime is roughly **90–120 seconds** at a peak of ~78 MB, using chunked bulk inserts
(`database/seeders/ChunkedInserter.php`) rather than Eloquent or Faker per row. The run is
deterministic — it seeds from a fixed PRNG seed, so counts are reproducible.

```bash
php artisan db:seed                    # full 1.8M row dataset
SEED_SCALE=0.01 php artisan db:seed    # ~18k rows, for a fast local loop
```

The seeder writes through the query builder, so none of the domain events that normally invalidate
the cache fire. It therefore flushes every cache namespace when it finishes — without that, the
console would keep serving the previous dataset from Redis.

### The seeded data is internally consistent

The seeder applies the same invariants the application enforces, so the numbers the console shows
are real rather than random:

- `inventory_items.quantity_on_hand` and `quantity_reserved` are the exact result of replaying the
  seeded orders against each product's opening stock: pending and confirmed orders hold a
  reservation, completed orders have decremented on-hand, cancelled orders have released theirs.
- `inventory_movements` records every one of those transitions with running `quantity_after` /
  `reserved_after` balances, so the ledger replays from zero to the stored balance.
- `SUM(orders.total_amount)` equals `SUM(order_items.line_total)`, and every line's
  `line_total = unit_price × quantity`.
- No child row predates its order, and no order references a product or customer created later.

---

## Frontend console

A Vue 3 single-page console ships with the API and exercises **every endpoint**. It is served at
`/` by `routes/web.php` and built with Vite.

```bash
npm install
npm run build      # production assets into public/build
npm run dev        # hot-reloading dev server while working on the UI
```

Sign in with the seeded account (`test@example.com` / `password`), or create a new one from the
same panel.

### Layout

```
resources/js/
  app.js                       mounts the app
  App.vue                      shell: auth gate, tab navigation, toasts
  api/client.js                fetch wrapper: bearer token, error envelope, Idempotency-Key
  composables/
    useAuth.js                 session state, register / login / logout
    useResourceList.js         filters, sorting, pagination for any list endpoint
    useToasts.js               global notification queue
  components/                  DataTable, FilterBar, PagerBar, DrawerPanel,
                               LoginPanel, StatusBadge, FieldErrors, ToastStack
  views/
    DashboardView.vue          report summary, low-availability stock, latest orders
    OrdersView.vue             list, place, transition, cancel, history
    InventoryView.vue          balances, adjustments, movement ledger
    ProductsView.vue           product CRUD
    CategoriesView.vue         category CRUD
    CustomersView.vue          customer CRUD
```

Because every list endpoint shares one contract — `search`, typed filters, `sort` with a `-` prefix
for descending, `page`, `per_page`, and a `meta` block — a single `useResourceList` composable and
one `DataTable` component drive all six lists.

### Endpoint coverage

| View | Endpoints used |
| --- | --- |
| Login panel | `POST /auth/register`, `POST /auth/login` |
| Shell | `POST /auth/logout` |
| Dashboard | `GET /orders/reports/summary`, `GET /inventory`, `GET /orders` |
| Orders | `GET /orders`, `POST /orders`, `GET /orders/{order}`, `GET /orders/{order}/history`, `PATCH /orders/{order}/status`, `POST /orders/{order}/cancel` |
| Inventory | `GET /inventory`, `POST /inventory/{product}/adjust`, `GET /inventory/{product}/movements` |
| Products | `GET`/`POST` `/products`, `GET`/`PUT`/`DELETE` `/products/{product}` |
| Categories | `GET`/`POST` `/categories`, `GET`/`PUT`/`DELETE` `/categories/{category}` |
| Customers | `GET`/`POST` `/customers`, `GET`/`PUT`/`DELETE` `/customers/{customer}` |

That is all 28 distinct endpoints. (`PUT` and `PATCH` on the three CRUD resources are the same
route and controller action, so the console uses `PUT`.)

### How the console surfaces the domain rules

- **Reservations are visible.** Inventory lists on-hand, reserved and available side by side, and
  the product picker in the order form shows available stock per product, so the difference between
  physical and sellable stock is obvious.
- **Idempotency is explicit.** The order form generates one `Idempotency-Key` when it opens and
  reuses it for every retry of that submission; the key is displayed in the form. A replayed
  response is detected through the `Idempotent-Replay` header and reported as such rather than being
  passed off as a new order.
- **The state machine drives the UI.** The order drawer only offers transitions the workflow
  allows — `confirmed` from pending, `completed` from confirmed — and cancellation always goes
  through the cancel endpoint, so stock is released.
- **Domain errors are rendered, not swallowed.** A 409 insufficient-stock response lists the exact
  per-product shortfall (requested vs available); 422 responses map field errors back to their
  inputs.

## Architecture

### Layering

```
routes/api/*.php
  └─ throttle + auth:api (+ idempotent on POST /orders)
       └─ Form Request           app/Http/Requests/**        validation, per_page bounds, Scribe params
            └─ Controller        app/Http/Controllers/**     thin: DTO in, resource out, policy checks
                 └─ Action       app/Actions/**              one write use case, owns its transaction
                 └─ Service      app/Services/**             InventoryLedger, OrderReportService
                      └─ Model   app/Models/**               relations, casts, scopes, filters
       └─ API Resource           app/Http/Resources/**       output shape
       └─ ApiResponse            app/Support/ApiResponse.php envelope
```

- **Form Requests** (`app/Http/Requests`) hold every validation rule. `IndexRequest` centralises
  pagination (`DEFAULT_PER_PAGE = 15`, `MAX_PER_PAGE = 100`) and the Scribe `per_page` docs.
- **DTOs** (`app/Data`) convert validated arrays into typed objects — e.g. `ProductData` parses the
  dollar price into `priceCents`, `CreateOrderData` maps items into `OrderItemData` objects,
  `InventoryAdjustmentData` resolves the movement type enum.
- **Actions** (`app/Actions`) own write use cases and their transaction boundaries:
  `CreateProductAction`, `AdjustInventoryAction`, `CreateOrderAction`, `UpdateOrderStatusAction`,
  `CancelOrderAction`, `IssuePassportTokenAction`.
- **Services**: `InventoryLedger` (stock writer), `OrderReportService` (cached aggregate report).
- **QueryFilters** (`app/QueryFilters`) turn validated query strings into builder constraints.
  `QueryFilter` implements search, whitelisted exact filters, money ranges (dollars normalised via
  `Money`), numeric ranges, `created_at` ranges, and whitelisted sorting with a `-` prefix for
  descending. Models opt in through the `HasFilters` trait and `scopeFilter()`.
- **Resources** (`app/Http/Resources`) define the public JSON shape and emit money as dollar strings.
- **ApiResponse** (`app/Support/ApiResponse.php`) builds every envelope. `paginatedPayload()`
  returns a plain array so a cached page can be stored in any cache driver and replayed through
  `fromPayload()`.

### InventoryLedger is the single writer for stock

`app/Services/InventoryLedger.php` is the only place `quantity_on_hand` / `quantity_reserved` are
mutated after a product exists, and every mutation appends exactly one `inventory_movements` row
(`record()`), so balances can be reconciled by replaying the ledger.

| Method | Effect | Movement type |
| --- | --- | --- |
| `lockFor(array $productIds)` | `SELECT … WHERE product_id IN (…) ORDER BY product_id FOR UPDATE` | — |
| `lockOrCreateFor(string $productId)` | creates a missing balance row, then re-reads it locked | — |
| `reserve()` | `quantity_reserved += n` | `order_reserved` |
| `release()` | `quantity_reserved -= min(n, reserved)` | `reservation_released` |
| `fulfil()` | `quantity_on_hand -= min(n, on_hand)`, `quantity_reserved -= min(n, reserved)` | `order_fulfilled` |
| `adjust()` | signed `quantity_on_hand` change | `restock` / `correction` |

The ledger never opens a transaction — callers do, so the lock scope is always visible at the call
site. The one balance write outside the ledger is the **opening row** created alongside a product in
`CreateProductAction` (`quantity_on_hand = stock_quantity`, `quantity_reserved = 0`).

---

## Database schema

### ER overview

```
users ──< orders (created_by / updated_by / cancelled_by, char(26))
users ──< idempotency_keys (user_id, FK, cascade)

categories 1 ──< products
products   1 ──1 inventory_items          (unique product_id)
products   1 ──< inventory_movements
products   1 ──< order_items              (restrict on delete)

customers  1 ──< orders
orders     1 ──< order_items              (cascade)
orders     1 ──< order_status_histories   (cascade)
orders     1 ──< inventory_movements      (nullable, null on delete)
```

### Conventions

- **ULID primary keys.** `App\Database\Blueprint::ulid()` adds a `char(26)` `id` and marks it
  primary; models use `HasUlids`. `Schema::defaultMorphKeyType('ulid')` is set in
  `AppServiceProvider`. ULIDs are lexicographically time-ordered, so `ORDER BY id` is a stable
  tiebreaker (used by the order history endpoint) and ids can be generated client-side without a
  round trip.
- **`actionAt()` / `actionBy()` blueprint macros.** Defined on `App\Database\Blueprint` (registered
  as the schema blueprint resolver) and mirrored as `Blueprint::macro()`s in `AppServiceProvider`
  for callers that use the framework blueprint:
  - `actionAt()` → nullable `dateTime` `created_at` + `updated_at`; `actionAt('cancelled')` →
    nullable `cancelled_at`.
  - `actionBy()` → nullable indexed `char(26)` `created_by` + `updated_by`; `actionBy('cancelled')`
    → nullable indexed `cancelled_by`.
  `dateTime` is used instead of `timestamp` to avoid the 2038 range limit and implicit
  `ON UPDATE CURRENT_TIMESTAMP` behaviour.
- **Audit columns are filled automatically.** `App\Models\BaseModel` hooks `creating`/`updating` and
  writes `created_by` / `updated_by` from `Auth::id()` when the column exists (existence is cached
  per table), so actions never pass routine audit fields.
- **Money.** Columns are `decimal(12,2)` **dollars**; PHP works in **integer cents**.
  `App\Support\Money::dollarsToCents()` / `centsToDollars()` do the conversion, and
  `Product::price`, `Order::total_amount`, `OrderItem::unit_price`, `OrderItem::line_total` are
  Eloquent `Attribute` casts that expose cents to PHP and store dollar strings. Requests and
  responses use dollar strings such as `"125.00"`.

### Tables

#### `categories`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK, ULID |
| `name` | string | indexed |
| `slug` | string | unique |
| `status` | string | default `active`, indexed (`active` / `archived`) |
| `created_at`, `updated_at` | dateTime null | `actionAt()` |
| `created_by`, `updated_by` | char(26) null | `actionBy()`, both indexed |

Indexes: `unique(slug)`, `index(status)`, `index(name)`, `index(created_at)`, `index(created_by)`,
`index(updated_by)`. Relations: `hasMany(Product)`.

#### `products`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `category_id` | char(26) | FK → `categories.id`, cascade delete |
| `name` | string | |
| `sku` | string | unique |
| `description` | text null | |
| `price` | decimal(12,2) | dollars |
| `status` | string | default `active`, indexed (`active` / `draft` / `archived`) |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `unique(sku)`, `index(status)`, `index(category_id, status)`, `index(status, created_at)`,
`index(created_at)`, `index(price)`, plus the FK index on `category_id` and the audit-column
indexes. Relations: `belongsTo(Category)`, `hasOne(InventoryItem)`, `hasMany(InventoryMovement)`.

#### `inventory_items`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `product_id` | char(26) | FK → `products.id`, cascade delete, **unique** (one balance per product) |
| `quantity_on_hand` | unsignedInteger | default 0 |
| `quantity_reserved` | unsignedInteger | default 0 |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `unique(product_id)`, `index(quantity_on_hand)`, `index(quantity_reserved)`,
`index(created_at)`, audit indexes. `available_quantity` is **derived**, not stored:
`max(0, quantity_on_hand - quantity_reserved)` (`InventoryItem::availableQuantity()`), with the SQL
form `(quantity_on_hand - quantity_reserved)` exposed as `InventoryItem::AVAILABLE_EXPRESSION` for
filtering and sorting.

#### `customers`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `name` | string | indexed |
| `email` | string | unique |
| `phone` | string null | |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `unique(email)`, `index(name)`, `index(created_at)`, audit indexes.
Relations: `hasMany(Order)`.

#### `orders`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `customer_id` | char(26) | FK → `customers.id`, cascade delete |
| `order_number` | string | unique, `ORD-` + 12 random upper-case chars |
| `status` | string | default `pending`, indexed; cast to `OrderStatus` |
| `total_amount` | decimal(12,2) | default 0, dollars |
| `cancelled_at` | dateTime null | `actionAt('cancelled')` |
| `cancelled_by` | char(26) null | `actionBy('cancelled')`, indexed |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `unique(order_number)`, `index(status)`, `index(customer_id, status)`,
`index(created_at, status)`, `index(total_amount)`, `index(created_by, created_at)`, plus the FK
index on `customer_id` and the audit indexes. Relations: `belongsTo(Customer)`,
`hasMany(OrderItem)`, `hasMany(OrderStatusHistory)`, `hasMany(InventoryMovement)`.
`Order::scopeVisibleTo(User)` restricts non-admins to `created_by = user.id`.

#### `order_items`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `order_id` | char(26) | FK → `orders.id`, cascade delete |
| `product_id` | char(26) | FK → `products.id`, **restrict** on delete (sold history is immutable) |
| `quantity` | unsignedInteger | |
| `unit_price` | decimal(12,2) | price captured at order time |
| `line_total` | decimal(12,2) | `unit_price × quantity` |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `index(product_id, created_at)`, FK indexes, audit indexes.

#### `inventory_movements`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `product_id` | char(26) | FK → `products.id`, cascade delete |
| `order_id` | char(26) null | FK → `orders.id`, null on delete (null for manual adjustments) |
| `type` | string | indexed; `InventoryMovementType` enum |
| `quantity_delta` | integer | signed change to on-hand |
| `quantity_after` | unsignedInteger | on-hand balance after the change |
| `reserved_delta` | integer | signed change to reserved, default 0 |
| `reserved_after` | unsignedInteger | reserved balance after the change, default 0 |
| audit | | `actionAt()`, `actionBy()` |

Indexes: `index(type)`, `index(product_id, created_at)`, FK indexes, audit indexes.
Movement types: `order_reserved`, `reservation_released`, `order_fulfilled`, `restock`,
`correction`. Only `restock` and `correction` (`InventoryMovementType::manualValues()`) may be
submitted by a client; the rest are written by the order workflow.

#### `order_status_histories`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `order_id` | char(26) | FK → `orders.id`, cascade delete |
| `from_status` | string null | null for the creation entry |
| `to_status` | string | indexed |
| `note` | text null | client-supplied or system note |
| audit | | `actionAt()`, `actionBy()` (`created_by` records who made the transition) |

#### `idempotency_keys`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | PK |
| `user_id` | char(26) | FK → `users.id`, cascade delete |
| `key` | string | client-supplied `Idempotency-Key` |
| `method`, `path` | string | request identity, for diagnostics |
| `request_hash` | char(64) | SHA-256 fingerprint of method + path + canonicalised body |
| `state` | string | `processing` (claimed) or `completed` (replayable) |
| `response_payload` | json null | stored response body |
| `response_status` | unsignedSmallInteger null | stored HTTP status |
| `expires_at` | dateTime null | indexed; now + 24h |
| audit | | `actionAt()`, `actionBy()` |

Indexes: **`unique(user_id, key)`** (the arbiter of concurrent duplicates), `index(expires_at)`
(used by the prune query), FK index, audit indexes.

#### Framework tables

`users` (ULID `id`, `name`, unique `email`, `email_verified_at`, `password`, `is_admin` boolean
default false, `remember_token`, `created_at`, `updated_at`), `password_reset_tokens`, `sessions`,
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, and the Passport `oauth_*` tables.

---

## Order workflow and concurrency

### State machine

`App\Enums\OrderStatus` is the single source of truth (`allowedTransitions()`, `canTransitionTo()`,
`isTerminal()`, `holdsReservation()`):

```
            ┌───────────┐  status: confirmed   ┌────────────┐  status: completed  ┌────────────┐
            │  pending  │ ───────────────────► │ confirmed  │ ──────────────────► │ completed  │ (terminal)
            └─────┬─────┘                      └──────┬─────┘                     └────────────┘
                  │  POST /cancel                     │  POST /cancel
                  └──────────────┬────────────────────┘
                                 ▼
                          ┌────────────┐
                          │ cancelled  │ (terminal)
                          └────────────┘
```

Any other move (skipping `confirmed`, reversing, or leaving a terminal state) raises
`InvalidOrderTransitionException` → HTTP 422 including the allowed transitions.

### Reservation model

```
available = quantity_on_hand - quantity_reserved   (never below 0)
```

Only `available` may be drawn from by a new order.

| Transition | `quantity_on_hand` | `quantity_reserved` | Ledger entry |
| --- | --- | --- | --- |
| create order (`→ pending`) | unchanged | `+ qty` | `order_reserved` |
| `pending → confirmed` | unchanged | unchanged | none |
| `confirmed → completed` | `− qty` | `− qty` | `order_fulfilled` |
| `pending/confirmed → cancelled` | unchanged | `− qty` | `reservation_released` |
| manual adjust (`restock` / `correction`) | `± qty` | unchanged | `restock` / `correction` |

Physical stock only leaves the warehouse at completion; until then the units are held, not removed,
so a cancellation is a pure release.

### How overselling is prevented

`App\Actions\Orders\CreateOrderAction::execute()`:

1. Opens `DB::transaction(..., attempts: 3)` — three retries for deadlock victims.
2. Consolidates duplicate lines for the same product, so a product appears once.
3. `InventoryLedger::lockFor()` takes `lockForUpdate()` on **all** inventory rows in one statement,
   with `sort($productIds)` and `ORDER BY product_id`, so concurrent orders acquire the same rows in
   the same order and queue instead of deadlocking.
4. **Only then** is availability checked (`assertStockIsAvailable()`), against the freshly locked
   rows. A concurrent order that was blocked on the lock re-reads the balance after the first
   transaction commits, so it sees the reservation the winner made. Shortfalls raise
   `InsufficientStockException` → HTTP 409 with per-product `requested` / `available` detail.
5. Order, line items, reservations, and the initial `order_status_histories` row are written while
   the locks are still held; the locks release only at commit.
6. `OrderCreated` is dispatched **after** the transaction so listeners never act on rolled-back state.

`StoreOrderRequest` caps a payload at 100 line items, bounding how many row locks one transaction
can hold.

### Double-cancel and lost-stock protection

- `CancelOrderAction` re-reads the order **inside** the transaction with
  `Order::query()->whereKey(...)->lockForUpdate()->firstOrFail()` rather than trusting the
  route-resolved model. If the locked row is already `cancelled` it returns a no-op (no release, no
  event), so two concurrent cancels cannot release the same reservation twice and inflate stock.
  `InventoryLedger::release()` additionally clamps with `min($quantity, $item->quantity_reserved)`.
- `UpdateOrderStatusAction` performs the same locked re-read before evaluating the transition guard,
  so two concurrent transitions cannot both pass it.
- `PATCH /orders/{order}/status` **refuses `cancelled`**: `UpdateOrderStatusRequest` validates
  against `OrderStatus::clientTransitionableValues()` (all values except `cancelled`). Cancellation
  is only reachable through `POST /orders/{order}/cancel`, which releases the reservation — so an
  order can never reach a terminal state with its stock still held.
- `AdjustInventoryAction` rejects any adjustment that would leave `quantity_on_hand` below
  `quantity_reserved` (HTTP 422), so reserved units always remain backed by physical stock.

---

## Idempotency

`App\Http\Middleware\EnsureIdempotentRequest` (aliased `idempotent` in `bootstrap/app.php`) is
applied to `POST /orders`.

**Claim-first design.** The key row is inserted *before* the request runs, so the unique index on
`(user_id, key)` — not a read-then-write check — arbitrates concurrent duplicates. The loser of the
insert race never reaches the controller.

```
Idempotency-Key header missing/empty or > 255 chars  → 422
INSERT idempotency_keys (state=processing, expires_at=now+24h)
├─ success  → run request (the response always carries Idempotent-Replay: false)
│              ├─ 2xx        → store payload + status, state=completed
│              ├─ non-2xx    → delete the claim (client may retry with the same key)
│              └─ exception  → delete the claim, rethrow
└─ unique violation → look up the existing row
       ├─ row gone (winner released)          → 409 + Retry-After: 1
       ├─ request_hash differs (hash_equals)  → 409 "already used with a different request payload" (logged as a warning)
       ├─ state != completed / no payload     → 409 + Retry-After: 1 (in flight)
       └─ completed                           → stored body + stored status, header Idempotent-Replay: true
```

- **Fingerprint:** SHA-256 of `{method, path, body}` where associative keys are sorted recursively.
  List order is preserved and therefore significant — the same items in a different order count as a
  different payload.
- **Replay is verbatim:** the stored JSON body and status code are returned unchanged.
- **Failures release the claim**, so a client can correct a 422 payload and retry with the same key.
- **Retention:** 24 hours (`expires_at`), pruned daily by the scheduled `model:prune` command.

---

## Caching strategy and invalidation

### Version-stamped namespaces

`App\Support\CacheRepository` with `App\Support\CacheNamespace` (`products`, `categories`,
`inventory`, `customers`, `reports`).

```
key    = "<namespace>:v<version>:<xxh128 hash of ksorted parameters>"
version = cache value of "cache-version:<namespace>", default 1
flush   = add("cache-version:<ns>", 2) or, if it already exists, increment it
```

Bumping the version orphans every key written under the previous version in **one** operation;
orphans expire on their own TTL.

**Why version stamping instead of cache tags**

- Works on **any** driver (Redis, database, file, array in tests) — tags require a taggable store.
- Group invalidation is a single `INCR`, independent of how many filter/sort/page variants exist.
- No key enumeration, no `KEYS`/`SCAN` pattern deletes on a hot Redis instance.
- Concurrency-safe: readers building a key under version N never see a half-cleared group.

### What is cached

| Endpoint | Namespace | Cache key parameters |
| --- | --- | --- |
| `GET /products` | `products` | `view=products.index`, `page`, `per_page`, all validated filters |
| `GET /products/{id}` | `products` | `view=products.show`, `id` |
| `GET /categories` | `categories` | `view=categories.index`, `page`, `per_page`, filters |
| `GET /categories/{id}` | `categories` | `view=categories.show`, `id` |
| `GET /customers` | `customers` | `view=customers.index`, `page`, `per_page`, filters |
| `GET /customers/{id}` | `customers` | `view=customers.show`, `id` |
| `GET /inventory` | `inventory` | `view=inventory.index`, `page`, `per_page`, filters |
| `GET /orders/reports/summary` | `reports` | `report=orders.summary`, `status`, `from`, `to` |

TTL: `CacheRepository::DEFAULT_TTL = 300` seconds for every entry (no call site overrides it).
Only the current page is serialised (`ApiResponse::paginatedPayload`), so an entry is bounded by
`per_page`, not by table size.

Deliberately **not** cached: `GET /orders`, `GET /orders/{id}`, `GET /orders/{id}/history`, and
`GET /inventory/{product}/movements` — they are owner-scoped or append-only reads where staleness is
more expensive than the query.

### Invalidation matrix

| Event | Dispatched from | Listener | Namespaces flushed |
| --- | --- | --- | --- |
| `CatalogChanged` | `CreateProductAction`, `ProductController@update/destroy`, `CategoryController@store/update/destroy` | `FlushCatalogCaches` | `products`, `categories` |
| `InventoryChanged` | `CreateProductAction`, `AdjustInventoryAction` | `FlushInventoryCaches` | `inventory`, `products` |
| `CustomerChanged` | `CustomerController@store/update/destroy` | `FlushCustomerCaches` | `customers` |
| `OrderCreated` / `OrderStatusChanged` / `OrderCancelled` | `CreateOrderAction`, `UpdateOrderStatusAction`, `CancelOrderAction` | `FlushOrderCaches` | `reports`, `inventory`, `products`, `customers` |

The cross-namespace flushes are intentional: product payloads embed stock balances and sales
metrics, category payloads carry `products_count`, and customer payloads carry order totals — so an
order write invalidates all four.

**Invalidation is synchronous; side effects are queued.** `FlushCatalogCaches`,
`FlushInventoryCaches`, `FlushCustomerCaches`, and `FlushOrderCaches` are plain listeners (no
`ShouldQueue`): deferring a flush to a worker would leave a window in which reads are served from a
cache the committed write has already contradicted. Work that is *not* required for correctness of
the next read — the customer email (`SendOrderStatusNotification`) and re-warming the report
(`RefreshOrderReportCache`) — is queued so mail latency and recomputation stay off the request path.

---

## Queues and events

**Events** (`app/Events`): `Catalog\CatalogChanged` (entity + id), `Inventory\InventoryChanged`
(product ids), `Customers\CustomerChanged` (customer id), `Orders\OrderCreated`,
`Orders\OrderStatusChanged` (order + from + to), `Orders\OrderCancelled`.

**Listeners** (`app/Listeners`): `FlushCatalogCaches`, `FlushInventoryCaches`,
`FlushCustomerCaches`, `FlushOrderCaches`, `SendOrderStatusNotification`, `SyncOrderAnalytics`.

Listeners are **not** registered in `AppServiceProvider` — Laravel discovers them from the
type-hint on each `handle()` method (the union types on the order listeners subscribe them to all
three order events at once). Registering them again would fire each listener twice.

**Queued work** (Redis connection, consumed by the `queue` container):

| Job / listener | Trait | Settings |
| --- | --- | --- |
| `SendOrderStatusNotification` | `implements ShouldQueue` | `tries = 3`, `backoff = 10`; sends `OrderStatusMail` (markdown `mail.orders.status`) to the customer; skips and logs a warning when the customer has no email; `failed()` logs the exception |
| `RefreshOrderReportCache` | `implements ShouldQueue, ShouldBeUnique` | `uniqueFor = 60`, `tries = 3`; flushes the `reports` namespace and recomputes the unfiltered summary. Uniqueness collapses a burst of orders into one recomputation |

`SyncOrderAnalytics` runs synchronously but dispatches `RefreshOrderReportCache` with
`->afterCommit()`, so the job never runs against an uncommitted transaction.

---

## Database optimization and indexing

### Index → query mapping

| Index | Query it serves |
| --- | --- |
| `products(category_id, status)` | `GET /products?category_id=…&status=…` — the composite filter, without touching the table for the status test |
| `products(status, created_at)` | the default list (`status=active` sorted by `-created_at`): filter and sort from one index |
| `products(created_at)` | unfiltered default sort and the `from`/`to` date range |
| `products(price)` | `min_price` / `max_price` range filters and `sort=price` |
| `products(sku)` unique | SKU lookups and the uniqueness rule on create/update |
| `categories(slug)` unique / `(name)` / `(status)` / `(created_at)` | slug uniqueness, name search and `sort=name`, status filter, date range and default sort |
| `inventory_items(product_id)` unique | one balance per product; the lock-and-read path `WHERE product_id IN (…) FOR UPDATE` and the `product_id` filter |
| `inventory_items(quantity_on_hand)` / `(quantity_reserved)` | `min_/max_quantity_on_hand`, `min_/max_quantity_reserved`, and sorting by either balance |
| `inventory_items(created_at)` | default `-created_at` sort and the date range |
| `customers(email)` unique / `(name)` / `(created_at)` | email uniqueness, name sort, default sort and date range |
| `orders(customer_id, status)` | `GET /orders?customer_id=…&status=…` |
| `orders(created_at, status)` | the report's `from`/`to` + `status` aggregate scan, and the default date-ordered list |
| `orders(created_by, created_at)` | `scopeVisibleTo()` — a non-admin's own orders in date order, which is *the* hot list query |
| `orders(total_amount)` | `min_total` / `max_total` and `sort=total_amount` |
| `orders(order_number)` unique / `(status)` | order-number lookup/search, plain status filter |
| `order_items(product_id, created_at)` | per-product sales history and the `withSalesMetrics` aggregate |
| `inventory_movements(product_id, created_at)` | `GET /inventory/{product}/movements` (newest first) |
| `inventory_movements(type)` | the `type` filter on the ledger endpoint |
| `order_status_histories(to_status)` | filtering/reporting on the status a transition landed on |
| `idempotency_keys(user_id, key)` unique | the claim insert that arbitrates duplicate requests |
| `idempotency_keys(expires_at)` | the nightly prune (`WHERE expires_at <= now()`) |
| `*(created_by)`, `*(updated_by)`, `orders(cancelled_by)` | "what did this actor touch" audit lookups (created by `actionBy()`) |

### N+1 avoidance

- `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider` turns an accidental
  lazy load into an exception in dev/test instead of silent per-row queries in production.
- Every list endpoint eager-loads what its resource reads: products
  `with(['category' => fn ($q) => $q->withCount('products'), 'inventory'])`, orders
  `with(['customer', 'items.product'])->withCount('items')`, inventory `with('product')`, categories
  `withCount('products')`.
- `CategoryResource` uses `whenCounted('products')`, so `products_count` is emitted only when it was
  eager-counted and never triggers a `COUNT` per row. `ProductResource` reads the inventory relation
  through `relationLoaded()` once per row; `whenLoaded()` guards every nested relation.
- Aggregates come from joined subqueries, not per-row queries: `Product::scopeWithSalesMetrics()`
  and `Customer::scopeWithOrderMetrics()` build a CTE with a `DENSE_RANK()` window function and
  `leftJoinSub` it, so totals and ranks for a whole page cost one extra join.

### Bounded pagination

`IndexRequest` applies `['nullable','integer','min:1','max:100']` to `per_page` on every list
endpoint, defaulting to 15. A client cannot request an unbounded page, so query cost, response size,
and cache entry size all stay bounded.

### Sargable date filters

`QueryFilter::applyCreatedAtRange()` and `OrderReportService::baseQuery()` expand `from`/`to` to
`startOfDay()` / `endOfDay()` timestamps and compare against the **bare** `created_at` column:

```php
$query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay());
```

Wrapping the column in `DATE(created_at) = ?` would make it a function expression and render
`orders(created_at, status)` / `products(status, created_at)` unusable. Bounds are also passed as
bindings, never interpolated.

### Single-query report

`OrderReportService::compute()` produces all seven figures from one pass over the filtered rows
using conditional aggregates:

```sql
COUNT(*)                                                   AS orders_count,
COALESCE(SUM(CASE WHEN status != ? THEN total_amount END),0) AS total_sales,
SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                AS pending_count,
… confirmed_count, completed_count, cancelled_count
```

Adding a counter costs an extra `CASE`, not an extra query. Average order value is computed in PHP
with `intdiv()` on cents. The whole result is cached in the `reports` namespace.

### Other query hygiene

- Sorting is whitelisted in both the Form Request (`Rule::in`) and the filter class
  (`$sortableColumns`), falling back to `-created_at`; derived sorts (`category_name`, `stock`,
  `available_stock`, `product_title`, `customer_name`) use correlated `limit(1)` subqueries or the
  shared `AVAILABLE_EXPRESSION` rather than joins that would duplicate rows.
- Raw fragments (`whereRaw`, `selectRaw`) always use `?` bindings.
- Multi-step writes run inside `DB::transaction()` with `attempts = 3` on every locking action.

---

### Keeping list endpoints fast at 1.8M rows

Products expose global aggregates — `units_sold`, `orders_count`, `gross_sales`, `sales_rank`.
Deriving them per request was the worst query in the application: a `GROUP BY` over every
`order_items` row, joined to all 120,000 products, then sorted. No index helps, because the sort
column does not exist until the join has run. On the seeded dataset a metric sort cost ~2.7 s of
SQL (1.4 s for the page, 1.3 s for the paginator's `COUNT(*)`, which repeated the whole join to
produce a number the join cannot change).

Three changes fixed it:

1. **Materialised the aggregates.** `product_sales_metrics` holds one row per product, with an
   index on each sortable column. Sorting by `gross_sales` is now an index range scan, not a
   filesort over a join.
2. **Made the join inner.** Every product is guaranteed a metrics row, so an inner join returns the
   same rows as a left join — but it frees the optimiser to drive from the sorted metric index and
   read fifteen rows instead of sorting the catalog. That one change took the query from 256 ms to
   1 ms.
3. **Suppressed the redundant count.** No filter touches a metric column, so the total is taken
   from the unjoined query and passed to `paginate()`, which skips the count query entirely.

**Keeping the table correct.** Totals are exact and updated synchronously, because they depend only
on `order_items`, which are written once when an order is created and never change:
`UpdateProductSalesMetrics` folds each new order into the running totals on the write path. The
*rank* is different — one sale can move every other product — so it is recomputed by the queued
`RefreshOrderReportCache` job, which is `ShouldBeUnique` and therefore collapses a burst of orders
into a single pass. Rank can trail the totals by up to a minute; the totals never do.

Every product gets its metrics row from a `created` model event, so the invariant holds for
factories and console scripts as well as the API. Bulk inserts that bypass Eloquent — the seeder,
or any import — are covered by:

```bash
php artisan metrics:rebuild      # ~4s for 120,000 products
```

Measured on the seeded 1.8M row dataset, cold cache, through nginx + php-fpm:

| Request | Before | After |
| --- | --- | --- |
| `GET /products?sort=gross_sales` | ~2.7 s | **0.08 s** |
| `GET /products?sort=sales_rank` | ~2.7 s | **0.04 s** |
| `GET /products` (default sort) | 5.52 s | **0.05 s** |
| `GET /products?search=…` | 1.30 s | 0.13 s |
| `GET /products?page=4000` | — | 0.12 s |
| `GET /orders`, `/inventory`, `/reports/summary` | — | 0.07–0.13 s |
| any of the above, warm cache | — | 0.03 s |

**Known remaining cost.** Customer metrics (`total_order_amount`, `customer_value_rank`) are still
derived per request and cost ~0.6 s cold. They were left as-is because they depend on order
*status* as well as order creation, so keeping them materialised needs incremental updates on
status transitions too. The same treatment would apply.

---

## Authentication, authorization, rate limiting, errors

### Authentication

Laravel Passport. The `api` guard uses the `passport` driver (`config/auth.php`), and protected
routes use `auth:api`. `POST /auth/register` and `POST /auth/login` issue a personal access token
through `IssuePassportTokenAction` (`$user->createToken(...)`); `POST /auth/logout` revokes the
current token. Send the token as:

```http
Authorization: Bearer <access_token>
```

A personal access client must exist — `make setup` / `make passport` creates it.

### Authorization

`App\Policies\OrderPolicy` — `view`, `update`, `cancel` all resolve to `owns()`:

```php
return $user->is_admin || $order->created_by === $user->id;
```

Orders are owned by the API client that created them; admins (`users.is_admin`) reach every order.
The same rule is enforced at the query level by `Order::scopeVisibleTo()` on `GET /orders`, so a
list can never leak another client's orders even if a policy check were missed. Catalog data
(products, categories, customers) is deliberately shared business data and has no ownership policy.

### Rate limiting

Defined in `AppServiceProvider::registerRateLimiters()`:

| Limiter | Limit | Keyed by | Applied to |
| --- | --- | --- | --- |
| `api` | 120 / minute | user id, falling back to IP | every API route (appended in `bootstrap/app.php`) |
| `orders` | 30 / minute | user id, falling back to IP | `POST /orders`, `PATCH /orders/{order}/status`, `POST /orders/{order}/cancel`, `POST /inventory/{product}/adjust` |
| `auth` | 20 / minute | IP (no user yet) | `POST /auth/register`, `POST /auth/login` |

Write paths take inventory row locks, hence the tighter budget.

### Error handling

Every API error uses one envelope (`ApiResponse::error`), configured in `bootstrap/app.php`:

```json
{ "success": false, "message": "…", "errors": null }
```

| Situation | Status | Message |
| --- | --- | --- |
| `ValidationException` | 422 | `The given data was invalid.` + field errors |
| `AuthenticationException` | 401 | `Unauthenticated.` |
| `AuthorizationException` | 403 | `This action is unauthorized.` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `Resource not found.` |
| `ThrottleRequestsException` | 429 | `Too many requests.` (rate-limit headers preserved) |
| `InsufficientStockException` | 409 | `Insufficient stock for one or more products.` + `errors.items[]` |
| `IdempotencyConflictException` | 409 | key reused with a different payload |
| `IdempotentRequestInFlightException` | 409 | duplicate still processing, with `Retry-After: 1` |
| `InvalidOrderTransitionException` | 422 | `An order cannot move from X to Y.` + `allowed_transitions` |
| Anything else | 500 | `An unexpected server error occurred.` (the real message only when `APP_DEBUG`) |

The 500 catch-all logs `exception`, `message`, `method`, `path`, and `user_id` through
`Log::error()`, so a fault is never swallowed silently. An idempotency key reused with a different
payload is logged with `Log::warning()`.

---

## API documentation

Scribe generates the reference from the annotations on every controller method
(`@group`, `@authenticated`, `@response`, plus `bodyParameters()` / `queryParameters()` on the Form
Requests). Regenerate with `php artisan scribe:generate` (or `make docs`). With
`laravel.add_routes = true` and `docs_url = /docs`:

- HTML docs (Scalar theme): `http://localhost:8080/docs`
- OpenAPI spec: `http://localhost:8080/docs.openapi`
- Postman collection: `http://localhost:8080/docs.postman`

### Endpoint map

| Method | Path (prefix `/api/v1`) | Notes |
| --- | --- | --- |
| POST | `/auth/register` | public, `throttle:auth` |
| POST | `/auth/login` | public, `throttle:auth` |
| POST | `/auth/logout` | `auth:api` |
| GET/POST | `/categories` | cached index |
| GET/PUT/PATCH/DELETE | `/categories/{category}` | cached show |
| GET/POST | `/products` | cached index |
| GET/PUT/PATCH/DELETE | `/products/{product}` | cached show |
| GET | `/inventory` | cached index |
| GET | `/inventory/{product}/movements` | ledger, newest first |
| POST | `/inventory/{product}/adjust` | `throttle:orders` |
| GET/POST | `/customers` | cached index |
| GET/PUT/PATCH/DELETE | `/customers/{customer}` | cached show |
| GET | `/orders` | owner-scoped |
| POST | `/orders` | `throttle:orders` + `Idempotency-Key` required |
| GET | `/orders/{order}` | policy `view` |
| PATCH | `/orders/{order}/status` | `throttle:orders`, policy `update`, `cancelled` rejected |
| POST | `/orders/{order}/cancel` | `throttle:orders`, policy `cancel` |
| GET | `/orders/{order}/history` | policy `view` |
| GET | `/orders/reports/summary` | cached aggregate |

All responses use `{"success": bool, "message": string, "data": …}`; list responses add `meta`
(`current_page`, `per_page`, `total`, `last_page`, `from`, `to`). Money is always a dollar string.

### 1. Register

```bash
curl -X POST http://localhost:8080/api/v1/auth/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "name": "Muhammad AR Zihad",
    "email": "muhammad@example.com",
    "password": "password-secret",
    "password_confirmation": "password-secret"
  }'
```

`201 Created`

```json
{
  "success": true,
  "message": "Registration successful.",
  "data": {
    "user": {
      "id": "01m2swhmj7dps93xge6qjdzjad",
      "name": "Muhammad AR Zihad",
      "email": "muhammad@example.com"
    },
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjhhM2M...",
    "token_type": "Bearer"
  }
}
```

### 2. Login

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email": "test@example.com", "password": "password"}'
```

`200 OK`

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": { "id": "01m2swhmj7dps93xge6qjdzjad", "name": "Test User", "email": "test@example.com" },
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjhhM2M...",
    "token_type": "Bearer"
  }
}
```

Wrong credentials return `422` with
`{"success": false, "message": "Invalid credentials.", "errors": {"email": ["The provided credentials are incorrect."]}}`.
Every call below assumes `-H 'Authorization: Bearer $TOKEN'`.

### 3. Create a product

```bash
curl -X POST http://localhost:8080/api/v1/products \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "category_id": "01m2swhmj7dps93xge6qjdzjae",
    "name": "Mechanical Keyboard",
    "sku": "KEY-001",
    "description": "Compact mechanical keyboard with hot-swappable switches.",
    "price": "125.00",
    "status": "active",
    "stock_quantity": 50
  }'
```

`201 Created` — the product and its opening inventory row are created in one transaction.

```json
{
  "success": true,
  "message": "Product created successfully.",
  "data": {
    "id": "01m2swhmj7dps93xge6qjdzjaf",
    "category_id": "01m2swhmj7dps93xge6qjdzjae",
    "category_name": "Computer Accessories",
    "name": "Mechanical Keyboard",
    "sku": "KEY-001",
    "description": "Compact mechanical keyboard with hot-swappable switches.",
    "price": "125.00",
    "status": "active",
    "category": {
      "id": "01m2swhmj7dps93xge6qjdzjae",
      "name": "Computer Accessories",
      "slug": "computer-accessories",
      "status": "active",
      "products_count": 12
    },
    "inventory": {
      "id": "01m2swhmj7dps93xge6qjdzjai",
      "product_id": "01m2swhmj7dps93xge6qjdzjaf",
      "quantity_on_hand": 50,
      "quantity_reserved": 0,
      "available_quantity": 50
    },
    "stock": 50,
    "available_stock": 50,
    "units_sold": 0,
    "orders_count": 0,
    "gross_sales": "0.00",
    "sales_rank": null,
    "created_at": "2026-09-18T10:00:00.000000Z"
  }
}
```

### 4. List products with filters

Supported query parameters (`ProductIndexRequest`): `search`, `status`, `category_id`, `category`,
`min_price`, `max_price`, `from`, `to`, `sort`, `per_page`. Sortable: `name`, `sku`, `status`,
`price`, `category_name`, `stock`, `available_stock`, `units_sold`, `gross_sales`, `sales_rank`,
`created_at` — prefix with `-` for descending.

```bash
curl -G http://localhost:8080/api/v1/products \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'search=keyboard' \
  --data-urlencode 'status=active' \
  --data-urlencode 'category=computer' \
  --data-urlencode 'min_price=10.00' \
  --data-urlencode 'max_price=250.00' \
  --data-urlencode 'sort=-price' \
  --data-urlencode 'per_page=15'
```

`200 OK`

```json
{
  "success": true,
  "message": "OK.",
  "data": [
    {
      "id": "01m2swhmj7dps93xge6qjdzjaf",
      "category_id": "01m2swhmj7dps93xge6qjdzjae",
      "category_name": "Computer Accessories",
      "name": "Mechanical Keyboard",
      "sku": "KEY-001",
      "description": "Compact mechanical keyboard with hot-swappable switches.",
      "price": "125.00",
      "status": "active",
      "category": {
        "id": "01m2swhmj7dps93xge6qjdzjae",
        "name": "Computer Accessories",
        "slug": "computer-accessories",
        "status": "active",
        "products_count": 12
      },
      "inventory": {
        "id": "01m2swhmj7dps93xge6qjdzjai",
        "product_id": "01m2swhmj7dps93xge6qjdzjaf",
        "quantity_on_hand": 50,
        "quantity_reserved": 2,
        "available_quantity": 48
      },
      "stock": 50,
      "available_stock": 48,
      "units_sold": 2,
      "orders_count": 1,
      "gross_sales": "250.00",
      "sales_rank": 1,
      "created_at": "2026-09-18T10:00:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1 }
}
```

Either bound may be used on its own, so `?max_price=25` ("everything under $25") is valid. An
inverted range is rejected with `422`:
`{"success": false, "message": "The given data was invalid.", "errors": {"max_price": ["The max price field must be greater than or equal to min price."]}}`.

### 5. Adjust inventory

`type` accepts `restock` (must be positive) or `correction` (may be negative).

```bash
curl -X POST http://localhost:8080/api/v1/inventory/01m2swhmj7dps93xge6qjdzjaf/adjust \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"type": "restock", "quantity": 25}'
```

`200 OK`

```json
{
  "success": true,
  "message": "Inventory adjusted successfully.",
  "data": {
    "id": "01m2swhmj7dps93xge6qjdzjai",
    "product_id": "01m2swhmj7dps93xge6qjdzjaf",
    "quantity_on_hand": 75,
    "quantity_reserved": 2,
    "available_quantity": 73
  }
}
```

An adjustment that would uncover reserved stock returns `422`:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "quantity": ["This adjustment would leave 1 units on hand while 2 are reserved for open orders."]
  }
}
```

### 6. Create an order (idempotent)

```bash
curl -X POST http://localhost:8080/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Idempotency-Key: 6f1e1d1c-6b0a-4f2f-9a1e-8b0f5f2a1c3d' \
  -d '{
    "customer_id": "01m2swhmj7dps93xge6qjdzjae",
    "items": [
      { "product_id": "01m2swhmj7dps93xge6qjdzjaf", "quantity": 2 }
    ]
  }'
```

`201 Created`, header `Idempotent-Replay: false`

```json
{
  "success": true,
  "message": "Order created successfully.",
  "data": {
    "id": "01m2swhmj7dps93xge6qjdzjag",
    "customer_id": "01m2swhmj7dps93xge6qjdzjae",
    "customer_name": "Customer 0001",
    "customer": {
      "id": "01m2swhmj7dps93xge6qjdzjae",
      "name": "Customer 0001",
      "email": "customer0001@example.com"
    },
    "order_number": "ORD-8F2K9QZ1MXTP",
    "status": "pending",
    "total_amount": "250.00",
    "items_count": 1,
    "items": [
      {
        "id": "01m2swhmj7dps93xge6qjdzjak",
        "product_id": "01m2swhmj7dps93xge6qjdzjaf",
        "product_name": "Mechanical Keyboard",
        "product": { "id": "01m2swhmj7dps93xge6qjdzjaf", "name": "Mechanical Keyboard", "sku": "KEY-001" },
        "quantity": 2,
        "unit_price": "125.00",
        "line_total": "250.00"
      }
    ],
    "cancelled_at": null,
    "created_at": "2026-09-18T10:30:00.000000Z"
  }
}
```

**Replay** — repeating the exact same request with the same key returns the stored response
verbatim, with `201` and header `Idempotent-Replay: true`. No second order is created and no extra
stock is reserved.

Same key, **different** payload → `409`:

```json
{
  "success": false,
  "message": "This Idempotency-Key was already used with a different request payload.",
  "errors": {
    "Idempotency-Key": ["This Idempotency-Key was already used with a different request payload."]
  }
}
```

Duplicate arriving while the first is still running → `409` with `Retry-After: 1`:

```json
{
  "success": false,
  "message": "A request with this Idempotency-Key is still being processed. Retry shortly.",
  "errors": {
    "Idempotency-Key": ["A request with this Idempotency-Key is still being processed. Retry shortly."]
  }
}
```

Missing header → `422` with `errors."Idempotency-Key"`. Not enough stock → `409`:

```json
{
  "success": false,
  "message": "Insufficient stock for one or more products.",
  "errors": {
    "items": [
      { "product_id": "01m2swhmj7dps93xge6qjdzjaf", "requested": 5, "available": 2 }
    ]
  }
}
```

### 7. Update order status

```bash
curl -X PATCH http://localhost:8080/api/v1/orders/01m2swhmj7dps93xge6qjdzjag/status \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"status": "confirmed", "note": "Stock confirmed and order is ready for processing."}'
```

`200 OK`

```json
{
  "success": true,
  "message": "Order status updated successfully.",
  "data": {
    "id": "01m2swhmj7dps93xge6qjdzjag",
    "customer_id": "01m2swhmj7dps93xge6qjdzjae",
    "customer_name": "Customer 0001",
    "order_number": "ORD-8F2K9QZ1MXTP",
    "status": "confirmed",
    "total_amount": "250.00",
    "items_count": 1,
    "cancelled_at": null,
    "created_at": "2026-09-18T10:30:00.000000Z"
  }
}
```

Sending `{"status": "completed"}` next converts the reservation into a physical decrement
(`quantity_on_hand 75 → 73`, `quantity_reserved 2 → 0`).

Sending `{"status": "cancelled"}` is rejected with `422`:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "status": ["Allowed statuses are: pending, confirmed, completed. Use POST /orders/{order}/cancel to cancel an order so its reserved stock is released."]
  }
}
```

An illegal transition (e.g. `completed → confirmed`) returns `422`:

```json
{
  "success": false,
  "message": "An order cannot move from completed to confirmed.",
  "errors": {
    "status": ["An order cannot move from completed to confirmed."],
    "allowed_transitions": []
  }
}
```

### 8. Cancel an order

```bash
curl -X POST http://localhost:8080/api/v1/orders/01m2swhmj7dps93xge6qjdzjag/cancel \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

`200 OK` — reserved units return to available; repeating the call is a safe no-op.

```json
{
  "success": true,
  "message": "Order cancelled successfully.",
  "data": {
    "id": "01m2swhmj7dps93xge6qjdzjag",
    "customer_id": "01m2swhmj7dps93xge6qjdzjae",
    "order_number": "ORD-8F2K9QZ1MXTP",
    "status": "cancelled",
    "total_amount": "250.00",
    "items_count": 1,
    "cancelled_at": "2026-09-18T11:05:00.000000Z",
    "created_at": "2026-09-18T10:30:00.000000Z"
  }
}
```

Cancelling a `completed` order returns `422` (`An order cannot move from completed to cancelled.`).
Another client's order returns `403` (`This action is unauthorized.`).

### 9. Order status history

```bash
curl -G http://localhost:8080/api/v1/orders/01m2swhmj7dps93xge6qjdzjag/history \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'per_page=15'
```

`200 OK` — newest first, ties broken by the time-ordered ULID.

```json
{
  "success": true,
  "message": "OK.",
  "data": [
    {
      "id": "01m2swhmj7dps93xge6qjdzjam",
      "order_id": "01m2swhmj7dps93xge6qjdzjag",
      "from_status": "confirmed",
      "to_status": "cancelled",
      "note": "Order cancelled.",
      "created_at": "2026-09-18T11:05:00.000000Z"
    },
    {
      "id": "01m2swhmj7dps93xge6qjdzjal",
      "order_id": "01m2swhmj7dps93xge6qjdzjag",
      "from_status": "pending",
      "to_status": "confirmed",
      "note": "Stock confirmed and order is ready for processing.",
      "created_at": "2026-09-18T10:35:00.000000Z"
    },
    {
      "id": "01m2swhmj7dps93xge6qjdzjah",
      "order_id": "01m2swhmj7dps93xge6qjdzjag",
      "from_status": null,
      "to_status": "pending",
      "note": "Order created.",
      "created_at": "2026-09-18T10:30:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 3, "last_page": 1, "from": 1, "to": 3 }
}
```

### 10. Inventory movement ledger

```bash
curl -G http://localhost:8080/api/v1/inventory/01m2swhmj7dps93xge6qjdzjaf/movements \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'type=order_reserved' \
  --data-urlencode 'per_page=15'
```

`200 OK`

```json
{
  "success": true,
  "message": "OK.",
  "data": [
    {
      "id": "01m2swhmj7dps93xge6qjdzjaj",
      "product_id": "01m2swhmj7dps93xge6qjdzjaf",
      "order_id": "01m2swhmj7dps93xge6qjdzjag",
      "type": "order_reserved",
      "quantity_delta": 0,
      "quantity_after": 75,
      "reserved_delta": 2,
      "reserved_after": 2,
      "created_at": "2026-09-18T10:30:00.000000Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1, "from": 1, "to": 1 }
}
```

A fulfilment entry looks like `"type": "order_fulfilled", "quantity_delta": -2, "quantity_after": 73,
"reserved_delta": -2, "reserved_after": 0`; a manual restock has `"order_id": null`.

### 11. Order summary report

```bash
curl -G http://localhost:8080/api/v1/orders/reports/summary \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode 'from=2026-01-01' \
  --data-urlencode 'to=2026-12-31'
```

`200 OK`

```json
{
  "success": true,
  "message": "Order report generated successfully.",
  "data": {
    "orders_count": 128,
    "total_sales": "48250.00",
    "average_order_value": "377.00",
    "pending_count": 12,
    "confirmed_count": 30,
    "completed_count": 80,
    "cancelled_count": 6
  }
}
```

`total_sales` excludes cancelled orders; `average_order_value` is `total_sales / orders_count`
computed in integer cents. Optional `status` filter restricts the whole report to one status.

---

## Testing

```bash
php artisan test          # locally
make test                 # inside the app container
php artisan test --filter=OrderWorkflowTest
```

`phpunit.xml` runs against SQLite `:memory:` with `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, and
`MAIL_MAILER=array`, so no external services are needed. `tests/TestCase.php` generates Passport
keys on demand.

The suite is 69 tests / 632 assertions and also passes against the production drivers. To run it
against the Docker MySQL and Redis instead of SQLite:

```bash
docker compose up -d mysql redis
docker compose exec -T mysql mysql -uroot -proot_secret -e "CREATE DATABASE IF NOT EXISTS order_inventory_test; GRANT ALL ON order_inventory_test.* TO 'order_inventory'@'%';"

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=order_inventory_test \
DB_USERNAME=order_inventory DB_PASSWORD=secret DB_URL= \
CACHE_STORE=redis REDIS_HOST=127.0.0.1 REDIS_PORT=6380 \
php artisan test
```

This matters because `lockForUpdate()` is a no-op on SQLite: the locking behaviour that prevents
overselling is only genuinely exercised on MySQL.

| Suite | File | Covers |
| --- | --- | --- |
| Auth | `tests/Feature/Auth/PassportAuthTest.php` | register → login → logout response shape; the global validation error envelope |
| Orders | `tests/Feature/Orders/OrderApiTest.php` | stock reservation, idempotent replay, oversell prevention, inactive products rejected, ownership (a client cannot read/act on another client's order; admins can) |
| Orders | `tests/Feature/Orders/OrderWorkflowTest.php` | reservation holds stock without moving units, completion converts reservation into a decrement, cancel releases and records a movement, double cancel releases once, illegal/skipped transitions rejected, the status endpoint cannot cancel, history records every transition |
| Orders | `tests/Feature/Orders/OrderReadableNamesTest.php` | customer names and line-item product names in order payloads, sorting by customer/item columns |
| Orders | `tests/Feature/Orders/OrderIdempotencyTest.php` | missing key rejected, key reused with a different payload conflicts, a failed request releases the key, two keys create two orders, a replay does not reserve stock twice |
| Catalog | `tests/Feature/Catalog/ProductApiTest.php`, `ProductCategoryFilterTest.php`, `ProductMetricsTest.php`, `ProductPriceFilterTest.php`, `CategoryApiTest.php` | product creation response shape, filter/sort/pagination scope, category name/slug filtering, price-range bounds, sales metrics, category `products_count` |
| Customers | `tests/Feature/Customers/CustomerApiTest.php` | search/sort and order metrics on list and show |
| Inventory | `tests/Feature/Inventory/InventoryApiTest.php` | filtering by product, sorting quantities, product identity in the payload |
| Inventory | `tests/Feature/Inventory/InventoryAdjustmentTest.php` | restock and correction movements, negative restock and zero quantity rejected, an adjustment that would uncover reserved stock rejected, ledger ordering and type filter |
| Caching | `tests/Feature/Caching/CachedReadsTest.php` | repeat reads hit the cache, writes invalidate the namespace, distinct query parameters do not collide |
| Reports | `tests/Feature/Reports/OrderReportTest.php` | cancelled orders excluded from sales, status filter, date-range filter, invalid filters rejected |
| Rate limiting | `tests/Feature/RateLimitingTest.php` | the `orders` and `auth` limiters, and the `Retry-After` header on a throttled response |
| Error handling | `tests/Feature/ErrorHandlingTest.php` | the error envelope for unauthenticated, unknown route, missing model, and validation failures |
| Listing | `tests/Feature/ListFilteringTest.php` | visible table columns are safe sort keys across endpoints |
| Support | `tests/Feature/Support/AuditFieldsTest.php` | `created_by` / `updated_by` filled from the authenticated user |
| Unit | `tests/Unit/Database/BlueprintMacrosTest.php` | `ulid()`, `actionAt()`, `actionBy()` helpers are registered |
| Unit | `tests/Unit/Http/IndexRequestPaginationTest.php` | every index request shares the centralised pagination rules |
| Unit | `tests/Unit/Support/MoneyTest.php` | dollars → cents and cents → dollar strings |

---

## Key technical decisions

| Decision | Alternative | Why |
| --- | --- | --- |
| **Reserve on create, decrement on completion** | decrement on-hand immediately | `on_hand` stays the physical truth used for picking/counting, while `available = on_hand − reserved` is what orders draw from. Cancellation becomes a pure release with no risk of "returning" units that never left. Cost: two counters to keep consistent, which is why one class owns both |
| **Pessimistic locking (`lockForUpdate`) before the availability check** | optimistic versioning with retry | Contention is on a small set of hot rows and the check-then-act window is exactly what must be closed. Locks are taken in sorted `product_id` order in one statement, so orders queue instead of deadlocking; `DB::transaction(..., 3)` retries genuine deadlock victims. Optimistic retries would convert every collision into wasted work plus a user-visible failure |
| **Idempotency in middleware, claim-first** | check inside the action | Keeps the guarantee uniform and outside business logic, and makes the **unique index** the arbiter — a duplicate never reaches the controller, so there is no second transaction to undo. Failures release the claim, so bad payloads stay retryable |
| **Version-stamped cache namespaces** | Laravel cache tags | Works on every driver (Redis, database, file, array), invalidates a whole group in one `INCR`, needs no key enumeration or `SCAN`, and keeps concurrent readers consistent. Trade-off: orphaned entries linger until their TTL, which is acceptable at a 300s TTL |
| **Synchronous cache invalidation, queued side effects** | queue everything | A deferred flush leaves a window where reads contradict a committed write. Mail and report re-warming do not affect read correctness, so they are queued (`RefreshOrderReportCache` is `ShouldBeUnique` to collapse bursts) |
| **ULID primary keys** | auto-increment integers | Time-ordered (so index locality and `ORDER BY id` tiebreaking still work), non-guessable in URLs, and generatable before insert — useful for building an order and its children in one transaction. Cost: 26 bytes per key |
| **Money as `decimal(12,2)` in SQL, integer cents in PHP** | floats, or cents everywhere | Floats drift on multiplication/summing; integer cents keep arithmetic exact. Decimal dollars keep SQL aggregates and DB tooling readable, and `Money` + Eloquent casts confine the conversion to one boundary |
| **Orders owned by their creator; catalog shared** | everything global, or everything tenant-scoped | Orders carry commercial data, so `OrderPolicy` plus `scopeVisibleTo()` enforce ownership at both the policy and query layers, with `is_admin` as the operator escape hatch. Products/categories/customers are shared reference data, so scoping them would only add friction |
| **Cancellation has its own endpoint** | allow `status=cancelled` on the status endpoint | Cancelling has a stock side effect (release). Excluding it from `clientTransitionableValues()` makes it impossible to reach the terminal `cancelled` state through a path that does not restore inventory |
| **Actions + a single ledger service** | logic in controllers or fat models | Each write use case owns exactly one transaction boundary, and `InventoryLedger` is the only writer of stock balances, so the locking and ledger-append invariants are enforced in one file instead of at every call site |
