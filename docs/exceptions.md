# Exceptions

> **Owns**
>
> - The exception class hierarchy: `DomainException` (abstract) → per-context base → concrete failure types
> - Where exception classes live (`Domains/<X>/Exceptions/<Name>.php`)
> - The throw / catch policy: actions throw, controllers don't catch, the global handler maps
> - HTTP / Inertia mapping in `bootstrap/app.php`
> - The boundary between domain exceptions and `ValidationException` / `AuthorizationException`
>
> **Forbids**
>
> - Throwing `\Exception` or `\RuntimeException` from a Domain or Application action
> - Catching domain exceptions inside the controller without a specific HTTP-shape reason
> - Re-stating Laravel's exception-handling mechanics — see Laravel docs for the framework parts
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Authorization](authorization.md), [Request data](http/request-data.md), [Anti-patterns](anti-patterns.md)

When an action cannot complete its work, it throws. The exception's *type* tells the layer above what kind of failure happened, so the right HTTP status, log level, and user message can be produced without the action itself knowing about HTTP.

## The hierarchy

```text
\App\Domains\DomainException                ← abstract project root
    ↑
    ├── \App\Domains\Employees\Exceptions\EmployeesException        ← per-context abstract base
    │       ↑
    │       ├── EmployeeAlreadyDisabled
    │       ├── EmployeeTwoFactorAlreadyEnabled
    │       └── …
    │
    ├── \App\Domains\<Other>\Exceptions\<Other>Exception            ← per-context base
    │       ↑
    │       └── …
```

Three tiers:

1. **`App\Domains\DomainException`** — abstract. Project-wide root for any domain failure. Lives at `app/Domains/DomainException.php`. Every render mapping in the central handler keys off this type.
2. **`App\Domains\<Context>\Exceptions\<Context>Exception`** — abstract. The per-context base. Lets each context attach metadata (e.g. an exception code prefix, a default log channel) without touching the project root.
3. **Concrete failure types** — final classes describing one specific failure (`EmployeeAlreadyDisabled`, `ApprovalLimitExceeded`). Lives in `Domains/<X>/Exceptions/`.

## Skeletons

```php
<?php

declare(strict_types=1);

namespace App\Domains;

use RuntimeException;
use Throwable;

abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        public readonly string $errorCode = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

Two readonly slots carry machine-readable failure identity alongside the human message:

- **`errorCode`** — a stable, snake_case identifier (`employee_already_disabled`, `approval_limit_exceeded`). Safe to expose to API consumers. Never change its spelling once shipped.
- **`context`** — a string-keyed array of structured data relevant to the failure (`['employee_id' => $id, 'attempted_at' => $at->toIso8601String()]`). Safe to log; do **not** put user-facing copy here. Never put PII / secrets here.

The `errorCode` is not the HTTP status. HTTP status is decided by the central handler based on exception type; `errorCode` is the API contract for the *specific* failure a client may want to branch on.

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Exceptions;

use App\Domains\DomainException;

abstract class EmployeesException extends DomainException
{
}
```

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Exceptions;

use App\Domains\Employees\Models\Employee;

