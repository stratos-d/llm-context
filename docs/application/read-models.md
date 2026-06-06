# Read Models & Queries

> **Owns**
>
> - The data-access rule: where database queries may execute
> - The `{Context}Query` read surface and its placement (`Queries/`)
> - Read-model DTOs and page/row shapes, and their placement (`ReadModels/`)
> - Filter/input DTO placement
> - The read-side flow from controller to response
>
> **Forbids**
>
> - Query execution outside a `{Context}Query` or a [`Repository`](../data/repositories.md)
> - Writes (queries are read-only)
> - Returning Eloquent models or collections (return read-model DTOs)
> - One query class per screen (reads are grouped per context)
> - Loading aggregates just to render list/dashboard/report screens
> - Putting the query surface and its DTOs in the same folder
>
> **See also**: [Architecture](../architecture.md), [Repositories](../data/repositories.md), [View data](../http/view-data.md), [Builders](../data/builders.md), [Cross-context communication](../cross-context.md)

This project is **CQRS-lite**: the write side (aggregates behind repositories) and the read side (read models behind queries) are separate code over one database. Aggregates exist for mutation; queries exist for projection and read-only decision support.

Two distinct things make up the read side, and they live in **separate folders**:

- the **query** — the surface that *executes* reads (`{Context}Query`, in `Queries/`); behavior, with dependencies.
- the **read model** — the DTO a query *returns* (`<Noun>Row`, `<Noun>Page`, `<Noun>Overview`, in `ReadModels/`); immutable data, no behavior.

A query is a service; a read model is data. Keeping them in one folder conflates the two — don't.

## The data-access rule

**Database query *execution* lives only in a `{Context}Query` (reads) or a [`Repository`](../data/repositories.md) (writes).** Controllers, actions, resources, policies, services, and authorizers never execute a query — they call a query object or a repository. "Execution" means the terminal builder/Eloquent calls (`get`, `first`, `exists`, `paginate`, `pluck`, `find*`, `save`, `delete`) and equivalent vendor write bindings.

The point is auditability: row-level security, tenant scoping, and authorization-input correctness are reviewable only when every query lives in a known, named place. A query in a controller is invisible to that review.

### Exemptions

The rule targets application/production domain code. These may query directly:

- **Tests and seeders** — they set up and inspect state.
- **Authentication guard/session resolution** — the framework guard and the "current actor" providers resolve identity; that is authentication plumbing, not domain data access.
- **Vendor internals** — a wrapped SDK's own queries are vendor code; our call sites still route through a query object or repository.

## The context query

Reads are grouped **per context** in one query object, not split one-class-per-screen. It hits the DB with tuned queries and returns **read-model DTOs, scalars, or booleans** — never Eloquent models or builders.

- One `{Context}Query` per context: `EmployeesQuery`, `AccessQuery`.
- Methods are verb-named and shaped per consumer: `list(...)`, `findOverview(...)`, `roleNames(...)`, `hasFullScope(...)`.
- It serves **every** read consumer: screen payloads *and* decision support for an authorizer (which calls its booleans and executes no queries itself).
- Split a query object by **noun sub-area** (`EmployeeAccessQuery`) only when one grows unwieldy — never by verb.
- Inject it with a matching property name: `EmployeesQuery $employeesQuery` (see [Conventions](../conventions.md)).

> Names like `EmployeesQuery`, `AccessQuery` are illustrative.

## Where they live

- **Single-context query** → `Domains/<Context>/Queries/<Context>Query.php`.
- **Cross-context query** (data joined from two or more contexts) → `Application/<UseCase>/Queries/<UseCase>Query.php`.
- **Read-model DTOs** the query returns → `Domains/<Context>/ReadModels/` (or `Application/<UseCase>/ReadModels/` for cross-context): `<Noun>Row.php`, `<Noun>Page.php`, `<Noun>Overview.php`. The query imports them from there.

HTTP request objects stop at `Interfaces/`. Query inputs (filters) are pure PHP objects.

## Query skeleton

```php
namespace App\Domains\Employees\Queries;

final class EmployeesQuery
{
    public function list(EmployeeListFilter $filter, int $page, int $perPage): EmployeeListPage
    {
        $paginator = Employee::query()
            ->select(['id', 'name', 'email', 'disabled_at'])
            ->when($filter->search !== null, fn (EmployeeBuilder $query) => $query->searchableBy($filter->search))
            ->newest()
            ->paginate(perPage: $perPage, page: $page)
            ->through(fn (Employee $employee): EmployeeListRow => new EmployeeListRow(
                id: $employee->getKey(),
                name: $employee->name,
                email: $employee->email,
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
- Methods named by intent; return read-model DTOs / scalars / booleans, never paginator / model / collection types.
- Select only the columns the projection needs.
- Use **builders** for reusable same-context query constraints; the query object composes them and shapes the result.
- Keep request parsing out of the query object.

## Filter DTOs

Filter DTOs are pure input objects with no HTTP dependency. The controller or request-data object builds the filter from validated input and passes it in:

```php
final readonly class EmployeeListFilter
{
    public function __construct(
        public ?string $search = null,
        public ?EmployeeStatus $status = null,
    ) {}
}
```

Do not add `fromRequest(Request $request)` to a filter or query object.

## Read-model DTO shape

Read models are plain immutable data in `ReadModels/`, returned by the query:

```php
namespace App\Domains\Employees\ReadModels;

final readonly class EmployeeListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $disabled,
    ) {}
}

final readonly class EmployeeListPage
{
    /** @param list<EmployeeListRow> $rows */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $perPage,
        public int $total,
    ) {}
}
```

The response / resource / view model owns final serialization. Read-model DTOs stay framework-free.

## Cross-context reads

Cross-context *write* rules are strict; reads are the controlled exception. A cross-context query (in `Application/<UseCase>/Queries/`) may join across context tables for read-only projection performance. It must return read-model DTOs and must not mutate state or leak foreign aggregates to callers. If another context needs the same read as a public contract, publish it deliberately and treat its DTO shape as a contract.

## Testing

Test query objects with integration tests against the database — their value is that the SQL, filtering, ordering, pagination, and DTO projection are correct, which only a real query proves.

## See also

- [Repositories](../data/repositories.md) — the write-side counterpart and the other half of the data-access rule.
- [View data](../http/view-data.md) — response / page-prop shaping.
- [Builders](../data/builders.md) — reusable Eloquent constraints queries compose.
- [Cross-context communication](../cross-context.md) — published queries and context boundaries.
