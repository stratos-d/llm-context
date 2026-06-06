# Request data

> **Owns**
>
> - Request-backed `spatie/laravel-data` objects
> - Typed input contracts for write endpoints
> - The request → Application input mapping (`toInput()`)
> - Custom value-rule objects backed by a context reader
> - The boundary between `authorize()` and policies
> - One-request-data-object-per-endpoint rule
>
> **Forbids**
>
> - Persistence / mutation
> - Calling actions
> - Inline `$request->validate(...)` — validation belongs in the data object
> - Reusing a view-data/options method (`array_column($reader->options(...), 'name')`) to derive a validation whitelist
> - Existence queries outside a reader (`Rule::exists(...)` against another context's tables)
>
> **See also**: [Controllers](controllers.md), [Actions](../actions.md), [View data](view-data.md), [Architecture](../architecture.md)

All write-endpoint validation goes through a request data object. Inline `$request->validate([...])` in a controller is a guidelines violation, period.

> Names like `LoginData` / `<Verb><Noun>Data` are illustrative.

## Where they live

Request data objects live at `Interfaces/<EntryPoint>/Requests/<Group>/<Name>Data.php` — alongside the controllers that consume them. They never live inside `Domains/`. Validation rules are part of the **delivery layer**: the same domain action may be called by `AdminWeb` (with browser-friendly rules) and `PartnerApi` (with stricter API rules), and each entry point owns its own request data object. They share an action, not a request contract.

Request data validates and shapes HTTP input. Controllers translate request data into scalars, value objects, identity value objects, or Application input DTOs. Application actions never depend on entry-point-specific request data classes.

## Skeleton

```php
final class <Verb><Noun>Data extends Data
{
    public function remember(): bool
    {
        return $this->remember;
    }

    public function __construct(
        #[Email]
        public string $email,
        public string $password,
        public bool $remember = false,
    ) {}
}
```

## Rules

- **One request data object per HTTP endpoint.** Two endpoints with truly identical rules may share, but that is rare. Sharing is a smell that the endpoints are doing the same thing.
- **Typed properties are the default.** Controllers should read validated properties from the data object, not call `$request->string(...)`, `$request->input(...)`, or `$request->boolean(...)` inline.
- **Validation attributes first.** Prefer promoted properties plus validation attributes (`#[Email]`, `#[Size(6)]`, `#[Max(10)]`, ...). Drop down to `public static function rules(): array` only when attributes cannot express the rule cleanly.
- **Helper methods are optional, not mandatory.** Add methods like `credentials(): EmployeeCredentials` only when they improve the call site or package multiple values into a stronger domain input.
- **Inject `Request` alongside the data object when you need raw HTTP concerns.** Session access, file uploads, headers, IP address, and the authenticated actor still come from `Illuminate\Http\Request`; the request data object owns validated body/query inputs.
- **No persistence, no action calls.** The data object only validates and shapes. Any write is the action's job.
- **Do not pass request data objects into Application actions.** For any multi-field write this is the default: the request object exposes a `toInput()` method returning an Application input DTO co-located with the action (see [From request to action](#from-request-to-action)). Only a trivial one- or two-scalar write skips the DTO and passes scalars/value objects directly.
- **`final` class.** Same as the rest of the project — see [Conventions § class modifiers](../conventions.md#class-modifiers).

## From request to action

A write endpoint flows through collaborators that each own exactly one concern. The request object validates HTTP input and then **maps itself** to a framework-agnostic Application input DTO; the action sees only that DTO.

```text
UpdateEmployeeData     (Interfaces/<EntryPoint>/Requests) — validates HTTP input
    → toInput()
UpdateEmployeeInput    (co-located with the action)       — framework-agnostic use-case input
    → execute()
UpdateEmployeeAction   (Application)                      — orchestrates the write
```

`toInput()` is the single place that knows both shapes. The controller never hand-assembles the input, and the action never imports the request class — so a second entry point (`PartnerApi`, CLI) reuses the action with its own request rules.

```php
// Interfaces/AdminWeb/Requests/Employees/UpdateEmployeeData.php  — delivery: validation + mapping
final class UpdateEmployeeData extends Data
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        #[Max(255)] public string $name,
        #[Email] public string $email,
        public ?string $portraitUrl,
        public bool $emailVerified,
        public bool $disabled,
        public array $roles,
        public array $permissions,
    ) {}

    /**
     * Dynamic rules attributes can't express — unique-with-ignore, a whitelist sourced
     * from another context — resolve their collaborators from the container here.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => [Rule::unique('employees', 'email')->ignore(request()->route('employee'))],
            'roles.*' => [Rule::in(/* names from the access read surface */)],
            'permissions.*' => [Rule::in(/* names from the access read surface */)],
        ];
    }

    public function toInput(): UpdateEmployeeInput
    {
        return new UpdateEmployeeInput(
            name: $this->name,
            email: $this->email,
            portraitUrl: $this->portraitUrl,
            emailVerified: $this->emailVerified,
            disabled: $this->disabled,
            roles: $this->roles,
            permissions: $this->permissions,
        );
    }
}
```

```php
// co-located with the action — no validation, no framework types, no Data base class
final readonly class UpdateEmployeeInput
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $portraitUrl,
        public bool $emailVerified,
        public bool $disabled,
        public array $roles,
        public array $permissions,
    ) {}
}
```

```php
// the controller stays glue: validation is automatic, then authorize → load → hand off
public function update(string $employee, UpdateEmployeeData $data): RedirectResponse
{
    $this->authorize(/* resource-level check */);

    $employee = $this->employeeRepository->findWithDisabledOrFail($employee);

    $this->updateEmployeeAccessAction->execute($employee, $data->toInput());

    return to_route('employees.edit', $employee);
}
```

### Input DTO vs scalars

- **Multi-field writes (three or more inputs, or any `list`/array): define a `<Verb><Noun>Input` DTO and map via `toInput()`.** This is the default — it tames the action's parameter list and gives the controller one hand-off.
- **One or two trivial scalars: skip the DTO** and pass them directly (`execute($id, $data->reason)`). A DTO for a single `reason` string is ceremony.
- The input DTO is a plain `final readonly class`, **not** a `spatie/laravel-data` object — keep the Data base class (and its validation/casting machinery) in the delivery layer. It is co-located with the action; see [Actions § signature rules](../actions.md#signature-rules).

## Validating against domain values

When a field must be one of a set the domain owns — an assignable role, a valid status, an existing category — **do not** inline the lookup in `rules()`, and **do not** reach for `Rule::exists(...)` against another context's tables. Both are wrong for the same reason: a query is executing outside that context's read surface, and the validation becomes coupled to table layout or, worse, to a view-data method.

The recurring anti-pattern:

```php
// bad — borrows a UI-options method, strips it with array_column, and runs the query inline.
// Validation now silently depends on roleOptions()'s shape and its 'name' key.
'roles.*' => ['string', Rule::in(array_column(app(AccessQuery::class)->roleOptions($guard), 'name'))],
```

Instead, wrap the check in a **custom rule object** that constructor-injects the context [query](../application/read-models.md) and asks it a purpose-built question. The query execution stays in the query object; the rule is reusable across every request object that needs it (so there is no per-endpoint duplication); and `rules()` touches the container once — to resolve the rule, not to fetch data.

```php
// Interfaces/<EntryPoint>/Rules/AssignableEmployeeRole.php
final readonly class AssignableEmployeeRole implements ValidationRule
{
    public function __construct(
        private AccessQuery $accessQuery,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, $this->accessQuery->assignableRoleNames(Guard::Employee->value), true)) {
            $fail('The selected :attribute is not an assignable role.');
        }
    }
}
```

```php
// the query exposes a purpose-built method — not the UI-options method
/** @return list<string> */
public function assignableRoleNames(string $guard): array { /* … */ }
```

```php
// in the request object — intent-revealing, no array_column, no duplication
'roles.*' => ['string', app(AssignableEmployeeRole::class)],
```

- Custom rules live at `Interfaces/<EntryPoint>/Rules/<Name>.php` — they are delivery validation, even though they consult a domain query.
- The rule is `final readonly` and constructor-injects the query; resolving it with `app(Rule::class)` inside the static `rules()` is the one acceptable container touch (rules cannot be injected — see below).
- Add a **purpose-built query method** (`assignableRoleNames`) rather than calling a view-data/options method and reshaping it. Validation asks its own question.

## `authorize()` vs policies

`public static function authorize(): bool` covers **caller-level** checks — does this kind of caller, in this kind of context, have permission to attempt this kind of action?

```php
public static function authorize(): bool
{
    return request()->user()?->can('manage-employees') ?? false;
}
```

That's the correct ceiling for `authorize()`. It deals only with the request itself.

**Resource-level** checks — "can this user edit *that specific* employee" — do **not** belong in `authorize()` (the resource isn't loaded yet) and they do **not** belong in the action either (Application actions stay framework-agnostic, no `Gate` facade). They belong in the **controller**, immediately after route-model binding, via a [Policy](https://laravel.com/docs/authorization#creating-policies) auto-discovered from the aggregate:

```php
// inside the controller
public function __invoke(Employee $employee, DisableEmployeeData $data): RedirectResponse
{
    Gate::authorize('disable', $employee);

    $this->disableEmployeeAction->execute(
        employeeId: EmployeeId::fromString((string) $employee->getKey()),
        reason: $data->reason,
    );

    return redirect()->route('employees.index');
}
```

The full rule — where Policies live, what each authorization layer owns, the difference between caller-level / resource-level / business-rule authorization — lives in [Authorization](../authorization.md). This file owns only the request-data half: `authorize()` is for caller-level checks, and only those.

When `authorize()` would have nothing meaningful to check, omit it or return `true` and let the controller enforce resource-level authorization.

## See also

- [Controllers](controllers.md) — the layer that injects the data object and that runs `Gate::authorize` for resource-level checks.
- [Actions](../actions.md) — the layer that consumes the typed inputs / DTOs the request produces.
- [Authorization](../authorization.md) — the full authorization rule: Policy placement, caller-level vs resource-level vs business-rule.
- [View data](view-data.md) — for read-only endpoints, the equivalent layer.
- [Anti-patterns](../anti-patterns.md) — `$request->validate([...])` inside a controller is a flagged signal.
