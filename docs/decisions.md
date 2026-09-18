# Key technical decisions

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

---

[← Back to the README](../README.md)

## Actions and services instead of repositories

`~/.claude/CLAUDE.md` prescribes a Service/Repository pattern with `app/Repositories/`
(interface plus Eloquent implementation). This project deliberately departs from that: data access
goes through Eloquent directly from single-purpose Actions and Services.

**Why.** A repository wrapping Eloquent mostly re-exposes the query builder through a narrower
interface, and the swappability it promises is rarely exercised — Eloquent models are already the
persistence abstraction. What the rule is really protecting against is business logic leaking into
controllers, and that is addressed here by Actions (`CreateOrderAction`), Services
(`InventoryLedger`, `SalesMetricsService`) and query objects (`ProductListQuery`), which keep
controllers to validate-delegate-respond.

**Where abstraction does earn its place**, it is applied: `App\Contracts\StockLedger`,
`SalesMetrics` and `OrderReports` are interfaces bound to their implementations in
`AppServiceProvider`, so the order workflow depends on the stock ledger's behaviour rather than on
one particular implementation of it.

**Trade-off.** Swapping the persistence layer wholesale would touch more code than it would with
repositories. That is judged an acceptable cost for an application whose storage is MySQL by design
and whose concurrency guarantees depend on database row locking.

## Catalog writes are administrator-only

Reads are open to any authenticated client, but creating or editing products and categories, and
adjusting stock, require `users.is_admin`.

Registration is public, so without this any self-registered account could rewrite prices, delete
products or invent inventory. Customer creation and updates stay open because placing an order
requires a customer record; deletion is restricted because it cascades to that customer's orders.

This replaces an earlier decision to treat catalog data as shared and unguarded, which an automated
security review correctly flagged and a live probe confirmed was exploitable.