final class EmployeeAlreadyDisabled extends EmployeesException
{
    public static function for(Employee $employee): self
    {
        return new self(
            message: "Employee #{$employee->getKey()} is already disabled.",
            errorCode: 'employee_already_disabled',
            context: ['employee_id' => $employee->getKey()],
        );
    }
}
```

Rules for concrete exceptions:

- **`final`**.
- **Named static constructor** (`::for(...)`) carrying the relevant context. Keeps call sites readable and centralises message formatting.
- **No public mutable state.** If extra fields are needed for the renderer (e.g. `$retryAfter`), make them readonly properties set through the static constructor.
- **No framework types** (no `Request`, no `Response`). Domain exceptions stay framework-agnostic.

## Where they live

```text
app/Domains/DomainException.php                                        ← project root (abstract)
app/Domains/<ContextName>/Exceptions/<ContextName>Exception.php        ← per-context base (abstract)
app/Domains/<ContextName>/Exceptions/<Name>.php                        ← concrete failures
```

A context need not have an `Exceptions/` folder until it has its first exception. Don't create the folder pre-emptively.

Application-layer cross-context use cases that produce their own failure types put the exception in the most-specific *single* context where the failure originated — usually the Domain whose invariant was violated. If a use case spans two contexts and its failure doesn't belong to either, place the exception under `app/Application/<UseCase>/Exceptions/<Name>.php`. This is rare; prefer a domain-owned exception when one fits.

## Throw / catch policy

### Throw — from actions only

Domain exceptions are thrown by:

- **Domain actions** when an aggregate invariant would be violated.
- **Application actions** when a business-rule check fails or a precondition isn't met (e.g. resource not found, wrong state, conflict).

Example:

```php
final class DisableEmployeeAction
{
    public function execute(Employee $employee): void
    {
        if ($employee->isDisabled()) {
            throw EmployeeAlreadyDisabled::for($employee);
        }

        $employee->disabled_at = CarbonImmutable::now();
        $employee->saveOrFail();
    }
}
```

Models, builders, controllers, services, and request data do **not** throw domain exceptions. Each layer's job is one of:

| Layer | Throws |
| ----- | ------ |
| Request data | `ValidationException` (Laravel-native, from `rules()` / attributes) |
| Controller | Nothing — bubbles whatever the action raised |
| Application action | Domain exceptions; sometimes `ValidationException` for cross-field rules better expressed there |
| Domain action | Domain exceptions only |
| Service | Framework or vendor exceptions; the action that called it decides whether to translate |
| Model | Should not throw — pure state |

### Catch — almost never

The default policy is **don't catch in the controller**. The Application action throws; the central exception handler in `bootstrap/app.php` maps the exception to an HTTP / Inertia response; the controller's job is composition, not error translation.

Catch only when:

- The use case has multiple **recoverable** outcomes worth distinguishing in the response (then prefer a `Result` object instead — see [Actions § when to return a result object](actions.md#when-to-return-a-result-object)).
- A specific framework exception (e.g. a third-party SDK's exception) needs translating into a domain exception or a result. Catch in the **service**, not the controller.

`ValidationException` and `AuthorizationException` are Laravel-native and travel their normal route — request data raises one, the policy / Gate raises the other, the framework renders. They are not domain exceptions and they are not caught manually.

## Mapping to HTTP / Inertia

Mapping lives in `bootstrap/app.php`'s `withExceptions()` callback. One place, not scattered.

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\App\Domains\DomainException $e, Request $request) {
        $status = 422;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $status);
        }

        return back()->withErrors(['_domain' => $e->getMessage()])->setStatusCode($status);
    });
})
```

Defaults:

| Exception type | Status | Notes |
| -------------- | ------ | ----- |
| `App\Domains\DomainException` (any subclass not handled below) | 422 | Generic domain failure |
| `App\Domains\<X>\Exceptions\<X>NotFound` (convention) | 404 | When the action can't find the resource by ID/value |
| `App\Domains\<X>\Exceptions\<X>Conflict` (convention) | 409 | Wrong state / concurrency conflict |
| `Illuminate\Validation\ValidationException` | 422 | Laravel-native, untouched |
| `Illuminate\Auth\Access\AuthorizationException` | 403 | Laravel-native, untouched |
| `Illuminate\Auth\AuthenticationException` | 401 / redirect | Laravel-native |
| Anything else uncaught | 500 | Framework default |

Override the default per concrete type only when the status code or response shape genuinely differs. The hierarchy lets the handler key off `instanceof` ladders without exploding — most domain failures legitimately are 422.

## Conventions for naming

Past-tense or fact-flavoured for the failure that happened:

```text
EmployeeAlreadyDisabled
EmployeeNotFound
EmployeeTwoFactorAlreadyEnabled
EmployeeTwoFactorNotEnabled

ApprovalLimitExceeded
DocumentNotApprovable
DocumentAlreadyPublished

LedgerTransactionWouldBreakBalance
LedgerAccountFrozen
```

Don't use generic `*Exception` suffixes on concrete classes — they're already in `Exceptions/` and they extend a class with `Exception` in its name. The suffix `*Exception` is reserved for the abstract bases (`DomainException`, `EmployeesException`).

## When to introduce a new exception class

The same rule that governs value objects: **promote when the same failure shows up in two-or-three places** with the same shape. Until then, a one-off `throw new EmployeesException("Employee #X cannot do Y because Z")` is fine — it carries the message, the central handler maps to 422, the user-visible behaviour is correct.

Don't pre-create `EmployeesException` subclasses for every conceivable failure. Promote when the call site repeats; let the type system catch up to the code, not the other way around.

## See also

- [Actions § signature rules](actions.md#signature-rules) — actions throw; they do not return error sentinel values.
- [Authorization](authorization.md) — `AuthorizationException` is separate from domain exceptions.
- [Request data](http/request-data.md) — `ValidationException` is separate; it's Laravel-native and stays in delivery.
- [Anti-patterns § exception misuse](anti-patterns.md) — grep-friendly red flags.
- [Glossary](glossary.md) — definitions of *Domain exception*, *Result object* (and when to prefer one over the other).
