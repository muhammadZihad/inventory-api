# Order Processing & Inventory API

A production-style backend for e-commerce order processing and inventory management, built with
Laravel 13, MySQL and Redis, with a Vue 3 operations console in front of it.

The whole design turns on one idea: **stock is reserved, not just decremented.** Inventory tracks
on-hand and reserved quantities separately, `available = on_hand - reserved` is the only figure an
order may draw from, and every change to either number is appended to a ledger that replays back to
the stored balance. Everything else — the locking, the state machine, the idempotency layer — exists
to keep that invariant true under concurrency and retries.

---

## What it does

- **Catalog** — products and categories with search, filtering, price ranges, sorting and pagination
- **Inventory** — on-hand/reserved/available balances, signed manual adjustments, and an append-only
  movement ledger
- **Orders** — creation with stock reservation, a guarded status workflow, cancellation that releases
  stock, and full status history
- **Reporting** — order summary aggregates
- **Console** — a Vue SPA that exercises every endpoint

Roughly 28 REST endpoints, documented by Scribe as OpenAPI and a Postman collection.

---

## Running it

```bash
make setup
```

That builds the containers, migrates, generates Passport keys, seeds ~1.8M rows, generates the API
docs and builds the console. Then:

| | |
| --- | --- |
| Console | http://localhost:8080 |
| API | http://localhost:8080/api/v1 |
| API docs | http://localhost:8080/docs |
| Demo login | `test@example.com` / `password` |

```bash
make test          # 71 tests
make lint          # Pint
make fresh         # re-migrate and re-seed
```

Seeding takes 90–120 seconds. `SEED_SCALE=0.01 php artisan db:seed` gives a fast local dataset
instead. Full instructions, including running without Docker, are in
**[docs/setup.md](docs/setup.md)**.

---

## Architecture

```
routes  →  Form Request  →  Controller  →  Action / Service  →  Model
                               ↓                  ↓
                          API Resource       domain event
                                                  ↓
                                        listeners (cache, queue)
```

Controllers stay thin: validate, delegate, return a resource. Business logic lives in single-purpose
**Actions** (`CreateOrderAction`, `CancelOrderAction`) and **Services** (`InventoryLedger`,
`OrderReportService`, `SalesMetricsService`). `InventoryLedger` is the single writer for stock
balances, so the reservation invariant is enforced in exactly one place.

Cross-cutting behaviour is kept out of the controllers entirely:

- **Idempotency** is middleware — it claims the key by inserting it *before* the request runs, so the
  unique index arbitrates concurrent duplicates rather than a read-then-write check
- **Cache invalidation** is event-driven, and synchronous, because a queued flush would leave a
  window serving reads the write has already contradicted
- **Notifications and analytics** are queued, because they must never slow or fail an order

→ **[docs/architecture.md](docs/architecture.md)** · **[docs/database.md](docs/database.md)** ·
**[docs/order-workflow.md](docs/order-workflow.md)**

---

## Performance

Measured on the seeded 1.8M row dataset, cold cache, through nginx + php-fpm:

| Request | Before | After |
| --- | --- | --- |
| `GET /products` | 5.52 s | **0.05 s** |
| `GET /products?sort=gross_sales` | ~2.7 s | **0.08 s** |
| `GET /customers` | 2.10 s | 0.23 s |
| `GET /orders`, `/inventory`, `/reports/summary` | — | 0.07–0.13 s |
| any of the above, warm cache | — | 0.03 s |

Four changes got there:

1. **Materialised the product sales aggregates.** Deriving units sold, gross sales and rank per
   request meant grouping every `order_items` row and joining it to the whole catalog — no index can
   help, because the sort column does not exist until the join has run. They are now stored per
   product and indexed, updated synchronously on order creation.
2. **Made that join inner rather than left.** Every product is guaranteed a metrics row, so the
   result is identical, but the optimiser is then free to drive from the sorted metric index and read
   fifteen rows instead of sorting 120,000. That single change: 256 ms → 1 ms.
3. **Stopped the paginator's redundant `COUNT(*)`.** It was repeating the entire aggregate join to
   produce a number the join cannot change.
4. **Covering indexes and sargable filters** — date ranges compare against the bare `created_at`
   column instead of wrapping it in `DATE()`, so the composite indexes stay usable.

Plus read-through caching on a version-stamped namespace, which invalidates a whole group with one
counter increment and needs no tag support from the driver.

→ **[docs/performance.md](docs/performance.md)** · **[docs/caching.md](docs/caching.md)**

---

## Documentation

| | |
| --- | --- |
| [Setup](docs/setup.md) | Docker and local installation, environment configuration |
| [Architecture](docs/architecture.md) | Layering, actions, services, DTOs, filters |
| [Database schema](docs/database.md) | Every table, column, relationship and index |
| [Order workflow](docs/order-workflow.md) | State machine, locking, overselling, idempotency |
| [Caching](docs/caching.md) | Strategy, invalidation matrix, queues and events |
| [Performance](docs/performance.md) | Indexing, query optimisation, measurements |
| [Security](docs/security.md) | Auth, authorization, rate limiting, error envelope |
| [API reference](docs/api.md) | Every endpoint with request and response samples |
| [Frontend](docs/frontend.md) | Console structure and endpoint coverage |
| [Seed data](docs/seeding.md) | Volumes, consistency guarantees, scaling |
| [Testing](docs/testing.md) | What is covered and how to run it |
| [Decisions](docs/decisions.md) | Trade-offs and why they were made |

---

## Testing

```bash
php artisan test
```

71 tests covering the reservation lifecycle and state machine, idempotent replay and conflict
handling, cache invalidation, rate limiting, reporting, stock adjustment guards, authorization and
the error envelope.

The suite runs on SQLite, where `lockForUpdate()` is a no-op — so the locking that prevents
overselling is verified separately against MySQL, including with genuinely concurrent processes.
See **[docs/testing.md](docs/testing.md)**.
