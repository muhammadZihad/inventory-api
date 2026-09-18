# Lessons

## Sorting & pagination
- **Pattern**: `latest('created_at')` was used as the only ordering for two different
  paginated ledgers (`OrderController::history`, `InventoryController::movements`). The
  `created_at` columns are second-precision `dateTime`, so rows written in the same
  request came back in arbitrary order. The same bug was introduced twice — fixed in
  `history()`, then repeated in `movements()`.
  **Rule**: any paginated endpoint ordered by a timestamp must add a deterministic
  tiebreaker (`->orderByDesc('id')`, which is time-ordered because primary keys are
  ULIDs). Never rely on a timestamp alone for stable ordering.
  **Date**: 2026-09-18

## Validation
- **Pattern**: `'max_x' => [..., 'gte:min_x']` made the upper bound unusable on its own —
  when `min_x` was absent, `gte` compared against `null` and returned 422 for a
  legitimate "everything under N" query.
  **Rule**: for optional range filters, guard the comparison with
  `Rule::when($this->filled('min_x'), ['gte:min_x'])` so each bound works independently,
  and add a test that sends the upper bound alone.
  **Date**: 2026-09-18

## Events & listeners
- **Pattern**: listeners were registered explicitly with `Event::listen()` in
  `AppServiceProvider` while Laravel also auto-discovered them from their `handle()`
  type-hints, so every listener fired twice — including a queued mail notification.
  **Rule**: in Laravel 11+, do not hand-register listeners that live in `app/Listeners`.
  Verify wiring with `php artisan event:list` after adding a listener and confirm each
  appears exactly once.
  **Date**: 2026-09-18

## Test isolation
- **Pattern**: caching tests passed under the array driver but failed on a second run
  against Redis, because the cache outlives the database between runs.
  **Rule**: any test that asserts on cached values must clear the cache in `setUp()`
  rather than depending on the driver configured in `phpunit.xml`.
  **Date**: 2026-09-18

## Verification
- **Pattern**: the suite runs on SQLite `:memory:`, where `lockForUpdate()` is a no-op, so
  green tests proved nothing about the concurrency control that prevents overselling.
  **Rule**: verify locking and cache behaviour against the real drivers (MySQL + Redis)
  before claiming they work — for locking, by running genuinely concurrent processes.
  **Date**: 2026-09-18

## Environment isolation when testing
- **Pattern**: exported MySQL `DB_*` env vars in a shell, then ran `php artisan test` in the same
  shell. `phpunit.xml` declares `DB_CONNECTION=sqlite` without `force="true"`, so the real env
  won, `RefreshDatabase` ran `migrate:fresh` against the MySQL database, and it wiped a
  freshly seeded 1.8M row dataset.
  **Rule**: never export database env vars into a shell that will also run the test suite. Scope
  them to the single command with `env VAR=... <command>`, and treat any test run that takes
  noticeably longer than the SQLite baseline as a signal it is hitting the wrong database.
  **Date**: 2026-09-18

## Performance verification
- **Pattern**: list endpoints were verified only against a dozen demo rows, so a `leftJoinSub`
  over the whole table looked fine. At 1.8M rows a cold product list took 5.5 s, because the
  aggregate ran once for the paginator's COUNT and once for the page, joined to every row.
  **Rule**: measure list endpoints against a realistically sized dataset before calling them done,
  and check whether an aggregate join happens before or after pagination. Paginate on an indexed
  column first, then attach per-page aggregates.
  **Date**: 2026-09-18

## Aggregates in list endpoints
- **Pattern**: product sales totals were computed per request by grouping every `order_items` row
  and joining the result to the whole catalog. It was invisible at demo scale and cost seconds at
  1.8M rows. Two compounding mistakes: the paginator's `COUNT(*)` repeated the entire join to
  produce a number a left join on a unique key cannot change, and a `LEFT JOIN` forced the
  optimiser to drive from the unsorted side.
  **Rule**: a column that is sortable must be a stored, indexed column — materialise the aggregate
  and maintain it on the write path. Pass a precomputed `$total` to `paginate()` when the join
  cannot change the row count, and prefer an INNER JOIN when the relationship is guaranteed, so the
  optimiser can drive from the sorted index.
  **Date**: 2026-09-19

## Invariants belong to the model, not the action
- **Pattern**: the "every product has a metrics row" invariant was created in `CreateProductAction`.
  Switching to an INNER JOIN then broke every test that built products with a factory, because
  factories bypass the action — the products silently vanished from list and show endpoints.
  **Rule**: when a join depends on a row always existing, enforce it with a model event so it holds
  for factories, console scripts and imports too, and provide a rebuild command for bulk inserts
  that bypass Eloquent entirely.
  **Date**: 2026-09-19
