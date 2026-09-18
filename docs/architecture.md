# Architecture

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

[← Back to the README](../README.md)
