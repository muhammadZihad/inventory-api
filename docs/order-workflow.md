# Order workflow, concurrency and idempotency

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

[← Back to the README](../README.md)
