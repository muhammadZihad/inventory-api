# API reference

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

[← Back to the README](../README.md)
