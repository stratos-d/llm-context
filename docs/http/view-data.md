# View data

> **Owns**
>
> - Inertia page-prop shaping rules
> - When to use Eloquent API resources (single-entity detail)
> - When to use view models / presenters (multi-source pages)
> - The boundary with [Read models](read-models.md) (lists, dashboards, reports)
>
> **Forbids**
>
> - Inline anonymous-array shaping in controllers
> - Page-prop shaping inside `HandleInertiaRequests` for non-trivial actor data
> - Authorization checks dressed up as view data
> - Loading aggregates to render lists or dashboards — see [Read models](read-models.md)
>
> **See also**: [Controllers](controllers.md), [Read models](read-models.md), [Models](../data/models.md), [Architecture](../architecture.md)

When a controller renders an Inertia page with non-trivial data, the page-props array is **shaped by a dedicated class** — never inline. There are three shaping options, picked by data source:

- One entity's details → an [Eloquent API resource](#the-two-shaping-options).
- A page composed from multiple sources → a [view model](#the-two-shaping-options).
- A list / table / dashboard / report → a [read model](read-models.md).

> Names like `Employee` / `EmployeeResource` / `DashboardViewModel` are illustrative.

## The two shaping options

Pick the one that fits the data source:

1. **Eloquent API resource** (`<Model>Resource extends JsonResource`)
   - For **one entity's details** — a single model, with optional eager-loaded relations, projected to a thin response shape.
   - **Not** for lists, tables, dashboards, or anything paginated. Those go through a [read model](read-models.md).
   - Lives next to the other HTTP code for its entry point at `Interfaces/<EntryPoint>/Resources/<Model>Resource.php`.

2. **View model / presenter** (`<Page>ViewModel`)
   - For pages assembled from multiple sources (multiple models, plus computed values, plus config).
   - Plain class with public properties or a `toArray()` method.
   - Lives at `Interfaces/<EntryPoint>/ViewModels/<Page>ViewModel.php`.
   - May internally compose resources and read-model results.

For the third case — list / table / dashboard / report screens — use a [read model](read-models.md). Read models hit the database directly with tuned queries and return DTOs; they do not load aggregates. This keeps list pages from hydrating models they don't need.

> Resources, view models, and read models are part of the **delivery layer**, not the domain. The same `Employee` model can have a different `EmployeeResource` for `AdminWeb` (verbose) and `PartnerApi` (terse). They share the model — they do not share the response shape.

## Why not inline

```php
// bad — shape leaks across controllers and breaks twice
return Inertia::render('employees/show', [
    'employee' => [
        'id' => $employee->id,
        'name' => $employee->name,
        'two_factor_enabled' => $employee->two_factor_secret !== null
            && $employee->two_factor_confirmed_at !== null,
        // …
    ],
]);
```

This becomes a maintenance hazard the moment the same shape is needed on a second page (e.g. dashboard header). Two ad-hoc copies will drift. The fix:

```php
// good — single source of truth for the shape
return Inertia::render('employees/show', [
    'employee' => EmployeeResource::make($employee),
]);
```

The same applies to `HandleInertiaRequests::share()`. If the shared `auth.user` payload is anything more than `id` + `email`, build a resource:

```php
'auth' => [
    'user' => $request->user() === null ? null : EmployeeResource::make($request->user()),
],
```

## Eloquent API resource skeleton

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

## View model skeleton

```php
final class DashboardViewModel
{
    public function __construct(
        private Employee $employee,
        private ActivitySummary $activitySummary,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee' => EmployeeResource::make($this->employee)->resolve(),
            'activity' => $this->activitySummary->toArray(),
            'features' => [
                'two_factor_required' => config('auth.two_factor_required'),
            ],
        ];
    }
}
```

Compose resources inside view models — do not duplicate the resource's shape.

## What does not belong in view data

- **Authorization decisions.** A resource may include a boolean flag like `'can_disable' => $request->user()->can('disable', $employee)`, but the resource is not the place for the authorization logic itself; that lives in policies.
- **Side effects.** A resource is a transformation, not a write surface.
- **Database queries beyond what is already loaded.** If a resource lazy-loads a relation, it produces N+1 queries. Eager-load in the controller (or via a builder helper, see [Builders](../data/builders.md)) and then hand the loaded model to the resource.

## See also

- [Read models](read-models.md) — the right home for list / table / dashboard / report data.
- [Controllers](controllers.md) — where resources / view models / read-model results are constructed.
- [Models](../data/models.md) — the source of read-only state helpers resources rely on.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — view-data shaping is the read-side counterpart of action invocation.
