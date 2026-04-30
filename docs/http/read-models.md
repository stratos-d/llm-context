# Read models

> **Owns**
>
> - The split between aggregates (writes) and read models (queries)
> - Where read models live
> - Read-model skeleton and DTO shape
> - When to use a read model vs. an Eloquent API resource
> - The "no aggregate hydration for screens" rule
>
> **Forbids**
>
> - Writes (read models are read-only)
> - Returning Eloquent models or collections (return DTOs)
> - Sharing a read model with another screen that has different shape needs
> - Loading aggregates just to extract a few fields for a list page
>
> **See also**: [View data](view-data.md), [Architecture](../architecture.md), [Repositories](../data/repositories.md), [Builders](../data/builders.md), [Glossary](../glossary.md)

A read model is a **query-shaped class** that produces results for one screen. It hits the DB directly with a tuned query and returns plain DTOs. It does not load aggregates, does not reuse the model's casts, and does not share its shape with any other screen.

The pattern is **CQRS-lite**: write side and read side are separate code, even though they share one database. Aggregates exist for mutation; read models exist for projection.

> Names like `EmployeeListQuery`, `DocumentDashboardQuery`, `ActivityFeedQuery` are illustrative.

## When to use a read model

Use a read model when **any** of the following is true:

- The screen renders a list, table, dashboard, or report (anything paginated, sorted, filtered).
- The query joins across two or more aggregates.
- The query joins across two or more bounded contexts.
- The page needs five or more fields from one aggregate that would require eager-loading several relations.
- The query is hot (high traffic) and aggregate hydration is wasteful.

Use an Eloquent API resource (see [View data](view-data.md)) when the page renders **one entity's details** and the response is a thin projection of one already-loaded model. The boundary is rough: detail screens → resource; list screens → read model.

## Where they live

Read models are part of the **delivery layer** — they exist to feed a specific screen, not to express domain rules.

- **Single-context read models** (data drawn from one bounded context) live at `Domains/<ContextName>/ReadModels/<Verb><Noun>Query.php`. Example: `Domains/Employees/ReadModels/EmployeeListQuery.php`.
- **Cross-context read models** (data drawn from two or more contexts via a join) live at `Application/<UseCase>/ReadModels/<Verb><Noun>Query.php`. Example: `Application/ContentOps/ReadModels/DocumentDashboardQuery.php`.
- **Per-entry-point read models** (when AdminWeb and PartnerApi need different shapes for the "same" data) live at `Interfaces/<EntryPoint>/ReadModels/<Verb><Noun>Query.php`. Use sparingly; prefer one canonical read model with two thin formatters where possible.

The DTOs each query returns live next to the query class:

```text
app/Domains/Employees/ReadModels/
├── EmployeeListQuery.php
├── EmployeeListRow.php
└── EmployeeListPage.php
```

## Naming

| Construct | Pattern | Example |
|---|---|---|
| Query class | `<Verb><Noun>Query` | `EmployeeListQuery`, `DocumentDashboardQuery`, `FindEmployeeOverviewQuery` |
| Single-row DTO | `<Noun>Row` | `EmployeeListRow`, `ActivityFeedRow` |
| Page / paginated result DTO | `<Noun>Page` | `EmployeeListPage`, `DocumentDashboardPage` |
| Aggregate-projection DTO (single entity, projection-only) | `<Noun>Overview` | `EmployeeOverview`, `DocumentOverview` |

The `Query` suffix on the *class* is intentional and the only place the project uses a generic suffix — it disambiguates the read-side query from the write-side aggregate command (which is a verb-named action).

## Skeleton — list query

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\ReadModels;

use App\Domains\Employees\Builders\EmployeeBuilder;
use App\Domains\Employees\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;

final class EmployeeListQuery
{
    public function __construct() {}

