# Request data

> **Owns**
>
> - Request-backed `spatie/laravel-data` objects
> - Typed input contracts for write endpoints
> - The boundary between `authorize()` and policies
> - One-request-data-object-per-endpoint rule
>
> **Forbids**
>
> - Persistence / mutation
> - Calling actions
> - Inline `$request->validate(...)` — validation belongs in the data object
>
> **See also**: [Controllers](controllers.md), [Actions](../actions.md), [View data](view-data.md), [Architecture](../architecture.md)

All write-endpoint validation goes through a request data object. Inline `$request->validate([...])` in a controller is a guidelines violation, period.

> Names like `LoginData` / `<Verb><Noun>Data` are illustrative.

## Where they live

Request data objects live at `Interfaces/<EntryPoint>/Requests/<Group>/<Name>Data.php` — alongside the controllers that consume them. They never live inside `Domains/`. Validation rules are part of the **delivery layer**: the same domain action may be called by `AdminWeb` (with browser-friendly rules) and `PartnerApi` (with stricter API rules), and each entry point owns its own request data object. They share an action, not a request contract.

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
- **`final` class.** Same as the rest of the project — see [Conventions § class modifiers](../conventions.md#class-modifiers).

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

    $this->disableEmployeeAction->execute($employee, $data->reason);

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
