# Database optimization and indexing

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

[← Back to the README](../README.md)
