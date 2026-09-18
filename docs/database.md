# Database schema

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

[← Back to the README](../README.md)
