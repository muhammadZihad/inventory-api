# Testing

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

[← Back to the README](../README.md)
