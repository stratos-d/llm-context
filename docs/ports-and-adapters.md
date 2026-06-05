# Ports and adapters

> **Owns**
>
> - When to introduce an interface (port) and when not to
> - The strategy-prefix naming convention (`Session*`, `Token*`, …) for variant-anticipated services
> - Where the abstraction lives when it is justified
>
> **Forbids**
>
> - Pre-emptively introducing an interface "in case a second implementation arrives"
> - Type-hinting a concrete adapter when an interface exists for it
> - Putting a port (interface) in `Infrastructure/`
>
> **See also**: [Philosophy](philosophy.md), [Architecture](architecture.md), [Actions](actions.md), [Services](services.md), [Anti-patterns](anti-patterns.md)

This file used to mandate a port-and-adapter pair for every external boundary. **That stance is gone.** It produced ceremony without payoff for capabilities that have one implementation and one consumer.

The current rule is much narrower. Read this whole file before deciding whether to introduce an interface.

## The trigger rule

Introduce an interface (port) only when **one** of the following holds:

1. **Two or more concrete implementations live in the same composition root.** Same DI container, same entry point, both reachable from the same wiring. The interface lets the container pick.
2. **A second implementation is arriving imminently.** "Imminently" means: within the same change set, or in a written ticket whose work has started. Not "someday".
3. **A runtime substitution is required.** The choice of implementation is decided per request / per tenant / per feature flag, not per entry point.

If none of those hold, **inject the concrete class.** No interface, no abstraction.

The most common version of trigger 1 is *future*: the same use case will be reused by a second entry point. That is **not** sufficient on its own — see the next section.

## The entry point is the polymorphism boundary

Each entry point (`AdminWeb/`, `PartnerApi/`, `Cli/`, …) is its own composition root with its own controllers, routes, and providers. Within one entry point, every dependency resolves to exactly one concrete class.

Two entry points needing the same Application action with *different* delivery mechanisms is **not** a port problem. It is two separate concrete classes for the delivery side, each consumed by exactly one entry point:

```text
app/Infrastructure/Auth/
├── Session/
│   └── SessionEmployeeAuthenticator.php   ← used only by AdminWeb
└── Token/
    └── TokenEmployeeAuthenticator.php     ← used only by PartnerApi (when it exists)
```

`AdminWeb\LoginController` injects `SessionEmployeeAuthenticator` directly. `PartnerApi\LoginController` would inject `TokenEmployeeAuthenticator` directly. Neither needs an interface, because neither could ever resolve to the other.

The shared layer above them (`Application/EmployeeAuth/VerifyEmployeeCredentialsAction`) is framework-agnostic by construction; it returns a typed result and never touches sessions or tokens. Both controllers consume the same Application action, then call their own delivery service. **That is the reuse pattern**, not a shared port.

## Strategy prefix for variant-anticipated services

When a concrete service represents a *strategy* that has plausible siblings (whether or not they exist yet), prefix the class name with the strategy. The prefix is the documentation:

- `SessionEmployeeAuthenticator` — login + session regenerate, for HTTP web flows
- `SessionPendingLoginStash` — pending-login keys in the session
- `SessionCurrentEmployeeProvider` — current employee from the configured guard
- *(future)* `TokenEmployeeAuthenticator` — Sanctum-style token issuance for API flows

When a sibling appears, it slots in obviously parallel. Until then, the prefix simply tells the reader "this one is the session-based one" without forcing an interface.

When there is **no** plausible variant (the class is the only shape the operation will ever take), drop the prefix:

- `EmployeeAuthenticator` would be wrong — what kind?
- `TwoFactorAuthenticator` is fine — it's just a 2FA verifier, no strategies to compare it against
- `RecordEmployeeLoginAction` is fine — there's only one way to record a login

## Where things live

| Construct | Location | Notes |
|---|---|---|
| Concrete service (no interface) | `Infrastructure/<Capability>/<Strategy>/<Strategy><Service>.php` | Strategy folder when the prefix is used. Drop the folder if there's no prefix. |
| Interface (port) — when justified | Lives with its **callers**, never with implementors. `Application/<UseCase>/Contracts/` for application-level capabilities; `Domains/<X>/Contracts/` if the abstraction is a domain concern. | Filename matches the interface name. |
| Concrete service implementing an interface | `Infrastructure/<Capability>/<Strategy>/<Strategy><Service>.php`, with `implements <Service>` | Same path layout as a service without an interface. |

A port **never** lives in `Infrastructure/`. If you want to put one there, you have inverted the dependency direction.

## Naming

| Construct | Pattern | Example |
|---|---|---|
| Concrete service, no variants anticipated | `<Noun><Capability>` | `TwoFactorAuthenticator`, `LaravelMailer` |
| Concrete service with strategy prefix | `<Strategy><Noun><Capability>` | `SessionEmployeeAuthenticator`, `SessionPendingLoginStash` |
| Interface (port), when introduced | `<Noun><Capability>` — same as the no-prefix concrete | `EmployeeAuthenticator` (when both `Session*` and `Token*` actually exist) |

Forbidden:

- `<Service>Interface` — Hungarian-flavoured, redundant
- `<Service>Contract` — same problem; `Contracts/` is the folder
- `I<Service>` — same problem
- Vendor-only adapter names (`Algolia`, `Google2FA`) — those may be the vendor's class. Our class is named in our vocabulary.

## Skeleton — concrete service (no interface)

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Session;

use App\Domains\Employees\Models\Employee;
use App\Infrastructure\Auth\Guard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Session\SessionManager;

