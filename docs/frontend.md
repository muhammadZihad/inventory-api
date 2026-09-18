# Frontend console

A Vue 3 single-page console ships with the API and exercises **every endpoint**. It is served at
`/` by `routes/web.php` and built with Vite.

```bash
npm install
npm run build      # production assets into public/build
npm run dev        # hot-reloading dev server while working on the UI
```

Sign in with the seeded account (`test@example.com` / `password`), or create a new one from the
same panel.

### Layout

```
resources/js/
  app.js                       mounts the app
  App.vue                      shell: auth gate, tab navigation, toasts
  api/client.js                fetch wrapper: bearer token, error envelope, Idempotency-Key
  composables/
    useAuth.js                 session state, register / login / logout
    useResourceList.js         filters, sorting, pagination for any list endpoint
    useToasts.js               global notification queue
  components/                  DataTable, FilterBar, PagerBar, DrawerPanel,
                               LoginPanel, StatusBadge, FieldErrors, ToastStack
  views/
    DashboardView.vue          report summary, low-availability stock, latest orders
    OrdersView.vue             list, place, transition, cancel, history
    InventoryView.vue          balances, adjustments, movement ledger
    ProductsView.vue           product CRUD
    CategoriesView.vue         category CRUD
    CustomersView.vue          customer CRUD
```

Because every list endpoint shares one contract — `search`, typed filters, `sort` with a `-` prefix
for descending, `page`, `per_page`, and a `meta` block — a single `useResourceList` composable and
one `DataTable` component drive all six lists.

### Endpoint coverage

| View | Endpoints used |
| --- | --- |
| Login panel | `POST /auth/register`, `POST /auth/login` |
| Shell | `POST /auth/logout` |
| Dashboard | `GET /orders/reports/summary`, `GET /inventory`, `GET /orders` |
| Orders | `GET /orders`, `POST /orders`, `GET /orders/{order}`, `GET /orders/{order}/history`, `PATCH /orders/{order}/status`, `POST /orders/{order}/cancel` |
| Inventory | `GET /inventory`, `POST /inventory/{product}/adjust`, `GET /inventory/{product}/movements` |
| Products | `GET`/`POST` `/products`, `GET`/`PUT`/`DELETE` `/products/{product}` |
| Categories | `GET`/`POST` `/categories`, `GET`/`PUT`/`DELETE` `/categories/{category}` |
| Customers | `GET`/`POST` `/customers`, `GET`/`PUT`/`DELETE` `/customers/{customer}` |

That is all 28 distinct endpoints. (`PUT` and `PATCH` on the three CRUD resources are the same
route and controller action, so the console uses `PUT`.)

### How the console surfaces the domain rules

- **Reservations are visible.** Inventory lists on-hand, reserved and available side by side, and
  the product picker in the order form shows available stock per product, so the difference between
  physical and sellable stock is obvious.
- **Idempotency is explicit.** The order form generates one `Idempotency-Key` when it opens and
  reuses it for every retry of that submission; the key is displayed in the form. A replayed
  response is detected through the `Idempotent-Replay` header and reported as such rather than being
  passed off as a new order.
- **The state machine drives the UI.** The order drawer only offers transitions the workflow
  allows — `confirmed` from pending, `completed` from confirmed — and cancellation always goes
  through the cancel endpoint, so stock is released.
- **Domain errors are rendered, not swallowed.** A 409 insufficient-stock response lists the exact
  per-product shortfall (requested vs available); 422 responses map field errors back to their
  inputs.

---

[← Back to the README](../README.md)