    public function execute(EmployeeListFilter $filter, int $page, int $perPage): EmployeeListPage
    {
        /** @var LengthAwarePaginator<EmployeeListRow> $paginator */
        $paginator = Employee::query()
            ->select([
                'id',
                'name',
                'email',
                'last_login_at',
                'two_factor_confirmed_at',
                'disabled_at',
            ])
            ->when($filter->search !== null, fn (EmployeeBuilder $q) =>
                $q->searchableBy($filter->search)
            )
            ->when($filter->status !== null, fn (EmployeeBuilder $q) =>
                $q->withStatus($filter->status)
            )
            ->newest()
            ->paginate(perPage: $perPage, page: $page)
            ->through(fn (Employee $employee): EmployeeListRow => new EmployeeListRow(
                id: $employee->getKey(),
                name: $employee->name,
                email: $employee->email,
                lastLoginAt: $employee->last_login_at?->toIso8601String(),
                twoFactorEnabled: $employee->two_factor_confirmed_at !== null,
                disabled: $employee->disabled_at !== null,
            ));

        return new EmployeeListPage(
            rows: $paginator->items(),
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }
}
```

Rules:

- `final`.
- Single public `execute(...)` method, same as actions on the write side.
- Returns a DTO (`<Noun>Page`), never the paginator or Eloquent collection.
- Uses the **builder** for filter composition (`searchableBy`, `withStatus`, `newest`). Filter logic lives on the builder; the query class composes filters and shapes the result.
- Selects only the columns the screen needs. A read model that does `select('*')` defeats its purpose.
- Eager-loads only when the projection genuinely needs a relation; prefer joining and selecting columns directly when joining is cleaner.

## Skeleton — DTOs

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\ReadModels;

final readonly class EmployeeListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $lastLoginAt,
        public bool $twoFactorEnabled,
        public bool $disabled,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\ReadModels;

final readonly class EmployeeListPage
{
    /** @param array<int, EmployeeListRow> $rows */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $perPage,
        public int $total,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, current_page: int, per_page: int, total: int} */
    public function toArray(): array
    {
        return [
            'rows' => array_map(fn (EmployeeListRow $row): array => (array) $row, $this->rows),
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
        ];
    }
}
```

Rules:

- DTOs are `final readonly class`. Public readonly properties.
- The page DTO owns the `toArray()` shape that Inertia receives. The controller does not reshape it inline.
- Field names in the PHP DTO use `camelCase`; the JSON shape `toArray()` produces uses `snake_case` (matching the JSON convention used elsewhere in Inertia props).
- The DTO is per-screen. If two screens need different fields, write two DTOs — they may share a query class internally if the underlying data is the same, but they expose two separate result types.

## Filter DTO

When a list query takes more than two filter parameters, group them in a filter DTO:

```php
final readonly class EmployeeListFilter
{
    public function __construct(
        public ?string $search = null,
        public ?EmployeeStatus $status = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->string('search')->toString() ?: null,
            status: EmployeeStatus::tryFrom($request->string('status')->toString()),
        );
    }
}
```

Filter DTOs live next to the query class. The factory method (`fromRequest`) keeps the controller shape thin.

## Cross-context read models

A read model that joins data from two contexts has a difficult ownership question: which context owns the join? The rule:

- **The join lives in `Application/<UseCase>/ReadModels/`**, not in either `Domains/<X>/ReadModels/`.
- Each contributing context exposes the columns it owns (often through its own narrower read model or its builder); the cross-context query composes them.
- Cross-context read models **may use a single SQL join** for performance — they are explicitly the place where the cross-context-no-joins rule of [Architecture § cross-context communication](../architecture.md#cross-context-communication) is relaxed. The exception is justified because read models are *projections*, not domain logic; they do not write.

```text
app/Application/ContentOps/ReadModels/
├── DocumentDashboardQuery.php     ← joins Documents + Employees + Activity
├── DocumentDashboardRow.php
└── DocumentDashboardPage.php
```

## Per-entry-point read models

When AdminWeb and PartnerApi need different shapes for the same underlying data, the default is **one read model with two formatters**:

- The read model owns the query and returns a rich DTO with all the fields any consumer might need.
- Each entry point's controller/view-model picks the subset it wants, formatting via its own resource or view model.

If the queries themselves diverge enough that one shape would force loading data the other doesn't need, split into two query classes and place each in `Interfaces/<EntryPoint>/ReadModels/`.

## What does not belong in a read model

- **Writes.** Any method that mutates state belongs in an action. A read model is read-only.
- **Business rules.** "Should we show this row?" is a domain question; it lives on the builder (`active()`, `withinAuthorityOf($approver)`) or in a value object, not in the query class.
- **Authorization.** "Can this user see this row?" is policy-shaped. The query receives the actor as a parameter when filtering by visibility (`$query->execute($actor, $filter, ...)`), and the actor-shape filtering is implemented in builder methods.
- **Domain events.** Read models do not emit events. They project.
- **Eloquent collections in the return type.** Always DTOs.

## Testing read models

Read models are tested as integration tests because they care about the query running against a real database:

```text
tests/Integration/Domains/<ContextName>/ReadModels/<Verb><Noun>QueryTest.php
```

The test seeds rows via factories, calls the query, and asserts on the DTO shape and ordering. Do not unit-test read models with a fake database; the value of the read-model layer is *that the SQL is correct*, and only an integration test proves that.

## See also

- [View data](view-data.md) — when to use an Eloquent API resource (single-entity detail) vs. a read model (lists, dashboards, reports).
- [Architecture § cross-context communication](../architecture.md#cross-context-communication) — the rule read models are explicitly allowed to relax.
- [Builders](../data/builders.md) — the filter-composition mechanism read models build on top of.
- [Repositories](../data/repositories.md) — the write-side counterpart; do not confuse the two.
- [Glossary](../glossary.md) — definition of *read model* and *CQRS-lite*.
