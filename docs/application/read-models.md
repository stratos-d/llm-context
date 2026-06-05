# Read Models (Context Readers)

> **Owns**
>
> - The data-access rule: where database queries may execute
> - The `{Context}Reader` read surface and its placement
> - Read-model DTOs and page/row shapes
> - Filter/input DTO placement
> - The read-side flow from controller to response
>
> **Forbids**
>
> - Query execution outside a `{Context}Reader` or a [`Repository`](../data/repositories.md)
> - Writes (readers are read-only)
> - Returning Eloquent models or collections (return DTOs)
> - One reader class per screen (reads are grouped per context)
> - Loading aggregates just to render list/dashboard/report screens
>
> **See also**: [Architecture](../architecture.md), [Repositories](../data/repositories.md), [View data](../http/view-data.md), [Builders](../data/builders.md), [Cross-context communication](../cross-context.md)

This project is **CQRS-lite**: the write side (aggregates behind repositories) and the read side (projections behind readers) are separate code over one database. Aggregates exist for mutation; readers exist for projection and read-only decision support.

## The data-access rule

**Database query *execution* lives only in a `{Context}Reader` (reads) or a [`Repository`](../data/repositories.md) (writes).** Controllers, actions, resources, policies, services, and authorizers never execute a query — they call a reader or a repository. "Execution" means the terminal builder/Eloquent calls (`get`, `first`, `exists`, `paginate`, `pluck`, `find*`, `save`, `delete`) and equivalent vendor write bindings.

The point is auditability: row-level security, tenant scoping, and authorization-input correctness are reviewable only when every query lives in a known, named place. A query in a controller is invisible to that review.

### Exemptions

The rule targets application/production domain code. These may query directly:

- **Tests and seeders** — they set up and inspect state.
- **Authentication guard/session resolution** — the framework guard and the "current actor" providers resolve identity; that is authentication plumbing, not domain data access.
- **Vendor internals** — a wrapped SDK's own queries are vendor code; our call sites still route through a reader or repository.

## The Context Reader

Reads are grouped **per context** in one reader, not split one-class-per-screen. A reader hits the DB with tuned queries and returns **DTOs, scalars, or booleans** — never Eloquent models or builders.

- One `{Context}Reader` per context: `EmployeesReader`, `AccessReader`.
- Methods are verb-named and shaped per consumer: `list(...)`, `findOverview(...)`, `roleNames(...)`, `hasFullScope(...)`.
- A reader serves **every** read consumer: screen payloads *and* decision support for an authorizer (which calls reader booleans and executes no queries itself).
- Split a reader by **noun sub-area** (`EmployeeAccessReader`) only when one grows unwieldy — never by verb.

> Names like `EmployeesReader`, `AccessReader` are illustrative.

## Where they live

- **Single-context readers** live at `Domains/<Context>/ReadModels/<Context>Reader.php`.
- **Cross-context readers** (data joined from two or more contexts) live at `Application/<UseCase>/ReadModels/<UseCase>Reader.php`.
- The DTOs a reader returns live next to it (`<Noun>Row.php`, `<Noun>Page.php`, `<Noun>Overview.php`).

HTTP request objects stop at `Interfaces/`. Reader inputs (filters) are pure PHP objects.

## Reader skeleton

```php
final class EmployeesReader
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
- Methods named by intent; return DTOs / scalars / booleans, never paginator / model / collection types.
- Select only the columns the projection needs.
- Use **builders** for reusable same-context query constraints; the reader composes them and shapes the result.
- Keep request parsing out of the reader.

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

Do not add `fromRequest(Request $request)` to a filter or reader.

## DTO shape

```php
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

The response / resource / view model owns final serialization. Reader DTOs stay framework-free.

## Cross-context reads

Cross-context *write* rules are strict; reads are the controlled exception. A cross-context reader (in `Application/<UseCase>/ReadModels/`) may join across context tables for read-only projection performance. It must return DTOs and must not mutate state or leak foreign aggregates to callers. If another context needs the same read as a public contract, publish it deliberately and treat its DTO shape as a contract.

## Testing

Test readers with integration tests against the database — their value is that the SQL, filtering, ordering, pagination, and DTO projection are correct, which only a real query proves.

## See also

- [Repositories](../data/repositories.md) — the write-side counterpart and the other half of the data-access rule.
- [View data](../http/view-data.md) — response / page-prop shaping.
- [Builders](../data/builders.md) — reusable Eloquent constraints readers compose.
- [Cross-context communication](../cross-context.md) — published readers and context boundaries.
