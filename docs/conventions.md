# Conventions

> **Owns**
>
> - `declare(strict_types=1)` rule for every PHP file
> - `final` on concrete classes, `abstract` on base classes
> - Namespace mirrors folder path
> - Naming style for classes, methods, variables, enums
>
> **Forbids**
>
> - Folder layout itself — see [Architecture](architecture.md)
> - Layer responsibilities — see [Architecture](architecture.md#layer-responsibilities)
> - Per-layer skeletons (Models, Controllers, Actions, …) — see the relevant topic file
>
> **See also**: [Architecture](architecture.md), [README](README.md)

Language-level rules that apply to every PHP file in this project, regardless of layer.

## Strict types

Every PHP file in `app/`, `tests/`, and `database/` declares strict types as the first non-comment line:

```php
<?php

declare(strict_types=1);

namespace App\<...>;
```

Files that omit this declaration are not idiomatic and should be fixed in the same change you touch them.

## Class modifiers

- **Concrete classes are `final`.** Models, controllers, actions, services, form requests, factories, builders — all `final` once they are not intended to be subclassed.
- **Base classes are `abstract`.** Anything named `Base<…>` (`BaseModel`, `BaseAuthenticatable`) is `abstract`. The exception is `BaseBuilder`, which is intentionally instantiable — see [Builders § BaseBuilder](data/builders.md#basebuilder).
- No "open for extension" without a documented reason. If you find yourself removing `final`, leave a one-line comment explaining what subclasses it and why.

## Namespaces mirror the folder path

A class at `app/Domains/Employees/Models/Employee.php` lives in namespace `App\Domains\Employees\Models`. No exceptions.

This is what lets the project's `HasFactory` trait derive the factory namespace from the model namespace by simple string substitution — see [Factories § auto-resolution](data/factories.md#auto-resolution). Breaking the mapping breaks that resolution and a handful of other conventions.

## Naming

| Construct                            | Style                                                                                  |
| ------------------------------------ | -------------------------------------------------------------------------------------- |
| Classes                              | `PascalCase`. Suffix-typed by role: `<Name>Controller`, `<Name>Action`, `<Name>Resource`, `<Name>Builder`, `<Name>Factory`, `<Name>Request`. |
| Variables / properties / parameters  | `camelCase`. Descriptive: `isRegisteredForDiscounts`, not `discount()`.                |
| Methods                              | `camelCase`. Verbs for actions / mutations, predicates for booleans (`isDisabled()`, `hasConfirmedTwoFactor()`). |
| Constants                            | `UPPER_SNAKE_CASE`.                                                                    |
| Enum keys / cases                    | `TitleCase`: `FavoritePerson`, `Monthly`.                                              |
| Database tables / columns            | `snake_case`. Plural for tables, singular for columns.                                 |
| Routes (URL paths)                   | `kebab-case`: `/two-factor-challenge`, `/recovery-codes`.                              |
| Route names                          | `dot.case`: `two-factor.challenge`, `auth.login`.                                      |
| Inertia page components              | `kebab-case` paths matching the route: `auth/login`, `auth/two-factor-challenge`.      |

### Avoid

- One-letter or two-letter variable names except short-lived loop indices.
- Method names that hide what they do: `handle()`, `process()`, `doSomething()`. Prefer the verb.
- Generic class suffixes: `EmployeeService`, `EmployeeManager`, `AuthHelper`. They quietly accumulate behavior. See [Actions § naming](actions.md#naming) for the alternative.
- Hungarian notation, type prefixes (`strName`, `iCount`), and `I`-prefixed interfaces.
- Suffixes that restate the folder a class lives in: `EmployeeIdValueObject`, `LoginResultDTO`, `SearchIndexerInterface`, `EmployeeAuthenticatorContract`. The folder (`ValueObjects/`, `Contracts/`) carries that information; the suffix is redundant.

### Value-object naming

Value objects are nouns from the domain vocabulary, with no suffix. The folder communicates what they are.

| Good | Bad |
|---|---|
| `Email` | `EmailValueObject`, `EmailVO`, `EmailType` |
| `DateRange` | `DateRangeValue`, `DateRangeDTO` |
| `EmployeeId` | `EmployeeIdValueObject` |
| `PendingLogin` | `PendingLoginDTO` |

The full rule lives in [Value objects § naming](data/value-objects.md#naming).

### Port and service naming

Interfaces are nouns describing a capability, with no suffix. Concrete services are nouns optionally prefixed with the strategy.

| Good | Bad |
|---|---|
| `EmployeeAuthenticator` (interface, when justified) | `EmployeeAuthenticatorInterface`, `IEmployeeAuthenticator`, `EmployeeAuthenticatorContract` |
| `SessionEmployeeAuthenticator` (concrete, strategy-prefixed) | `EmployeeAuthenticatorImpl`, `EmployeeAuthenticatorService` |
| `TwoFactorAuthenticator` (concrete, no variant anticipated) | `TwoFactorAuthenticatorService` |

The full rule lives in [Ports and adapters § naming](ports-and-adapters.md#naming).

### Variable names match the class they hold

When a variable holds an instance of a class — most importantly a constructor-promoted property — its name is the **camelCase form of the full class name**. No truncation, no role-based shorthand.

Good:

```php
public function __construct(
    private LogInEmployeeAction $logInEmployeeAction,
    private TwoFactorAuthenticator $twoFactorAuthenticator,
    private RecordEmployeeLoginAction $recordEmployeeLoginAction,
    private SessionGuard $sessionGuard,
) {}
```

Bad:

```php
public function __construct(
    private LogInEmployeeAction $logIn,            // truncated
    private TwoFactorAuthenticator $authenticator, // role-only
    private RecordEmployeeLoginAction $record,     // role-only
    private SessionGuard $guard,                   // role-only
) {}
```

The reason is that role-based shorthands stop being unique the moment a second similar dependency arrives (`$action` becomes ambiguous when there are two; `$guard` collides with `Illuminate\Contracts\Auth\Guard`). Matching the full class name keeps every reference grep-friendly and self-documenting.

Edge cases:

- **Stylistic class capitalization** (e.g. `LogIn`, `LogOut`, `XmlParser`) collapses into the conventional word form in the variable: `$loginEmployeeAction`, `$logoutEmployeeAction`, `$xmlParser`. The variable is normal English, not a re-statement of the class casing.
- **Acronyms in class names** lowercase the leading acronym in the variable: `Google2FA $google2fa`, `JsonResponse $jsonResponse`, `XMLParser $xmlParser`.
- **Local variables** holding a single instance of a class follow the same rule (`$twoFactorAuthenticator = new TwoFactorAuthenticator(...)`), unless a tighter, equally-clear name is justified by context (`$activeEmployee` over `$employee` when there are two `Employee` instances in scope).
- **A repository's persistence-mechanism prefix may be dropped.** `EloquentOrderRepository $orderRepository` is preferred; `$eloquentOrderRepository` is also allowed. The `Eloquent`/strategy prefix is storage noise at the call site, and `orderRepository` stays unique and grep-friendly. This narrow exception is for the persistence prefix only — it does not license role-only shorthands (`$repository`, `$orders`) or dropping a *meaningful* strategy prefix elsewhere (a `SessionEmployeeAuthenticator` is still `$sessionEmployeeAuthenticator`, because `Session` distinguishes it from other authenticators).

## Type declarations

- Method parameters and return types are **always** declared. `function foo($x)` is not acceptable — write `function foo(string $x): int`.
- Use nullable types (`?string`) over union with null (`string|null`).
- Use union types only when no narrower alternative exists.
- Prefer `void` to no return type when a method genuinely returns nothing.

## Comments

The only comment form allowed in production PHP is the **PHPDoc block** (`/** … */`) attached to a declaration (class, method, property, constant, parameter, enum case). Every other comment form is forbidden:

| Forbidden | Rule |
| --------- | ---- |
| `// single-line comment` | Forbidden. If the code needs explanation, either (a) rename so the intent is in the code, (b) extract the block into a well-named private method, or (c) write it as a PHPDoc block on a declaration that owns the concern. |
| `/* block comment */` (non-doc) | Forbidden. Use a `/** … */` docblock on the enclosing declaration. |
| `# hash comment` | Forbidden. Same rule as `//`. |
| `// TODO:` / `// FIXME:` / `// HACK:` | Forbidden. Open a task in the tracker or in an explicit planning document, not a comment the compiler ignores. |
| Commented-out code | Forbidden. Delete it — version control remembers. |

Exceptions — these are comments but do not count as "comments" for this rule:

- `declare(strict_types=1);` and similar directive lines.
- The `<?php` opening tag.
- The doc-block attached to the top of a file for copyright / license headers (the project doesn't currently use these; if it ever does, they are PHPDoc form).

Markdown, YAML, TypeScript, and shell files are out of scope — this rule is about PHP.

The rationale: comments drift silently because they aren't type-checked, Pint-checked, or test-exercised. Forcing explanation into either the code shape or a docblock on a declaration keeps the explanation close to something a tool can verify is still aligned with reality.

## PHPDoc

PHPDoc blocks exist to express **what a PHP type declaration cannot**, nothing more.

### When to write one

Write a PHPDoc block only when at least one of these is true:

1. A return type, parameter type, or property type is an **abstract container** (`array`, `iterable`, `\Traversable`, `\Generator`, `Collection`, `LazyCollection`, `EloquentCollection`, `Paginator`, `CursorPaginator`, or any other class that holds elements of a type not expressed in the declaration). The block must narrow the element shape.
2. A type is a **template parameter** (`@extends BaseModel<EmployeeBuilder>`, `@template TBuilder of BaseBuilder`).
3. A nullability, return state, or throw is non-obvious from the call site and the reader genuinely needs it (`@throws <SpecificException> when …`).

If none of the above hold, **do not write a PHPDoc block**. A docblock that only restates what the signature says is noise.

### Don't restate the signature

Forbidden:

```php
/**
 * Get the employee.
 *
 * @param  string  $id
 * @return Employee
 */
public function getEmployee(string $id): Employee { /* … */ }
```

Reason: every tag restates what the signature already says, and "Get the employee" restates the method name. The whole block can be deleted without losing information.

Allowed (because it carries information the signature can't):

```php
/**
 * @return array<int, Employee>
 *
 * @throws EmployeeNotFound when no employee matches any of the given IDs
 */
public function getEmployeesByIds(array $ids): array { /* … */ }
```

Here the return type `array` is abstract and the `@throws` is not inferable.

### Don't narrate the class or method

A docblock that *describes what a class or method is for* — in prose, not types — is forbidden. The class name plus its folder already state the role; the method name plus its body already state the behavior. Narration restates them and then drifts.

Forbidden:

```php
/**
 * Persistence adapter for the Employee aggregate. All employee writes and
 * load-to-mutate reads go through here, so no action or controller touches
 * the query layer directly.
 */
final class EloquentEmployeeRepository { /* … */ }
```

```php
/**
 * Persist the actor's role/permission grants through the Spatie storage binding.
 */
public function syncAccess(Employee $employee, array $roles, array $permissions): void { /* … */ }
```

Both blocks say only what the name and signature already say. Delete them. If a class's purpose isn't clear from its name and location, rename it; if a method's behavior isn't clear from its name and body, rename it or extract a well-named private method.

### The only narrative exception

A prose comment (PHPDoc or, here only, a short `//`) is allowed when it explains something a reader **cannot** infer from correct, conventional code:

- behavior that is **temporary** or a known stopgap (with the condition for removal),
- code that deliberately goes **against the documented convention** (and why),
- a genuinely **complex or unconventional** mechanism whose *why* is non-obvious (an ordering subtlety, a concurrency or security reason, a third-party quirk).

The test: if the comment explains *why this code is not what a reader would expect*, keep it. If it explains *what the code does*, delete it. Example of an allowed comment:

```php
// owned/assigned scopes resolve to bounded id sets; none exist for the current
// population, so anything short of an `all` scope denies — revisit when memberships land.
return $hasAllScope ? ScopeSpecification::all() : ScopeSpecification::none();
```

### Abstract types always get a shape

Whenever a type is one of the container shapes listed above, **an accompanying PHPDoc line giving the element type is required**. No abstract container types without a shape annotation.

| Declaration | Forbidden | Required |
| ----------- | --------- | -------- |
| `function foo(): array` | no docblock, or `@return array` | `@return array<int, Employee>` (list), `@return array<string, DateRange>` (map), `@return array{id: string, name: string}` (shape) |
| `function foo(array $items): void` | `@param array $items` | `@param array<int, Employee> $items` |
| `function foo(): Collection` | `@return Collection` | `@return Collection<int, Employee>` |
| `function foo(): LazyCollection` | `@return LazyCollection` | `@return LazyCollection<int, Employee>` |
| `function foo(): iterable` | `@return iterable` | `@return iterable<int, Employee>` or `@return iterable<string, DateRange>` |
| `function foo(): \Generator` | `@return \Generator` | `@return \Generator<int, Employee>` |
| `public array $employees;` | `@var array` | `@var array<int, Employee>` |

The "key, value" form (`array<int, Employee>`) is the default. Use `array<Employee>` (one-arg) only when the keys are genuinely immaterial — rare. Use the shape form (`array{…}`) for fixed-key records (DTO-shaped arrays, config shapes).

### Signature-first

If a type *can* be expressed in a real PHP declaration, express it there — don't use a PHPDoc block as a substitute. `@param` should never appear for a parameter whose type is fully expressed in the signature.

The exception is generics: PHP has no `function <T>(...)` syntax, so generic parameters live in PHPDoc (`@template`, `@extends`). See [Models § static analysis and IDE notes](data/models.md#static-analysis-and-ide-notes) for the template pattern used by base classes.

## Constructor property promotion

Use PHP 8 constructor property promotion. Empty `__construct()` methods (for classes with no dependencies) are noise — omit them.

Mark promoted dependencies `readonly` whenever they aren't reassigned after construction. Same for VO / DTO / Result fields. Most of the codebase qualifies.

```php
final class EnableEmployeeTwoFactorAction
{
    public function __construct(
        private TwoFactorAuthenticator $twoFactorAuthenticator,
    ) {}

    public function execute(EmployeeId $employeeId): void { /* … */ }
}
```

## Routing

Route handlers are always controller methods, never closures. `Route::get(..., [<Controller>::class, 'method'])`. Closures are reserved for `group()`, `prefix()`, `middleware()`, and similar scope-declaring helpers — not for request handling. Render-only Inertia endpoints get a controller too. See [Routes](http/routes.md).

## Time

All wall-clock reads go through `CarbonImmutable::now()`. Other time-source calls are forbidden:

| Forbidden | Use instead |
| --------- | ----------- |
| `now()` (helper) | `CarbonImmutable::now()` |
| `Carbon::now()` (mutable) | `CarbonImmutable::now()` |
| `new \DateTime(...)` / `new \DateTimeImmutable(...)` | `CarbonImmutable::parse(...)` or `CarbonImmutable::createFromFormat(...)` |
| `time()` / `microtime(...)` for any domain timestamp | `CarbonImmutable::now()` |
| Storing time as integer seconds in domain code | `CarbonImmutable` instance; the cast at the persistence boundary handles formatting |

Why `CarbonImmutable` and not `Carbon`: mutability is a footgun — `$instant->addDay()` mutates instead of returning a new instance, which silently breaks invariants. Immutable everywhere, no exceptions.

Why a global rule instead of a `Clock` abstraction: Laravel already provides a battle-tested test hook (`Carbon::setTestNow($instant)`) that freezes time globally for the test. The rule is enforced by discipline + grep + Pint, not by injecting a `Clock` interface. The trigger rule for introducing an interface (≥2 implementations, see [Ports and adapters](ports-and-adapters.md#the-trigger-rule)) is not met for time — there is one wall clock and one test override.

In tests:

```php
use Carbon\CarbonImmutable;

public function test_recording_a_login_stamps_the_employee(): void
{
    $now = CarbonImmutable::parse('2026-04-27 10:00:00');
    CarbonImmutable::setTestNow($now);

    $employee = Employee::factory()->create();
    $this->recordEmployeeLoginAction->execute(
        EmployeeId::fromString((string) $employee->getKey()),
    );

    $this->assertTrue($employee->refresh()->last_login_at->equalTo($now));
}
```

`setTestNow()` resets between tests automatically. No teardown needed.

## IDs

Aggregate roots must have **stable identities**. The concrete ID format is a project-level technical decision, not a DDD rule.

### Rule

Allowed stable formats include `int`, `uuid`, `uuidv7`, `ulid`, prefixed strings, or another format the project explicitly chooses.

Rules:

- **Aggregate roots have stable IDs.** They can be referenced across requests, events, jobs, logs, and bounded-context boundaries.
- **The format is not global by default.** Do not assume every aggregate ID uses the same database type unless the project has explicitly decided that.
- **Identity value objects mirror the owning context's decision.** `EmployeeId`, `UserId`, and `OrganizationId` should not validate one global format unless the owning context actually requires that format.
- **Internal-only tables follow their technical needs.** Pivots, framework tables, lookup tables, queue tables, and audit/log tables may use composite keys, natural keys, autoincrement IDs, or another stable shape.
- **Cross-context contracts depend on identity semantics, not storage type.** Other contexts should not need to know whether an ID is stored as `bigint`, `uuid`, `char(26)`, or something else.

### Optional ID Format Decision

Choosing `int`, `uuid`, `uuidv7`, or `ulid` is an implementation tradeoff:

- `int` / autoincrement is compact and framework-friendly, but exposes sequence information if used externally.
- `uuid` / `uuidv7` is globally unique and widely supported; UUIDv7 adds insertion-order locality where supported.
- `ulid` is sortable and human-copyable, but is usually stored as text unless the project adds custom storage.
- Prefixed strings can improve operational clarity, but require a deliberate convention.

This project documentation does not choose one. Decide per project, document the decision, then keep ID value objects and migrations consistent with it.

## Logging

`Log::info(...)` is **forbidden**. Every other log level is allowed but must carry intent.

### Why `info` specifically

`info` is the level engineers reach for when they haven't decided what the line is *for*. Is it a durable audit record? A debugging aid? A signal an operator should look at? Banning the level forces the author to commit to one of these before writing a line:

| Intent | Mechanism |
| ------ | --------- |
| "Something went wrong and a human should look" | `Log::error(...)` (or a thrown domain exception + central handler, when the context is a single request) |
| "Something suspicious but not fatal" | `Log::warning(...)` |
| "A business-meaningful thing happened that we want to persist forever" | Dispatch a [domain event](domain-events.md); events are first-class, typed, searchable, and replayable. A log line is not. |
| "A business-meaningful thing happened that needs durable audit" | A structured audit channel (dedicated Monolog channel, persisted store, or event → listener → DB table). Not the default log stream. |
| "I want to see this value while debugging" | `Log::debug(...)` — filtered out in production by `LOG_LEVEL=info` (or higher). `dd()` / `ray()` / `dump()` in tests and local only, never in committed code. |

### Forbidden

| Signal | Rule |
| ------ | ---- |
| `Log::info(...)` anywhere | Forbidden. Pick one of the alternatives in the table above. |
| `info(...)` helper | Forbidden. Same reason. |
| `Log::channel('...')->info(...)` | Forbidden on the default channel. A **dedicated audit channel** may use `info`, but the call sites must go through a wrapper named for the intent (e.g. `AuditLog::record(...)`), not through `Log::channel(...)->info(...)` directly. |
| `Log::info()` to announce a request or job started / finished | Use framework logging channels for request / queue lifecycle; don't re-implement them. |
| `Log::*` inside an aggregate method | Aggregates stay framework-agnostic. Log at the composition root (Application action, controller, listener) — or record an event and let a listener log after dispatch. |
| `Log::*($message, ['user' => auth()->user()->toArray()])` | PII in unstructured logs is forbidden. See future PII plan; for now, log stable identifiers (aggregate IDs) only. |

### Allowed

- `Log::error(...)`, `Log::critical(...)`, `Log::alert(...)`, `Log::emergency(...)` for genuine failures.
- `Log::warning(...)` for suspicious-but-non-fatal situations.
- `Log::debug(...)` for developer-facing diagnostics; production `LOG_LEVEL` filters them out.
- A future project-specific `AuditLog` service for durable, structured, business-event records (not yet introduced — see the "when a second implementation coexists" trigger rule in [Ports and adapters](ports-and-adapters.md#the-trigger-rule)).

### Structured context

Every `Log::*` call passes a structured context array, never a concatenated message:

```php
Log::warning('Employee login rejected: disabled account.', [
    'employee_id' => $employee->getKey(),
    'ip' => $request->ip(),
]);
```

String-interpolated identifiers (`"Login rejected for {$employee->id}"`) make log aggregation useless. Keep the message template stable and put variables in the context array.

## See also

- [Architecture](architecture.md) — the folder layout and layer responsibilities these rules sit on top of.
- [Value objects § identifier value objects](data/value-objects.md#identifier-value-objects) — identity contracts and format-aware validation.
- [Anti-patterns](anti-patterns.md) — grep-friendly red flags for time and ID violations.
- [README](README.md) — the topic index.
