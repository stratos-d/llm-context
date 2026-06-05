# Authorization

> **Owns**
>
> - Where Policies live (`Domains/<X>/Policies/<Aggregate>Policy.php`)
> - Where authorization is *invoked* (request data `authorize()` and controllers, never inside Application actions)
> - The split between caller-level checks, resource-level checks, and business-rule authorization
> - Per-entry-point authorization variation
> - Forbidden patterns
>
> **Forbids**
>
> - `Gate::authorize(...)` / `$this->authorize(...)` / `Gate::allows(...)` inside an Application action or aggregate method
> - Policies that talk to HTTP, sessions, or request scope directly
> - Re-stating Laravel's policy mechanics — those live in the Laravel docs
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Request data](http/request-data.md), [Controllers](http/controllers.md), [Anti-patterns](anti-patterns.md)

Authorization decides whether a given **actor** is allowed to perform a given **operation** on a given **resource**. In this project, those decisions are framework-coupled (they read the authenticated user out of the request, they live next to Laravel's `Gate` facade) and therefore live at the **delivery boundary** — request data objects and controllers — not inside Application actions or aggregate methods, which stay framework-agnostic.

## Where Policies live

```text
app/Domains/<ContextName>/Policies/<Aggregate>Policy.php
```

A policy is the per-aggregate authorization class Laravel auto-discovers via the model ↔ policy convention: `App\Domains\Employees\Models\Employee` resolves to `App\Domains\Employees\Policies\EmployeePolicy` automatically. No manual registration needed.

Policies are Laravel authorization adapters colocated with the aggregate context. They are not domain behavior. They must not mutate state, open transactions, perform persistence, or call HTTP/session/request helpers.

Rules:

- **One policy per aggregate root.** `EmployeePolicy` covers `Employee`. Subordinate models inside the aggregate are authorized through their root.
- **Methods named after the operation in domain vocabulary**: `view`, `update`, `disable`, `enableTwoFactor`, `resetPassword`. Match the controller / use-case verb when both exist.
- **`final` class.** Same as everywhere.
- **Constructor-promoted dependencies**, `readonly` where viable. Most policies need none.
- **No HTTP / session / request access.** A policy receives the actor (`User` model) and the resource as method parameters; nothing else.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Policies;

use App\Domains\Employees\Models\Employee;

final class EmployeePolicy
{
    public function view(Employee $actor, Employee $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('employees.view');
    }

    public function disable(Employee $actor, Employee $target): bool
    {
        return ! $actor->is($target)
            && $actor->hasPermission('employees.manage');
    }
}
```

The actor type matches the guard — `Employee` for the AdminWeb guard, a future `PartnerUser` for `PartnerApi`. The signature stays plain PHP method parameters; no facades, no request scope.

## Where authorization is invoked

Three layers, each with a specific job. None of them is the Application action.

### 1. Caller-level checks — request data `authorize()`

Does this *kind of caller* have access to attempt this *kind of action*? Lives in the request data object's `authorize()`:

```php
final class DisableEmployeeData extends Data
{
    public static function authorize(): bool
    {
        return request()->user()?->can('employees.manage') ?? false;
    }

    public function __construct(
        public string $reason,
    ) {}
}
```

This is the right place for **broad permission gates** ("can this caller manage employees at all?"). It runs before route-model-binding completes and the resource is loaded. Returning `false` produces a 403 automatically.

### 2. Resource-level checks — controller

Can this *specific* actor act on this *specific* resource? Lives in the controller, immediately after the resource is bound:

```php
final class DisableEmployeeController
{
    public function __invoke(Employee $employee, DisableEmployeeData $data): RedirectResponse
    {
        Gate::authorize('disable', $employee);

        $this->disableEmployeeAction->execute(
            employeeId: EmployeeId::fromString((string) $employee->getKey()),
            reason: $data->reason,
        );

        return redirect()->route('employees.index');
    }
}
```

The controller is the right place because:

- The resource is now loaded.
- The actor is now resolved (the `Auth::user()` / guard read is HTTP-scoped — fine in a controller, forbidden inside the action).
- The exact same Application action can be reused by another entry point with **different** authorization rules; keeping `Gate::authorize` in the controller per entry point makes that explicit.

The `Gate::authorize($ability, $resource)` call throws `AuthorizationException` (mapped to 403 by Laravel) on failure. That's the desired behaviour; do not catch it in the controller.

### 3. Business-rule authorization — Application action (without Gate)

The rules that aren't about *who you are* but about *whether the operation makes sense given current state* live inside the Application action — but as **plain conditional code**, not via `Gate`:

```php
final class ApproveContentChangeAction
{
    public function execute(ContentChangeId $changeId, EmployeeId $reviewerId): ApproveContentChangeResult
    {
        $change = ContentChange::query()->findOrFail($changeId->toString());
        $reviewer = Employee::query()->findOrFail($reviewerId->toString());

        if (! $reviewer->canReview($change->category)) {
            return ApproveContentChangeResult::outsideReviewerScope();
        }

        if ($change->status !== ContentChangeStatus::PendingReview) {
            return ApproveContentChangeResult::wrongState();
        }

        // …
    }
}
```

This is **not** "can the user do approvals" (delivery-level). It is "can this reviewer approve this change, for this category, in this state" (business rule). It lives in the action because the rule depends on aggregate state the controller doesn't see.

Rule of thumb: if the check is `Gate::authorize(...)`, it is delivery-level. If the check inspects aggregate fields or value objects to decide, it is a business rule.

## Per-entry-point variation

Each entry point has its own controllers, request data objects, and middleware. Authorization rules can therefore vary **without** an interface or a polymorphism boundary:

- `AdminWeb` controller calls `Gate::authorize('disable', $employee)` against `EmployeePolicy`.
- A future `PartnerApi` controller calls a different policy or different abilities, against the same Application action.

If the same policy method should apply across entry points, both controllers call it. If it shouldn't, each controller authorizes differently. The Application action is the reuse boundary; authorization is per delivery shape.

## Forbidden patterns

| Forbidden | Why | Right shape |
| --------- | --- | ----------- |
| `Gate::authorize(...)` inside an Application action | Couples the action to the framework's auth facade and to the request-scoped current user | Move to the controller; pass any business-rule check into the action as plain code |
| `Gate::authorize(...)` inside an aggregate method | Same framework-coupling problem, plus the aggregate now depends on request scope | Move to the controller; keep aggregate methods focused on state and invariants |
| `auth()->user()` / `Auth::user()` inside a policy | Policy receives the actor as a parameter; pulling it from request scope is service-locator pattern | Pass the actor through |
| Policy class outside `Domains/<X>/Policies/` | Breaks Laravel's auto-discovery convention this project relies on | Move to the aggregate's context |
| Inline authorization in a model (`public function canBeDisabledBy(...)`) | Policies own authorization; models own state | Extract to the policy |
| Authorization logic split across the controller *and* the action for the same rule | Two places to maintain; tests pass against one and miss the other | Pick one layer per rule (delivery vs business) and stick to it |
| `Gate::define('manage-employees', fn() => ...)` ad-hoc closures | Loses the per-aggregate convention; not auto-discovered | A method on the appropriate `<Aggregate>Policy` |

## What about middleware?

Middleware is the right place for **route-wide caller-level gating** ("this whole route group is admins-only"). Use it for that. It is not a substitute for resource-level checks, which still happen at the controller after the resource binds.

```php
Route::middleware(['auth:admin', 'can:employees.manage'])->group(function (): void {
    Route::post('employees/{employee}/disable', [DisableEmployeeController::class, '__invoke']);
});
```

Middleware-level `can:` covers the broad permission. The controller still calls `Gate::authorize('disable', $employee)` for the per-resource decision.

## Authorization in tests

Test the policy directly with a unit test (no HTTP):

```php
public function test_an_employee_cannot_disable_themselves(): void
{
    $employee = Employee::factory()->withPermission('employees.manage')->create();
    $policy = new EmployeePolicy();

    $this->assertFalse($policy->disable($employee, $employee));
}
```

Test the controller end-to-end with a feature test that asserts a 403 for unauthorized actors and a redirect for authorized ones. Don't test policies through the HTTP kernel exclusively — the policy unit test is faster and exhaustive.

## See also

- [Architecture § layer responsibilities](architecture.md#layer-responsibilities) — confirms policies are an aggregate-context concern.
- [Request data § authorize vs policies](http/request-data.md#authorize-vs-policies) — the request-data half of the rule.
- [Actions](actions.md) — the action stays framework-agnostic; business-rule authorization lives there as plain code.
- [Anti-patterns § authorization misuse](anti-patterns.md) — grep-friendly red flags.
- [Glossary](glossary.md) — definition of *Policy*.
