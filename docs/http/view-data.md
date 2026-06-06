# View data

> **Owns**
>
> - Inertia page-prop shaping rules
> - The per-page view model (the default for any non-trivial page)
> - When to use an Eloquent API resource (optional single-entity detail)
> - The boundary with [Read models](../application/read-models.md) (lists, dashboards, reports)
>
> **Forbids**
>
> - Inline anonymous-array shaping in controllers
> - Page-prop shaping inside `HandleInertiaRequests` for non-trivial actor data
> - Authorization checks dressed up as view data
> - Loading aggregates to render lists or dashboards — see [Read models](../application/read-models.md)
> - More than one public shaping method on a view model — the entry point is always `build(...)`
> - Queries or `app(...)` service location inside a view model or resource
>
> **See also**: [Controllers](controllers.md), [Read models](../application/read-models.md), [Models](../data/models.md), [Architecture](../architecture.md)

When a controller renders an Inertia page with non-trivial data, the page-props array is **shaped by a dedicated class** — never inline. The default is a **per-page view model**; an Eloquent API resource is an optional single-entity serializer; lists and dashboards come from a [read model](../application/read-models.md).

> Names like `DashboardPage` / `EmployeesQuery` / `EmployeeResource` are illustrative.

## Per-page view model (the default)

For any page that needs more than a single read model passed straight through, build a **view model per page**:

- **One view model per Inertia page**, named after the page: `<Page>Page` (illustrative).
- Lives at `Interfaces/<EntryPoint>/ViewModels/<Group>/<Page>Page.php`.
- Exposes a **single public method, `build(...)`**, returning the page-props array. Same method name on every view model — convention over per-page invention.
- **Constructor-injects its collaborators** (the context [queries](../application/read-models.md) it composes). The page's *resolved inputs* — a record id, a resolved authorization scope, a filter — are passed to `build(...)` as arguments, not held as constructor state. The controller resolves authorization/scope and hands the result in.
- **Composes queries across contexts.** A page spanning two bounded contexts (e.g. an entity's own fields + that actor's roles/permissions from the authorization context) is exactly what a view model is for: the Interface layer is the only layer allowed to know both contexts. Each context still exposes its data through its own query / read model — the view model only *assembles* their DTO output, it does not query.

### Skeleton

```php
final readonly class DashboardPage
{
    public function __construct(
        private EmployeesQuery $employeesQuery,
        private ActivityQuery $activityQuery,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $employeeId): array
    {
        return [
            'employee' => $this->employeesQuery->profile($employeeId)->toArray(),
            'activity' => $this->activityQuery->recentFor($employeeId)->toArray(),
            'features' => [
                'two_factor_required' => config('auth.two_factor_required'),
            ],
        ];
    }
}
```

The controller **method-injects** the view model and hands it the resolved input:

```php
public function show(string $employee, DashboardPage $page): Response
{
    $this->authorize(...);   // authorization stays in the controller

    return Inertia::render('dashboard', $page->build($employee));
}
```

Method injection keeps the controller constructor focused on its write collaborators; each action resolves only the page it renders.

### One `build()` method, even for trivial pages

Every page view model exposes exactly `build(...)` — never `toArray()`, `forX()`, `present()`, or a per-page verb. Consistency is the point: a reader of any controller knows the props come from `$page->build(...)` without learning a new method name per screen. A near-empty page (a single list passthrough) still uses `build()`; the uniformity is worth the one-line class.

## The serializer: read models, not the aggregate

A view model assembles **read-model DTOs**, never the write-side aggregate. Lists, tables, dashboards, and reports come straight from a [Read model](../application/read-models.md) — if a page *is* a single read, the controller can pass the read model's result directly and skip the view model. The aggregate is the write side and is off-limits to the read path: a view model that reaches for `Model::find()` to read fields is a bug; that read belongs in a query.

> Read models are Application-layer query results; view models are the delivery-layer assembly of them for one page. The same read model can feed a verbose `AdminWeb` page and a terse `PartnerApi` page through different view models.

## Eloquent API resources (optional)

An Eloquent API resource (`<Model>Resource extends JsonResource`) is an alternative **single-entity** serializer — one model projected to a thin shape. It is **optional**: a project may standardize entirely on read-model DTOs + view models and not use resources at all. Record that choice in the project overlay. Where resources *are* used:

- Only for **one entity's details**, never lists/tables/dashboards (those are read models).
- Lives at `Interfaces/<EntryPoint>/Resources/<Model>Resource.php`.
- **No queries, no `app(...)`.** Hand the resource an already-loaded model. Cross-context data (e.g. an actor's roles from another context) is **not** a resource's job — pulling it via `app(SomeQuery::class)` inside `toArray()` is the anti-pattern this rule exists to stop. That composition belongs in a view model.

```php
final class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'two_factor_enabled' => $this->hasConfirmedTwoFactor(),
        ];
    }
}
```

Use the model's read-only state helpers (`hasConfirmedTwoFactor()` etc.) instead of recomputing the predicate inside the resource. See [Models § what belongs on a model](../data/models.md#what-belongs-on-a-model) for the model side of that contract.

## Why not inline

```php
// bad — shape leaks across controllers and breaks twice
return Inertia::render('employees/edit', [
    'employee' => [
        'id' => $employee->id,
        'name' => $employee->name,
        'two_factor_enabled' => $employee->two_factor_secret !== null
            && $employee->two_factor_confirmed_at !== null,
        // …
    ],
    'roles' => Role::all()->pluck('name'),   // and a cross-context query, inline
]);
```

This becomes a maintenance hazard the moment the same shape is needed on a second page, and it buries a cross-context read in the controller. The fix is a per-page view model:

```php
// good — one home for the page's shape and its cross-context composition
return Inertia::render('employees/edit', $page->build($employee));
```

The same applies to `HandleInertiaRequests::share()`. If the shared `auth.user` payload is anything more than `id` + `email`, shape it through a resource or a small shared view model — not an inline array.

## What does not belong in view data

- **Authorization decisions.** A view model may *include* a resolved boolean flag (`'can_disable' => ...`) handed to it, but the decision itself is made at the controller boundary via the authorization contract — never computed inside view data. See [Authorization](../authorization.md).
- **Side effects.** View data is a transformation, not a write surface.
- **Queries and service location.** A view model composes queries that were **injected**; it does not call `Model::query()` or `app(...)`. A resource receives an already-loaded model and adds no queries. Any read belongs in a [query](../application/read-models.md).

## See also

- [Read models](../application/read-models.md) — the right home for list / table / dashboard / report data, and the DTOs view models assemble.
- [Controllers](controllers.md) — where view models are method-injected and read-model results are constructed.
- [Models](../data/models.md) — the source of read-only state helpers resources rely on.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — view-data shaping is the read-side counterpart of action invocation.