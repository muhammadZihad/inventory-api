# Caching, queues and events

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

[← Back to the README](../README.md)
