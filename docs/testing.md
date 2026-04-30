# Testing

> **Owns**
>
> - Test discipline (feature vs unit) and what each is for
> - Test commands (`php artisan test --compact`, `--filter`)
> - The PR / merge checklist
>
> **Forbids**
>
> - Claiming a feature is "done" without tests
> - Weakening or deleting tests to make them pass
>
> **See also**: [Architecture](architecture.md), [Anti-patterns](anti-patterns.md), [Actions](actions.md), [Request data](http/request-data.md)

Every change ships with tests. No exceptions. The test layer mirrors the layer the code lives in.

## What to test

### Feature tests (most tests)

Feature tests cover an HTTP route end-to-end. For each route in a feature, write tests for at least:

- **The happy path** — the request the UI is designed to make, with valid input, returns the expected redirect / view / status.
- **Validation failures** — required fields missing, format errors, duplicate emails, etc. Each rule in the [request data object](http/request-data.md) is exercised.
- **The auth / permission boundary** — guests get redirected, the wrong guard gets 403, a disabled account gets blocked. Whatever the route's guard chain promises, prove it.

When the feature involves data fetching, add a test that the page-prop shape matches what the frontend expects. See [View data](http/view-data.md) for why shaping is centralised; tests are why it stays correct.

### Unit tests

Unit tests cover code that has non-trivial branching independent of HTTP:

