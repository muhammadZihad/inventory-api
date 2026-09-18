# Seed data

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

[← Back to the README](../README.md)
