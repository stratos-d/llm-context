# Anti-patterns

> **Owns**
>
> - The grep-friendly red-flag table — signals that one of the layer rules has been broken
> - A worked refactor example showing how a layered solution beats an inline one
>
> **Forbids**
>
> - Re-stating the rules themselves — every row links back to the file that *defines* the rule
>
> **See also**: [Architecture](architecture.md), [Ports and adapters](ports-and-adapters.md), [Cross-context communication](cross-context.md), [Transactions](transactions.md), [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md), [Controllers](http/controllers.md), [Actions](actions.md), [Models](data/models.md)

If a code review surfaces any of the signals below, the change needs to move before merging. Each row links to the file that owns the rule.

## Red flags

### Layer leaks (controllers, models, traits)

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `forceFill(...)->save()` inside a model or trait | An [action](actions.md). Models do [not write to themselves](data/models.md#what-does-not-belong-on-a-model). |
| `Model::query()->where('col', ...)` inside a controller | A [builder method `whereCol()`](data/builders.md#what-belongs-in-a-builder). |
| `$request->validate([...])` inside a controller | A request data object in [`Interfaces/.../Requests/`](http/request-data.md#where-they-live). |
| `DB::transaction(...)` inside a controller | The [Application action that owns the use case](transactions.md). |
| `$model->update([...])` inside a controller or model | An [action](actions.md). |
| `Model::create([...])` inside a controller | An [action](actions.md). |
| `if (... 2FA enabled) { ... } else { ... }` in controller | An action that returns a typed result; the controller branches on the result, not on raw model state. |
| Trait method that calls `->save()` on `$this` | Extract into an action; the [trait keeps only read helpers](data/models.md#traits-on-models). |
| Inline `'auth.user' => [...]` in `HandleInertiaRequests` | A [resource or view model](http/view-data.md#the-two-shaping-options). |
| Inline `Inertia::render('page', ['user' => [...]])` | Same — a [resource or view model](http/view-data.md#the-two-shaping-options). |
| `Hash::check(...)` inside a controller | An action. |
| `Crypt::encryptString(...)` inside a controller / model | An action; if the cipher is exotic, wrap it behind a port. |
| `new ScopeTrait` for filtering on a model | A [builder class](data/builders.md#when-to-create-a-custom-builder-class). Project-authored scope traits are forbidden. |
| `protected $fillable = [...]` array on a new model | The [`#[Fillable]` attribute](data/models.md#attributes). |
| `protected $hidden = [...]` array on a new model | The [`#[Hidden]` attribute](data/models.md#attributes). |
| `newFactory()` override on a concrete model | Delete it — [auto-resolution](data/factories.md#auto-resolution) covers this case. |

### Transaction misuse

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `DB::transaction(...)` inside a Domain action | Move to the Application action that composes it. Domain actions never open transactions. See [Transactions § Domain actions never wrap](transactions.md#domain-actions-never-wrap). |
| `DB::transaction(...)` inside a controller, service, listener, job, middleware, or model | Move to the Application action. The Application action is the [sole transaction root](transactions.md#the-rule). |
| `DB::transaction()` wrapping a single `$model->save()` | Drop the wrap. A single `save()` is already atomic. See [Transactions § when not to wrap](transactions.md#when-not-to-wrap). |
| Nested `DB::transaction(...)` (one Application action wraps and calls another that also wraps) | Pick one transaction root. The inner action either becomes a Domain action or has its wrap removed. See [Transactions § no nested transactions](transactions.md#no-nested-transactions). |

### Framework-coupling and infrastructure leaks

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `auth()`, `Auth::guard(...)`, `Auth::login(...)` inside an Application action | A controller-injected concrete service in `Infrastructure/Auth/<Strategy>/`. The Application action stays framework-agnostic. |
| `$request->session()->put(...)` / `session(...)` inside an Application action | A controller-injected concrete `*Stash` service in `Infrastructure/<Capability>/<Strategy>/`. |
| `Sanctum::*` / token issuance inside an Application action | An entry-point-specific concrete service like `Infrastructure/Auth/Token/TokenEmployeeAuthenticator`. |
| Composite framework operation (multiple framework calls forming one logical step) inlined into the controller | Extract into a concrete service worth a name. One-liners stay inline; "login + session-regenerate" doesn't. |
| Same one-liner framework call (`auth()->guard(...)->user()`) repeated in 3+ files | Wrap in a small concrete service when the wrapping adds type narrowing or a name worth having. |
| `app(SomeService::class)` inside an action / controller | Constructor-injected dependency; the service-locator pattern is forbidden. |
| `new <Vendor>\<Sdk>(...)` inside an Application action or Domain action | A concrete service in `Infrastructure/External/<Capability>/`. |
| Eloquent query against a soft-disable model that *includes* disabled rows because someone forgot `->active()` | Use a `#[ScopedBy]` global scope so the default is safe. Opt out with `withDisabled()` / `onlyDisabled()`. See [Models § default-safe queries](data/models.md#default-safe-queries-via-global-scope). |
| `#[ScopedBy(...)]` on a reporting or operational aggregate (`Project`, `Document`, `ImportRun`, `AuditEvent`, …) or any aggregate that gets summed, counted, or grouped in a report | Forbidden. Hidden row-filtering produces silently-wrong totals on `sum/count/groupBy`. Use **explicit** builder methods (`->notArchived()`, `->onlyActive()`) and let the caller opt in. See [Models § default-safe queries](data/models.md#default-safe-queries-via-global-scope). |
| Action assigns `$document->status = ...` directly with a guarding `if`-chain checking the previous state | Forbidden when the model qualifies for Trigger B (state-machine or high-impact aggregate). Define a transition method (`$document->publish(...)`) on the model and have it throw on illegal state. See [Models § Trigger B](data/models.md#trigger-b--risk-state-machine-and-high-impact-aggregates). |

### Preemptive abstraction

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `interface FooBar` with exactly one implementation that has no plausible second | Delete the interface; inject the concrete class. The codebase reflects what *is*, not what was anticipated. See [Ports and adapters § de-abstraction](ports-and-adapters.md#de-abstraction-going-from-interface-to-concrete). |
| `interface FooBar` introduced "so we can fake it in tests" | If the only consumer is one fake, delete both. Test consumers with the real implementation, or with a small purpose-built test double constructed inline. |
| Type-hinting a concrete service that has an interface, when the project actually has multiple implementations behind that interface | Type-hint the interface in that case. |
| Port defined in `Infrastructure/<Capability>/Contracts/` | Move it to `Application/<UseCase>/Contracts/` or `Domains/<X>/Contracts/` — ports are owned by callers. |
| `final readonly class FooPolicy { public bool $foo; ... }` wrapping one bool / int / string with no invariant | Use the primitive. The parameter name carries the meaning. See [Value objects § when not to introduce one](data/value-objects.md#when-not-to-introduce-one). |
| `*Result` object whose only fields are `bool $success` + a copy of an argument the caller passed in | Return `bool`. See [Actions § when to return a result object](actions.md#when-to-return-a-result-object). |
| `*Interface`, `*Contract`, `*ValueObject`, `*DTO`, `I*` suffix on a class or interface name | Drop the suffix. The folder (`Contracts/`) and the type (interface vs class) carry the category. |
| Empty service provider with no bindings to register | Delete it. Laravel auto-resolves concrete classes; an empty provider is overhead. |
| Mutable property on a VO / Result / DTO | `readonly`. These types are identity-by-value; mutation breaks the contract. |

### Action placement and result shape

| Signal in code | Where it should go |
| -------------- | ------------------ |
| Action under `Interfaces/<EntryPoint>/Actions/` | That folder does not exist; this is an entry-point-specific concrete service under `Infrastructure/<Capability>/<Strategy>/`. See [Actions § where delivery-coupled writes go](actions.md#where-delivery-coupled-writes-go). |
| Application action returning `RedirectResponse`, `JsonResponse`, or an Inertia response | Return a typed [result object](actions.md#when-to-return-a-result-object), `bool`, or `void`; the controller turns it into HTTP. |
| Application action calling `redirect()`, `back()`, or `Inertia::render(...)` | Move to the controller; the action returns the outcome, the controller produces the response. |
| Application action calling another Application action that calls back into the first | Cycle; refactor by extracting the shared dependency. |
| `event(...)` inside a controller | An [action](actions.md#what-an-action-can-call) — controllers never emit domain events. |
| Domain event payload containing an Eloquent model | Use the aggregate ID + value objects. Models outlive request scope only sometimes; events always do. |

### Routing and HTTP shape

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `Route::get(..., function (...) { ... })` (closure handler with logic) | A controller method: `Route::get(..., [<Controller>::class, 'method'])`. Group/prefix/middleware closures stay (they declare scope, not behavior). |
| `Route::get('/dashboard', fn () => Inertia::render('dashboard'))` | A controller method even for one-line render-only endpoints. Routes stay declarative. |

### Authorization misuse

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `Gate::authorize(...)` / `$this->authorize(...)` inside an Application or Domain action | Move to the controller (resource-level) or request data `authorize()` (caller-level). Actions stay framework-agnostic. See [Authorization](authorization.md). |
| `auth()->user()` / `Auth::user()` inside a Policy | Policy receives the actor as a method parameter; pulling from request scope is service-locator pattern. |
| Policy class outside `Domains/<X>/Policies/` | Move to the aggregate's context so Laravel's auto-discovery resolves it. |
| Authorization split across controller *and* action for the same rule | Pick one layer per rule (delivery vs business). |
| `Gate::define('...', fn() => ...)` ad-hoc closure for a per-aggregate ability | A method on the appropriate `<Aggregate>Policy`. |
| Custom `canBeXBy(...)` method on a model | Authorization belongs in the policy, not the model. |

### Exception misuse

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `throw new \Exception(...)` / `throw new \RuntimeException(...)` from a Domain or Application action | Throw a domain exception extending `App\Domains\<Context>\Exceptions\<Context>Exception`. See [Exceptions](exceptions.md). |
| `try { ... } catch (DomainException $e) { ... }` inside a controller, with no specific HTTP-shape reason | Don't catch — the central handler in `bootstrap/app.php` maps the exception to HTTP. |
| Domain exception class containing `Request`, `Response`, or framework types | Strip framework knowledge; exceptions stay framework-agnostic. |
| Domain exception thrown from a model, builder, or controller | Move the throw into an action; only actions throw domain exceptions. |
| `*Exception` suffix on a concrete failure class (e.g. `EmployeeAlreadyDisabledException`) | Drop the suffix; the namespace already says `Exceptions`. Reserve `*Exception` for the abstract bases (`DomainException`, `<Context>Exception`). |
| Action returning a `bool` or sentinel value to signal a failure that *should* be exceptional (e.g. "resource not found") | Throw a domain exception; reserve `bool`/`Result` for outcomes that are part of the use case's normal vocabulary. |

### Job misuse

| Signal in code | Where it should go |
| -------------- | ------------------ |
| Job in `app/Jobs/` (Laravel default flat folder) | Move to `Application/<UseCase>/Jobs/`. |
| Job in `Domains/<X>/Jobs/` or `Infrastructure/Jobs/` | Move to `Application/<UseCase>/Jobs/`. |
| Eloquent model in a job's constructor | Pass IDs as value objects; the action reloads inside `handle()`. |
| Business logic inside `Job::handle()` | Move logic into the Application action; the job stays a thin wrapper. |
| `dispatch(...)` inside a controller, model, or Domain action | Dispatch from the Application action only. |
| `dispatch(new SomeJob(...))` not chained with `->afterCommit()` when called from inside a transaction root | Use `->afterCommit()` so the job doesn't see uncommitted state. See [Jobs § jobs and transactions](jobs.md#jobs-and-transactions). |
| Job that does not implement `ShouldQueue` (synchronous-only) | Inline the call to the Application action; if it doesn't queue, it isn't a job. |

### Time discipline

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `now()` (helper) anywhere | `CarbonImmutable::now()`. See [Conventions § Time](conventions.md#time). |
| `Carbon::now()` (mutable Carbon) | `CarbonImmutable::now()`. Mutability is a footgun. |
| `new \DateTime(...)` / `new \DateTimeImmutable(...)` | `CarbonImmutable::parse(...)` or `CarbonImmutable::createFromFormat(...)`. |
| `time()` / `microtime(...)` for a domain timestamp | `CarbonImmutable::now()`. Reserve `microtime()` for performance instrumentation only. |
| Storing time as integer seconds in domain code or events | `CarbonImmutable` instance; the persistence cast handles encoding. |
| `Carbon::setTestNow(...)` outside a test | Production code never freezes time. Tests do. |
| Test that depends on wall-clock time without `setTestNow()` | The test is flaky. Freeze time. |

### ID strategy

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `$table->id();` on an aggregate-root migration | `$table->uuid('id')->primary();` — aggregate roots use UUIDv7. See [Conventions § IDs](conventions.md#ids). |
| Aggregate-root model without `HasUuids` and a `newUniqueId()` returning `Str::uuid7()` | Add the trait + override; the default `HasUuids` returns `Str::orderedUuid()` (UUIDv4 with a custom prefix), not a real UUIDv7. |
| `unsignedBigInteger('<aggregate>_id')` referencing a UUID-keyed aggregate | Use `$table->uuid('<aggregate>_id')`; the FK column type must match the PK type. |
| Sequential integer ID in a URL, API response, or domain event payload for an aggregate root | Expose the UUID. Sequential IDs leak count and growth. |
| ULID columns | UUIDv7 instead — Postgres has a native `uuid` type; ULID is stored as `char(26)`. |
| `int` type on an aggregate-root ID parameter | `string` — UUIDv7 is a string type in PHP. |

### Comments

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `// single-line comment` in a `.php` file | Forbidden. Rename / extract a method / move the explanation to a PHPDoc block on the enclosing declaration. See [Conventions § Comments](conventions.md#comments). |
| `/* block comment */` (non-doc) | Forbidden. Use a `/** … */` docblock on the enclosing declaration. |
| `# hash comment` | Forbidden. Same rule as `//`. |
| `// TODO:` / `// FIXME:` / `// HACK:` | Forbidden. Put tasks in the tracker or in an explicit planning document, not in a comment. |
| Commented-out code | Forbidden. Delete it; version control remembers. |

### PHPDoc signal density

| Signal in code | Where it should go |
| -------------- | ------------------ |
| PHPDoc block that only restates the method signature (`@param string $id`, `@return Employee` on `getEmployee(string $id): Employee`) | Delete the block. See [Conventions § PHPDoc \u00a7 don't restate the signature](conventions.md#dont-restate-the-signature). |
| PHPDoc summary that restates the method name (`/** Get the employee. */` on `getEmployee()`) | Delete. The method name carries that already. |
| `@return array` / `@param array $x` / `@return Collection` / `@return iterable` without a shape | Forbidden. Abstract container types always get a shape annotation: `array<int, Employee>`, `Collection<int, Employee>`, `iterable<string, DateRange>`, or `array{id: string, name: string}`. See [Conventions § PHPDoc \u00a7 abstract types always get a shape](conventions.md#abstract-types-always-get-a-shape). |
| `@param` on a parameter whose type is fully expressed in the signature and has no shape or generic narrowing to add | Delete the `@param` line. |
| `@throws` without a specific exception class or without a reason (`@throws \Exception`) | Name the specific exception class and say when it fires, or delete the tag. |
| A method that returns `array` / `iterable` / `Collection` with no accompanying PHPDoc giving the element type | Add the shape annotation. This is required, not optional. |

### Logging

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `Log::info(...)` | Forbidden. Pick `Log::warning` / `Log::error` / `Log::debug` / a domain event / an audit record depending on intent. See [Conventions § Logging](conventions.md#logging). |
| `info(...)` helper | Forbidden. Same reason. |
| `Log::channel('...')->info(...)` on the default channel | Forbidden. Audit channels go through a named wrapper (`AuditLog::record(...)`), not `Log::channel()->info()`. |
| `Log::*` inside a Domain action | Move to the composition root (Application action, controller, listener) or emit a domain event. Domain actions stay framework-agnostic. |
| `Log::*("message for {$var}", [...])` (interpolated message) | Keep the template stable, put variables in the context array: `Log::warning('message for :id', ['id' => $var])` or better `Log::warning('message', ['id' => $var])`. |
| `dd(...)` / `dump(...)` / `ray(...)` in committed code | Forbidden. Local-only debugging tools. Use `Log::debug(...)` if the line genuinely needs to ship. |
| `Log::*` with a user model, request object, or any PII-dense payload | Log stable identifiers (aggregate IDs) only. |

### Cross-context leaks

| Signal in code | Where it should go |
| -------------- | ------------------ |
| `use App\Domains\<Other>\Models\<X>` inside `Domains/<This>/` | Cross-context boundary violation. Use a [domain event, published action, or published read model](cross-context.md). |
| `use App\Domains\<Other>\Actions\<X>` inside `Domains/<This>/` | Domain actions are never cross-context callable. The cross-context call belongs in `Application/<UseCase>/`. See [Cross-context communication](cross-context.md). |
| `use App\Application\<Other>\<X>Action` inside another context's Application use case, where the target action is **not** marked `@published` | Either promote the target action to a [published action](cross-context.md#published-actions) (if cross-context exposure is intended) or split the use case so each context owns its own work. |
| Foreign key to another context's table | Store an opaque ID; project the foreign data via a [published read model](cross-context.md#published-read-models) or domain events. |
| `JOIN` across contexts in a builder or query | A [published read model](cross-context.md#published-read-models) in the producing context that owns the join. |
| Listener for context A's event registered inside `Domains/A/` | Listeners belong to the **reacting** context. Move the registration to the listener context's `DomainServiceProvider`. |
| Domain event payload containing another context's model or aggregate | Carry primitives, IDs, and value objects only. The receiver looks foreign data up via a published read model if needed. |

## Worked example — refactor sketch

The following is the most common shape of a guidelines violation in this codebase: a controller method that does the controller's job *and* the action's job *and* the builder's job at once.

### Before

```php
// LoginController.php
public function store(Request $request, LoginData $data): RedirectResponse
{
    $employee = Employee::query()
        ->whereNull('disabled_at')
        ->where('email', $data->email)
        ->first();

    if ($employee === null || ! Hash::check($data->password, $employee->password)) {
        throw ValidationException::withMessages([...]);
    }

    if ($employee->hasEnabledTwoFactorAuthentication()) {
        $request->session()->put(['login.id' => $employee->getKey(), 'login.remember' => $data->remember]);
        return redirect()->route('two-factor-challenge.show');
    }

    Auth::guard('admin')->login($employee, $data->remember);
    $request->session()->regenerate();
    $employee->forceFill(['last_login_at' => now()])->save();

    return redirect()->intended(route('dashboard'));
}
```

Issues this triggers from the table above:

- `Model::query()->whereNull('disabled_at')->where(...)` in controller → builder + a global scope so `disabled_at` is excluded by default
- `Hash::check(...)` in controller → Application action
- Two writes (`Auth::login` + session regenerate; `forceFill->save`) in controller → Domain action plus a controller-injected concrete service
- 2FA branch in controller → Application action returning a typed result the controller branches on
- Inline session writes → a controller-injected concrete `*Stash` service

### After

The work splits into: a controller (composes), an Application action (orchestrates the use case), a Domain action (mutates one aggregate), and two entry-point-specific concrete services for the framework-coupled steps. No interfaces, no fakes, no service-provider bindings.

```php
// Interfaces/AdminWeb/Controllers/Auth/LoginController.php
final class LoginController
{
    public function __construct(
        private VerifyEmployeeCredentialsAction $verifyEmployeeCredentialsAction,
        private RecordEmployeeLoginAction $recordEmployeeLoginAction,
        private SessionEmployeeAuthenticator $sessionEmployeeAuthenticator,
        private SessionPendingLoginStash $sessionPendingLoginStash,
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

```php
// Application/EmployeeAuth/VerifyEmployeeCredentialsAction.php
final class VerifyEmployeeCredentialsAction
{
    public function __construct(
        private Hasher $hasher,
    ) {}

    public function execute(string $email, string $password): VerifyEmployeeCredentialsResult
    {
        /** @var EmployeeBuilder $employeeQuery */
        $employeeQuery = Employee::query();

        // Global scope already excludes disabled employees.
        $employee = $employeeQuery->whereEmail($email)->first();

        if ($employee === null || ! $this->hasher->check($password, $employee->password)) {
            return VerifyEmployeeCredentialsResult::invalid();
        }

        if ($employee->hasEnabledTwoFactorAuthentication()) {
            return VerifyEmployeeCredentialsResult::pendingTwoFactor($employee);
        }

        return VerifyEmployeeCredentialsResult::verified($employee);
    }
}
```

```php
// Domains/Employees/Actions/RecordEmployeeLoginAction.php
final class RecordEmployeeLoginAction
{
    public function execute(Employee $employee): void
    {
        $employee->last_login_at = CarbonImmutable::now();
        $employee->saveOrFail();
    }
}
```

```php
// Infrastructure/Auth/Session/SessionEmployeeAuthenticator.php
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

What each piece owns:

- **Controller**: HTTP shape only — invokes Application action, branches on result, calls the concrete services for delivery-coupled work, returns redirect.
- **`VerifyEmployeeCredentialsAction`** (Application): the Login use case — query, hash check, 2FA branch. Returns a typed result. Framework-agnostic; runs unchanged in any entry point.
- **`RecordEmployeeLoginAction`** (Domain): one `Employee` invariant — update `last_login_at`. Reusable from any caller.
- **`SessionEmployeeAuthenticator`** + **`SessionPendingLoginStash`** (concrete services in `Infrastructure/Auth/Session/`): the delivery-coupled side — guard login + session regenerate, pending-login session keys. Strategy-prefixed; a future `Token*` sibling for `PartnerApi` slots in obviously parallel.

When `PartnerApi` arrives, its `LoginController` reuses `VerifyEmployeeCredentialsAction` unchanged and pairs it with a `TokenEmployeeAuthenticator` concrete service, returning JSON. No interface needed; the entry point IS the polymorphism boundary.

Tests:

- The controller is feature-tested end-to-end with the real `SessionEmployeeAuthenticator` (the test runs the whole HTTP kernel, asserts redirect + auth state).
- `VerifyEmployeeCredentialsAction` is unit-tested without booting any HTTP kernel.
- `RecordEmployeeLoginAction` is unit-tested with a factory-built `Employee`.

The 30-line branching mess becomes four focused classes, each independently testable, none of them coupled to a delivery shape they do not own.

## See also

- [Architecture § layer responsibilities](architecture.md#layer-responsibilities) — the rules each row of the table is a violation of.
- [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md) — the cross-cutting docs whose rules the new red-flag tables enforce.
- [Testing § PR / merge checklist](testing.md#pr--merge-checklist) — a complementary pre-merge checklist.
- Each linked topic file owns the rule it points at; this file owns only the recognition signals.