final class SessionEmployeeAuthenticator
{
    public function __construct(
        private AuthFactory $authFactory,
        private SessionManager $sessionManager,
    ) {}

    public function login(Employee $employee, bool $remember): void
    {
        $this->authFactory->guard(Guard::Admin->value)->login($employee, $remember);
        $this->sessionManager->regenerate();
    }

    public function logout(): void
    {
        $this->authFactory->guard(Guard::Admin->value)->logout();
        $this->sessionManager->invalidate();
        $this->sessionManager->regenerateToken();
    }
}
```

Rules:

- `final`. Same as everywhere else in the project.
- Constructor-promoted, `readonly` where viable.
- The class encapsulates a *composite* operation (`login` = guard-login + session-regenerate). One-liner wrappers around a framework call earn a class only when the wrapping adds type narrowing or a name worth having.
- No facade calls inside method bodies — inject framework primitives via constructor.

## Skeleton — interface, when justified

Only when trigger 1, 2, or 3 holds. Then:

```php
<?php

declare(strict_types=1);

namespace App\Application\<UseCase>\Contracts;

interface <ServiceName>
{
    // Methods describe what the project wants, not what the framework calls them.
    // Parameters and return types are project types or primitives — never framework types.
}
```

Rules:

- The interface is `interface`, not `abstract class`. Implementations are independent, not subclasses.
- One interface per capability. If two methods would never co-occur in the same implementation, split into two interfaces.
- Methods describe **what we want**, not what the framework calls them.

## Container wiring

Most concrete services need **no binding**. Laravel's container auto-resolves any concrete class with constructor type-hints. Inject the class directly.

A binding is only needed when:

- An interface exists and one of trigger 1/2/3 has produced multiple implementations
- A class needs construction parameters Laravel can't infer

When a binding *is* needed, prefer the entry-point's service provider:

```text
app/Interfaces/<EntryPoint>/Providers/<EntryPoint>ServiceProvider.php
```

Each entry-point provider is registered in `bootstrap/providers.php`. Use `$this->app->when($consumer)->needs($interface)->give($concrete)` for contextual binding. Only use a default `bind()` when the interface has exactly one production implementation everywhere.

If the per-entry-point provider has no bindings to register, **don't create it**. An empty provider is overhead.

## What never happens in a controller, action, or other consumer

- `app(SomeService::class)` — service-locator pattern. Use constructor injection.
- `new <FrameworkSpecificClass>()` — frameworks resolve via the container; consumers depend on the service class.
- Type-hinting a concrete service that has an interface, when the project has multiple implementations behind that interface — type-hint the interface in that case.

## What does NOT need a service class at all

Most things. Don't wrap framework calls in a service "for testability" or "in case it changes":

- **Eloquent / query builder / `DB::transaction()`.** The database is the project's primary persistence. Eloquent models are acceptable aggregate roots, and Application queries may use the query builder directly for tuned read models (see [Philosophy](philosophy.md)).
- **HTTP request and response shaping.** Controllers shape HTTP. Inertia renders. No abstraction needed.
- **Validation.** Owned by request data objects.
- **Logging.** `Log::*` is fine. Logging mechanism changes are rare and self-contained.
- **Config reads.** `config('foo.bar')` in adapters and controllers is fine. Domain code receives values via constructor or arguments.
- **Policies / `Gate::authorize()`.** Authorization stays framework-coupled.
- **Job dispatch.** `dispatch(...)` from an Application action is fine.
- **`auth()`, `Auth::guard()`, `$request->user()`, `$request->session()`** — call directly when the consumer is the *only* place that needs the call. Wrap in a concrete service when the call is *composite* (multiple framework primitives forming one logical operation) **or** repeated across several consumers and benefits from a name and from type narrowing.

## When you're tempted to introduce an interface

Three temptations and what to do instead:

**"It would be nice to swap this out in tests."**
Test the integration with the real implementation, or test the consumer with a small purpose-built test double constructed inline. A whole interface plus a fake class is rarely worth it for a single substitution scenario. See [Testing](testing.md).

**"A second implementation might exist someday."**
Wait for it. The cost of de-abstracting later (delete the interface, drop `implements`, retype-hint the consumer) is small and mechanical. The cost of premature abstraction is paid every time someone reads the code.

**"Different entry points need different shapes."**
Different concrete classes per entry point. The entry point is the polymorphism boundary; the interface adds nothing.

## De-abstraction (going from interface to concrete)

When an interface no longer earns its keep — the second implementation didn't arrive, the fake stopped being used — remove it:

1. Delete the interface file.
2. Drop `implements <Interface>` from the concrete class.
3. Update consumer type-hints from interface to concrete class (rename refactor in IDE).
4. Drop any container bindings for the interface.
5. Run tests + Pint.

This direction is just as valid as adding an interface. The codebase should reflect what *is*, not what was once anticipated.

## See also

- [Philosophy § resist preemptive abstraction](philosophy.md) — the rationale for the trigger rule.
- [Architecture](architecture.md) — where services and (when they exist) interfaces sit in the layer diagram.
- [Actions](actions.md) — Application actions consume services; this is where the dependency injection flows.
- [Services](services.md) — naming and shape rules for concrete services.
- [Testing](testing.md) — how to test consumers without a fake-class farm.
- [Anti-patterns](anti-patterns.md) — preemptive-abstraction red flags.
- [Glossary](glossary.md) — definitions of *port*, *adapter*, *strategy prefix*, *composition root*.
