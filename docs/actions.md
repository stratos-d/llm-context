# Actions

> **Owns**
>
> - Action naming (`<Verb><Noun>Action`)
> - The two placements: Domain action (aggregate command) and Application action (use case)
> - The placement test: *what would force this code to change?*
> - Single-`execute()` signature, transaction boundary, return-domain-value rules
> - What an action can / cannot call
> - Result-object convention (location and naming)
>
> **Forbids**
>
> - HTTP concerns — see [Controllers](http/controllers.md)
> - Validation — see [Request data](http/request-data.md)
> - A third placement under `Interfaces/<EntryPoint>/Actions/` — that does not exist; delivery-coupled writes belong to entry-point-specific concrete services in `Infrastructure/`
> - Re-stating "what belongs on a model" — see [Models § what belongs on a model](data/models.md#what-belongs-on-a-model)
>
> **See also**: [Philosophy](philosophy.md), [Architecture](architecture.md), [Ports and adapters](ports-and-adapters.md), [Transactions](transactions.md), [Cross-context communication](cross-context.md), [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md), [Models](data/models.md), [Anti-patterns](anti-patterns.md)

The action is the unit of write-side work. **Every** state-changing operation goes through one. There are no exceptions.

> Names like `LogInEmployeeAction`, `EnableEmployeeTwoFactorAction`, `CompleteEmployeeTwoFactorChallengeAction` are illustrative.

## The placement test

Place an action by **what would force its body to change**:

- If it changes when *the business rules of one aggregate* change → **Domain action**, lives in `Domains/<X>/Actions/`.
- If it changes when *the use case* changes (steps reordered, new step added, authorization tightened) → **Application action**, lives in `Application/<UseCase>/`.

There are exactly two placements. There is no third tier. Delivery-coupled writes (web-guard login, token issuing, cookie regeneration) are not actions — they are **concrete services** in `Infrastructure/<Capability>/<Strategy>/`, injected directly by the controller for that entry point. See [Ports and adapters § the entry point is the polymorphism boundary](ports-and-adapters.md#the-entry-point-is-the-polymorphism-boundary).

The dependency direction is fixed: `Interfaces → Application → Domain → (Aggregates, Builders, Value Objects)`. Application calls Domain. Application **never** calls Interfaces.

## Naming

`<Verb><Noun>Action`. The verb describes the operation, the noun the aggregate it changes:

```text
DisableEmployeeTwoFactorAction
EnableEmployeeTwoFactorAction
ConfirmEmployeeTwoFactorAction
RegenerateEmployeeRecoveryCodesAction
ConsumeEmployeeRecoveryCodeAction
RecordEmployeeLoginAction

VerifyEmployeeCredentialsAction
CompleteEmployeeTwoFactorChallengeAction
ApproveContentChangeAction
PublishDocumentAction
RecordAuditEventAction
ImportDirectoryRecordsAction
CreateOrganizationWithOwnerAction
```

Avoid generic suffixes: `EmployeeService`, `EmployeeManager`, `AuthHelper`. They quietly accumulate behaviour and end up as 800-line god objects. One class per operation keeps each file at ~30–80 lines.

Action names may be longer when they are clearer — `CompleteEmployeeTwoFactorChallengeAction` over `CompleteChallengeAction`. The action name is a contract; readability wins over brevity. See [Conventions § naming](conventions.md#naming) for the broader naming guide.

## Domain actions

A Domain action owns an **aggregate command**: invariant-protecting state mutation on one aggregate. It is the unit of work the aggregate would expose as a method if this project used rich aggregate roots (it does not — see [Philosophy § anaemic domain model](philosophy.md#what-we-deliberately-do-not-adopt)).

> **Exception — state-machine and high-impact aggregates.** When the aggregate qualifies for Trigger B (see [Models § Trigger B](data/models.md#trigger-b--risk-state-machine-and-high-impact-aggregates)), the transition method lives on the model from day one and the Domain action either disappears (the Application action calls `$aggregate->transition(...)` directly) or shrinks to a thin wrapper that exists only because the use case needs to be addressable as an action (e.g. dispatched from a job). Do not inline transition guards (`if ($document->status !== ...)`) into Domain actions for these aggregates.

### Where they live

`app/Domains/<DomainName>/Actions/<Verb><Noun>Action.php`. Group with sub-folders when a domain has a cohesive cluster of related operations:

```text
app/Domains/Employees/Actions/
├── RecordEmployeeLoginAction.php
└── TwoFactor/
    ├── ConfirmEmployeeTwoFactorAction.php
    ├── DisableEmployeeTwoFactorAction.php
    ├── EnableEmployeeTwoFactorAction.php
    └── RegenerateEmployeeTwoFactorRecoveryCodesAction.php
```

### What a Domain action does

- State changes on one aggregate (`Employee.two_factor_*` columns).
- Lifecycle transitions (`Document` from `draft` to `published`).
- Invariant enforcement (refusing a state change that would violate aggregate rules).
- Domain-specific writes that do not need orchestration across multiple aggregates or multiple steps.

### What a Domain action must not depend on

- HTTP, Inertia, redirects, cookies, sessions, web guards, API tokens, frontend state.
- Other bounded contexts. A Domain action in `Domains/Employees/` does not import from `Domains/Documents/`.
- Use-case orchestration. If an action's job description contains "and then …", split the orchestration up into an Application action and keep this one focused on one aggregate.

### Skeleton

```php
final class DisableEmployeeTwoFactorAction
{
    public function execute(Employee $employee): void
    {
        $employee->two_factor_secret = null;
        $employee->two_factor_recovery_codes = null;
        $employee->two_factor_confirmed_at = null;
        $employee->saveOrFail();
    }
}
```

Per-attribute assignment is the default style. `forceFill([...])` is acceptable when the action's job is *exactly* "reset this set of columns" (e.g. 2FA disable) and grouping the reset visually helps. Either way, **the action saves; the model does not**. See [Models § what does not belong on a model](data/models.md#what-does-not-belong-on-a-model).

Note the absence of `DB::transaction(...)`. A Domain action never opens its own transaction; that is the Application action's job. See [Transactions](transactions.md).

## Application actions

An Application action owns a **use case**: a single goal a single actor accomplishes in a single interaction with the system, even if it spans multiple steps internally.

### Where they live

`app/Application/<UseCase>/<Verb><Noun>Action.php`. The `<UseCase>` folder groups all actions, ports, and result objects that participate in one use case:

```text
app/Application/EmployeeAuth/
├── VerifyEmployeeCredentialsAction.php       ← Application action
├── VerifyEmployeeCredentialsResult.php       ← Result (sum type)
├── CompleteEmployeeTwoFactorChallengeAction.php
└── PendingLogin.php                          ← DTO
```

A use case may touch one bounded context or several. The placement test is *what would force this to change?* — not how many contexts it touches.

### The three Application-layer shapes

Three distinct shapes coexist under `Application/<UseCase>/`:

1. **Action** — `*Action` suffix. One public `execute()` method. Orchestrates the use case.
2. **Result** — `*Result` suffix. Returned by an action. Sum type with named static constructors. Only when justified (see § [When to return a result object](#when-to-return-a-result-object)).
3. **DTO / Value Object** — no suffix; named after the domain concept (`PendingLogin`). Plain `readonly` data carriers. Don't add `*ValueObject` / `*DTO` / `*Interface` suffixes.

Don't wrap a single primitive in a class. If the only state is one `bool`, `int`, or `string` and there are no invariants the primitive can't carry, **use the primitive**. The parameter name carries the meaning. See [Value objects § when not to introduce one](data/value-objects.md#when-not-to-introduce-one).

### What an Application action does

- Composes one or more Domain actions.
- Coordinates multiple steps under one transaction boundary, when steps must commit or roll back together.
- Loads aggregates needed for the use case (via builders).
- Calls concrete services (or, when justified, ports) for capabilities the use case needs (`Hasher`, `TwoFactorAuthenticator`, future `Mailer`).
- Performs **business-rule authorization** as plain conditional code — the rules that depend on aggregate state (e.g. "can this reviewer approve this change in the current state?"). Resource-level and caller-level authorization (`Gate::authorize`, request-data `authorize()`) live at the delivery boundary, never inside the action; see [Authorization](authorization.md).
- Emits domain events (or returns them in the result; see [Domain events](domain-events.md)).
- Returns a typed result object, primitive, or `void` describing the outcome (see § [When to return a result object](#when-to-return-a-result-object)).

### What an Application action must not depend on or return

- Inertia responses, redirects, Blade views, HTTP resources, JSON resources.
- Anything entry-point-specific. The Application action runs unchanged across web, API, CLI. Delivery-side concrete services (session login, token issuance, cookie writes) belong to the entry point's controller, not to the Application action.
- Direct framework auth/session calls (`auth()`, `Auth::guard()`, `$request->session()`) — those happen in the controller or a controller-injected concrete service.
- `Gate::authorize(...)` / `$this->authorize(...)` — authorization happens at the delivery boundary; see [Authorization](authorization.md).

### Skeleton

```php
final class CompleteEmployeeTwoFactorChallengeAction
{
    public function __construct(
        private TwoFactorAuthenticator $twoFactorAuthenticator,
        private ConsumeEmployeeRecoveryCodeAction $consumeEmployeeRecoveryCodeAction,
        private RecordEmployeeLoginAction $recordEmployeeLoginAction,
    ) {}

    public function execute(Employee $employee, string $code): bool
    {
        // … verify code or recovery code; on success run domain actions and return true …
    }
}
```

This action returns `bool` because the caller already has the `Employee` and there are only two outcome states. See § [When to return a result object](#when-to-return-a-result-object) for the alternative.

## When to return a result object

Return a typed `*Result` from an Application action only when **at least one** of the following holds:

1. **The caller doesn't already have the returned data** — the action *loads* or *produces* something new (e.g. an `Employee` resolved from `email + password`).
2. **There are 3+ distinct outcome states** — `bool` isn't expressive enough.

Otherwise return `bool`, `void`, or a single domain object directly. **Don't dress one bit in a class.** A tautological result object (where the `?Employee` field just echoes back the `Employee` the caller passed in) is overhead, not communication.

Worked comparison from this codebase:

| Action | Caller has data going in? | Outcome states | Return type |
|---|---|---|---|
| `VerifyEmployeeCredentialsAction` | No (just `email + password` strings) | 3 (verified / pendingTwoFactor / invalid) | `*Result` ✅ earns it |
| `CompleteEmployeeTwoFactorChallengeAction` | Yes (already has the `Employee`) | 2 (success / fail) | `bool` ✅ enough |

### Result object shape, when justified

- **Location.** Co-located with the action under the same `Application/<UseCase>/` folder.
- **Naming.** `<Action-name-without-Action>Result`. `VerifyEmployeeCredentialsResult`. `ApproveContentChangeResult`.
- **Shape.** `final readonly class` with named static constructors expressing each outcome (`::verified($employee)`, `::pendingTwoFactor($employee)`, `::invalid()`).
- **Public state.** Public readonly properties; assertions and downstream branching happen on those.
- **No framework types.** No `RedirectResponse`, no `JsonResource`, no `Response`. The controller turns the result into HTTP.

```php
final readonly class VerifyEmployeeCredentialsResult
{
    private function __construct(
        public bool $verified,
        public bool $requiresTwoFactorChallenge,
        public ?Employee $employee,
    ) {}

    public static function verified(Employee $employee): self
    {
        return new self(verified: true, requiresTwoFactorChallenge: false, employee: $employee);
    }

    public static function pendingTwoFactor(Employee $employee): self
    {
        return new self(verified: true, requiresTwoFactorChallenge: true, employee: $employee);
    }

    public static function invalid(): self
    {
        return new self(verified: false, requiresTwoFactorChallenge: false, employee: null);
    }
}
```

Domain actions usually return `void` or the modified aggregate. They rarely need a result object; if they do, it lives next to the action under `Domains/<X>/Actions/`.

## Signature rules

- **Single public entry method named `execute()`.** Use named arguments at the call site for readability. `__invoke()` is **not** used in this project — the project chose `execute()` and stays consistent.
- **Constructor-promoted dependencies, `readonly` where viable.** No `app(...)` calls inside method bodies; no service-locator pattern.
- **Type-hint the type that is actually wired.** Inject the concrete service when it has no interface; inject the interface only when an interface genuinely exists (see [Ports and adapters § the trigger rule](ports-and-adapters.md#the-trigger-rule)).
- **The Application action is the sole transaction root.** When a use case writes across multiple aggregates or rows that must commit together, the Application action wraps the work in `DB::transaction()`. Domain actions, controllers, and services never open one. Single-row writes do not need a wrap (one `save()` is atomic). See [Transactions](transactions.md).
- **Return a domain value, not an HTTP value.** `void`, `bool`, the modified model, a value object, a result object — never `RedirectResponse` / `JsonResponse` / Inertia response.
- **Idempotence where natural.** Calling `EnableEmployeeTwoFactorAction` twice in a row is a developer mistake; calling `RecordEmployeeLoginAction` twice should be safe (or both calls should be visible in an audit trail). State the policy at the top of the class when it is non-obvious.
- **Throw, don't return error sentinels.** When a precondition fails or an invariant is violated, the action throws a domain exception. The central handler maps it to HTTP. Return `bool` / `Result` only for outcomes that are part of the use case's normal vocabulary (e.g. "verified / pending-2FA / invalid"); failures of the use case itself are exceptions. See [Exceptions](exceptions.md).

## What an action *can* call

- Aggregates and other models — read state, set attributes, `->save()`. Models do not save themselves; the action does. See [Models § what does not belong on a model](data/models.md#what-does-not-belong-on-a-model).
- Builders — to compose query helpers. See [Builders](data/builders.md).
- Other actions — composition is fine; loops over actions are a smell.
- Concrete services in `Infrastructure/` (or interfaces, when justified) — `TwoFactorAuthenticator`, future `Mailer`, etc. Constructor-injected.
- Domain events — `event(new EmployeeLoggedIn(...))`. See [Domain events](domain-events.md).
- `dispatch(new SomeJob(...))` — Application actions only.

## What an action *cannot* call

- Controllers, request data objects, view models.
- Framework auth/session/HTTP shaping: `auth()`, `Auth::guard()`, `request()`, `session()`, `redirect()`, `back()`. Those belong in the controller (or a controller-injected concrete service for the entry point).
- Inertia, view rendering helpers.
- Code from another bounded context's `Models/`, `Builders/`, or `Actions/`. Cross-context calls go through events, app actions, or contracts. See [Architecture § cross-context communication](architecture.md).
- Other actions transitively if it would create a cycle.

## Where delivery-coupled writes go

If you find yourself wanting to write an action whose body would have to be rewritten verbatim for a different delivery shape (Web vs API vs CLI), **it is not an action** — it is an entry-point-specific concrete service in `Infrastructure/`, injected directly by that entry point's controller.

Examples and their correct placement:

| Operation | Wrong (action) | Right (concrete service) |
|---|---|---|
| Log into web session | `LogInEmployeeToWebGuardAction` | `Infrastructure/Auth/Session/SessionEmployeeAuthenticator` (concrete) |
| Issue API access token | `IssueEmployeeAccessTokenAction` | `Infrastructure/Auth/Token/TokenEmployeeAuthenticator` (concrete, when PartnerApi exists) |
| Set remember cookie | `SetRememberCookieAction` | Inside `SessionEmployeeAuthenticator::login()` |
| Send password-reset email | `SendPasswordResetEmailAction` | `Infrastructure/Mail/<X>Mailer` |
| Stash pending login in session | `StashPendingLoginAction` | `Infrastructure/Auth/Session/SessionPendingLoginStash` |

The shape of the controller does not change: it composes Application actions and concrete services. Where the framework-coupled work lives changes — out of `Action` classes, into `Infrastructure/<Capability>/<Strategy>/`. An interface only enters the picture if the same composition root has multiple implementations or one is imminent — see [Ports and adapters § the trigger rule](ports-and-adapters.md#the-trigger-rule).

## Worked example — the `LoginController` flow

The controller composes one Application action and two entry-point-specific concrete services. Each does its job:

```php
// Interfaces/AdminWeb/Controllers/Auth/LoginController.php
final class LoginController
{
    public function __construct(
        private VerifyEmployeeCredentialsAction $verifyEmployeeCredentialsAction,
        private RecordEmployeeLoginAction $recordEmployeeLoginAction,
        private SessionEmployeeAuthenticator $sessionEmployeeAuthenticator,
        private SessionPendingLoginStash $sessionPendingLoginStash,
        private SessionCurrentEmployeeProvider $sessionCurrentEmployeeProvider,
    ) {}

    public function store(LoginData $data): RedirectResponse
    {
        $result = $this->verifyEmployeeCredentialsAction->execute(
            email: $data->email,
            password: $data->password,
        );

        if (! $result->verified || $result->employee === null) {
            throw ValidationException::withMessages([/* … */]);
        }

        if ($result->requiresTwoFactorChallenge) {
            $this->sessionPendingLoginStash->stash($result->employee, $data->remember);

            return redirect()->route('two-factor-challenge.show');
        }

        $this->recordEmployeeLoginAction->execute($result->employee);
        $this->sessionEmployeeAuthenticator->login($result->employee, $data->remember);

        return redirect()->intended(route('dashboard'));
    }
}
```

The pieces:

- `VerifyEmployeeCredentialsAction` (Application) — loads the `Employee`, verifies the password, branches on 2FA-enabled, returns `VerifyEmployeeCredentialsResult`. Framework-agnostic: this action runs unchanged in any entry point.
- `RecordEmployeeLoginAction` (Domain) — updates `last_login_at` on the `Employee` aggregate.
- `SessionEmployeeAuthenticator`, `SessionPendingLoginStash`, `SessionCurrentEmployeeProvider` (concrete services in `Infrastructure/Auth/Session/`) — entry-point-specific. Each encapsulates a *composite* framework operation worth a name.
- `LoginController` — composes the Application action + the entry point's concrete services; returns redirect.

When `PartnerApi` arrives, its `LoginController` will reuse the same `VerifyEmployeeCredentialsAction` and pair it with a `TokenEmployeeAuthenticator` concrete service, returning JSON. The Application action is the reuse boundary; interfaces are not needed.

## See also

- [Philosophy](philosophy.md) — why the project uses anaemic models with action-based mutation.
- [Architecture § where does an action live](architecture.md#where-does-an-action-live) — the canonical placement table.
- [Ports and adapters](ports-and-adapters.md) — where delivery-coupled writes go instead of into actions.
- [Models § what does not belong on a model](data/models.md#what-does-not-belong-on-a-model) — what the action does that the model does not.
- [Controllers](http/controllers.md) — the layer that composes actions and ports.
- [Transactions](transactions.md) — the sole-root rule for `DB::transaction()`.
- [Cross-context communication](cross-context.md) — published actions and the cross-context call rule.
- [Authorization](authorization.md) — where `Gate::authorize` lives (not here).
- [Exceptions](exceptions.md) — the failure-throwing convention actions follow.
- [Jobs](jobs.md) — how Application actions are delivered via the queue.
- [Anti-patterns](anti-patterns.md) — grep-friendly signals of misplaced actions.
- [Glossary](glossary.md) — definitions for *Domain action*, *Application action*, *use case*, *result object*, *port*, *adapter*, *published action*, *transaction root*, *Policy*, *Domain exception*, *Job*.