- **Domain actions** with branches — invariant enforcement, lifecycle-transition guards, recovery-code consumption (valid / invalid / already-consumed).
- **Application actions** with branches — login (2FA enabled / disabled / failed credentials), 2FA confirmation (correct / incorrect code), request approval (within / over limit).
- **Builder methods** that combine multiple conditions. A `->withConfirmedTwoFactor()` helper has one assertion; a `->searchableBy(string $term)` that ORs across columns has several.
- **Concrete services** — verify they call the right framework / vendor primitives, with the right translation. Service tests usually need framework boot, so they live in `tests/Integration/Infrastructure/<Capability>/<Strategy>/` rather than `tests/Unit/`.
- **Value objects** — constructor validation, equality, derived state. (Skip when the value object is a thin wrapper a primitive could replace; see [Value objects § when not to introduce one](data/value-objects.md#when-not-to-introduce-one).)
- **Result objects** — named-constructor outcomes (`::verified()`, `::pendingTwoFactor()`, `::invalid()`).

### Integration tests

Integration tests cover a concrete service's behaviour against the real framework / vendor primitive (or a tightly-controlled stand-in like a real Postgres in CI). Their job is to assert that the service does what its method names promise *with the framework actually involved*. Live under `tests/Integration/Infrastructure/<Capability>/<Strategy>/`.

Service integration tests are run on the *service*, not on every consumer that uses it. Consumers are exercised end-to-end via feature tests.

### Substituting collaborators in tests

Most consumers are exercised with the real production service in feature tests. The test runs the whole HTTP kernel, hits the database, and asserts on observable outcomes (redirect, session state, persisted rows).

When you need to substitute a collaborator (because it makes a real network call, or because asserting on its inputs is the point of the test), prefer the lightest tool that fits:

1. **Inline test double.** Construct a small purpose-built object in the test that satisfies the type the consumer expects.
2. **Laravel framework fakes.** `Mail::fake()`, `Bus::fake()`, `Queue::fake()`, `Event::fake()`, `Http::fake()` are first-class testing tools. Use them when the production code calls Laravel's facade.
3. **Container bind override.** When a service is genuinely awkward to substitute (e.g. third-party HTTP client), bind a stub class for the duration of the test:
   ```php
   $this->app->bind(SearchIndexer::class, fn () => new StubSearchIndexer());
   ```

Do **not** introduce a permanent `Fake*` class for every concrete service "so we can swap it in tests". A class that exists only to be a test target is dead weight in `app/`. Keep test doubles in `tests/` (or inline) when they only serve tests; promote a fake to `app/Infrastructure/.../Fake/` only when dev/staging environments also use it.

## Test commands

- **Run everything (compact output):**
  ```bash
  make artisan ARGS="test --compact"
  ```
- **Filter to a specific test:**
  ```bash
  make artisan ARGS="test --filter=LogInEmployeeActionTest --compact"
  ```
- **Filter to a path:**
  ```bash
  make artisan ARGS="test tests/Feature/Auth --compact"
  ```

When you finish writing a feature, run the smallest filter that covers it. When that is green, run the full suite.

## Test placement

Mirror the source layout:

```text
tests/
├── Feature/
│   ├── <EntryPoint>/                ← AdminWeb, PartnerApi, …
│   │   └── <Group>/
│   │       └── <Verb><Noun>Test.php  ← HTTP-shaped tests for controllers
│   └── <ContextName>/
│       └── <Verb><Noun>Test.php     ← cross-cutting flows that span layers
└── Unit/
    ├── Application/
    │   └── <UseCase>/
    │       ├── <Verb><Noun>ActionTest.php
    │       └── <Verb><Noun>ResultTest.php
    └── Domains/
        └── <ContextName>/
            ├── Actions/
            ├── Builders/
            ├── Models/
            ├── Scopes/                  ← global-scope behaviour
            └── ValueObjects/

tests/Integration/
└── Infrastructure/
    └── <Capability>/
        └── <Strategy>/                  ← production service against real framework / vendor
```

A test file's name reflects the unit it tests — `VerifyEmployeeCredentialsActionTest`, `RecordEmployeeLoginActionTest`, `EmployeeBuilderTest`, `SessionEmployeeAuthenticatorTest`. Avoid generic names like `LoginTest` that grow over time into thousand-line god-tests.

## PR / merge checklist

Before opening a PR:

- [ ] No `where(...)` chains in controllers or actions — all on builders. ([Builders](data/builders.md))
- [ ] No `$request->validate([...])` calls — all rules in a request data object. ([Request data](http/request-data.md))
- [ ] No `forceFill(...)->save()`, `update([...])`, or `save()` inside models or traits. ([Models](data/models.md))
- [ ] Every state-changing endpoint composes Application actions and concrete services; the controller does not directly call domain models, vendor SDKs, or unwrap composite framework operations. ([Actions](actions.md))
- [ ] Application actions return typed result objects, primitives, or `void`, not HTTP responses. ([Actions § when to return a result object](actions.md#when-to-return-a-result-object))
- [ ] No `new <Vendor>\<Sdk>(...)` calls in `Application/` or `Domains/`. Wrap in a concrete service in `Infrastructure/`. ([Services](services.md))
- [ ] No interface introduced without ≥2 implementations or imminent arrival. ([Ports and adapters § the trigger rule](ports-and-adapters.md#the-trigger-rule))
- [ ] No empty service providers — if there are no bindings to register, the provider doesn't exist.
- [ ] No cross-context model imports. ([Architecture § cross-context communication](architecture.md#cross-context-communication))
- [ ] Multi-step writes are wrapped in `DB::transaction()` inside the action. ([Actions § signature rules](actions.md#signature-rules))
- [ ] Controllers are `final`, ≤ ~30 lines per method. ([Controllers § style](http/controllers.md#style))
- [ ] Inertia page props are shaped by an `<X>Resource` or `<X>ViewModel`, not inline arrays. ([View data](http/view-data.md))
- [ ] Feature tests cover happy path + validation failure + auth boundary.
- [ ] Unit tests cover non-trivial branching in Domain actions, Application actions, and combination logic in builders.
- [ ] Service integration tests cover any new service that wraps a real framework or vendor primitive, under `tests/Integration/Infrastructure/<Capability>/<Strategy>/`.
- [ ] `php artisan test --compact` is green.
- [ ] Pint is clean (`vendor/bin/pint --dirty --format agent`).

## Discipline rules

- **Tests are written alongside the feature, not after.** A feature plan that schedules tests for "phase 8" is a feature plan that ships untested.
- **Never delete or weaken a test to make a build pass.** If a test is wrong, fix it; if it is right and the code is wrong, fix the code. The third option — silencing the test — is always wrong.
- **Run the test for the feature you just changed.** Failing fast on a single filter is cheaper than waiting for the full suite.

## See also

- [Anti-patterns § PR table](anti-patterns.md#red-flags) — what to grep for in review.
- [Architecture](architecture.md) — the layering each test category corresponds to.
- [Request data](http/request-data.md), [Actions](actions.md), [Services](services.md) — the layers most tests live next to.
