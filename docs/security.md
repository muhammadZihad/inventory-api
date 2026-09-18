# Authentication, authorization, rate limiting and errors

### Authentication

Laravel Passport. The `api` guard uses the `passport` driver (`config/auth.php`), and protected
routes use `auth:api`. `POST /auth/register` and `POST /auth/login` issue a personal access token
through `IssuePassportTokenAction` (`$user->createToken(...)`); `POST /auth/logout` revokes the
current token. Send the token as:

```http
Authorization: Bearer <access_token>
```

A personal access client must exist — `make setup` / `make passport` creates it.

### Authorization

`App\Policies\OrderPolicy` — `view`, `update`, `cancel` all resolve to `owns()`:

```php
return $user->is_admin || $order->created_by === $user->id;
```

Orders are owned by the API client that created them; admins (`users.is_admin`) reach every order.
The same rule is enforced at the query level by `Order::scopeVisibleTo()` on `GET /orders`, so a
list can never leak another client's orders even if a policy check were missed. Catalog data
(products, categories, customers) is deliberately shared business data and has no ownership policy.

### Rate limiting

Defined in `AppServiceProvider::registerRateLimiters()`:

| Limiter | Limit | Keyed by | Applied to |
| --- | --- | --- | --- |
| `api` | 120 / minute | user id, falling back to IP | every API route (appended in `bootstrap/app.php`) |
| `orders` | 30 / minute | user id, falling back to IP | `POST /orders`, `PATCH /orders/{order}/status`, `POST /orders/{order}/cancel`, `POST /inventory/{product}/adjust` |
| `auth` | 20 / minute | IP (no user yet) | `POST /auth/register`, `POST /auth/login` |

Write paths take inventory row locks, hence the tighter budget.

### Error handling

Every API error uses one envelope (`ApiResponse::error`), configured in `bootstrap/app.php`:

```json
{ "success": false, "message": "…", "errors": null }
```

| Situation | Status | Message |
| --- | --- | --- |
| `ValidationException` | 422 | `The given data was invalid.` + field errors |
| `AuthenticationException` | 401 | `Unauthenticated.` |
| `AuthorizationException` | 403 | `This action is unauthorized.` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `Resource not found.` |
| `ThrottleRequestsException` | 429 | `Too many requests.` (rate-limit headers preserved) |
| `InsufficientStockException` | 409 | `Insufficient stock for one or more products.` + `errors.items[]` |
| `IdempotencyConflictException` | 409 | key reused with a different payload |
| `IdempotentRequestInFlightException` | 409 | duplicate still processing, with `Retry-After: 1` |
| `InvalidOrderTransitionException` | 422 | `An order cannot move from X to Y.` + `allowed_transitions` |
| Anything else | 500 | `An unexpected server error occurred.` (the real message only when `APP_DEBUG`) |

The 500 catch-all logs `exception`, `message`, `method`, `path`, and `user_id` through
`Log::error()`, so a fault is never swallowed silently. An idempotency key reused with a different
payload is logged with `Log::warning()`.

---

[← Back to the README](../README.md)
