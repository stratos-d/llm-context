# Models

> **Owns**
>
> - `BaseModel` and `BaseAuthenticatable` definitions
> - Concrete model wiring (`#[UseEloquentBuilder]`, `@extends Base<Builder>`)
> - Database-backed magic property PHPDoc (`@property`)
> - `#[Fillable]` / `#[Hidden]` attribute usage
> - What belongs / does not belong on a model
> - What does not belong in a base class
> - Traits on models — read-only-helpers rule
>
> **Forbids**
>
> - Builder definitions and filter chains — see [Builders](builders.md)
> - Factory mechanics — see [Factories](factories.md)
> - Persistence calls (`save()`, `update()`, `forceFill()->save()`) — see [Actions](../actions.md)
> - Third-party SDK construction — see [Services](../services.md)
>
> **See also**: [Builders](builders.md), [Factories](factories.md), [Architecture](../architecture.md), [Actions](../actions.md)

Models in this project are **aggregate roots and aggregate parts** within a [bounded context](../architecture.md#bounded-contexts-and-aggregates). They hold state, casts, relationships, and read-only state helpers. They never write to themselves; state mutation lives in [actions](../actions.md). All names like `Employee` / `EmployeeBuilder` in the samples below are illustrative.

## Aggregate root vs aggregate part

A model is its **own aggregate root** when **all** of these hold:

- It has its own **identity** — a UUIDv7 primary key, exposed externally (URLs, APIs, events). See [IDs](#ids).
- It has its own **lifecycle** — rows are created, mutated, and deleted on a cadence that doesn't track any other aggregate.
- It is **not loaded exclusively as a child** — callers reach it by querying its own builder, not only via another model's `->relation`.
- It has at least one **action that operates on it directly** — there is a `<Verb><ThisModel>Action.php` somewhere in `Application/` or `Domains/<X>/Actions/`, not only actions that mutate a parent and touch this model transitively.

If any of these fail, the model is an **aggregate part** — it belongs inside another aggregate's consistency boundary. Parts use `bigint` autoincrement PKs (or composite keys), are loaded through the root, and have no actions of their own. Their invariants are enforced by the root's actions.

Worked examples:

- `Employee` — aggregate root. Own UUIDv7 identity, independent lifecycle, hits its own builder, has `RegisterEmployeeAction` and friends. ✓
- A hypothetical `EmployeeLoginAttempt` — aggregate **part** of `Employee`. Rows are only ever created by a login-handling action on `Employee`; no caller queries `EmployeeLoginAttempt` except via `$employee->loginAttempts`; no actions target it. Keeps `bigint` PK.
- A hypothetical `DocumentRevision` inside a `Document` aggregate — aggregate part. Revisions are meaningless outside their document; the document's actions create and mutate them.

The criterion matters because the [IDs rule](../conventions.md#ids), the [UUIDv7 PK requirement](#ids), and the [policy-per-aggregate-root rule](../authorization.md) all apply only to aggregate roots. An aggregate part does not need its own policy, its own exception subclass, or its own UUIDv7 identity.

## On anaemic models

This project deliberately uses an **anaemic domain model**: behaviour that mutates state lives in actions, not on the aggregate. The model exposes read-only state helpers (`hasConfirmedTwoFactor()`, `isDisabled()`) but does not expose state-changing behaviour methods (no `$employee->disableTwoFactor()`).

This is a documented compromise; see [Philosophy § what we deliberately do not adopt](../philosophy.md#what-we-deliberately-do-not-adopt) for the rationale. The trade is fewer Eloquent-vs-DDD friction points at the cost of behaviour being one indirection away from the data it touches.

### Promoting attribute writes to behaviour methods

There are two triggers for putting a behaviour method on the aggregate. Pick the one that matches the aggregate's nature.

#### Trigger A — frequency (CRUD-shaped aggregates)

Default rule. When the same attribute-write pattern shows up in **three or more actions** on the same aggregate, promote it to a behaviour method on the model. The action then becomes:

```php
$employee->disableTwoFactor();
$employee->saveOrFail();
```

where `disableTwoFactor()` is the behaviour method that does the attribute work. The action **still saves**; the behaviour method only mutates in-memory state. This keeps the rule "models do not write to themselves" intact while pulling the *what to mutate* knowledge onto the aggregate where it belongs.

Do not promote on the first duplicate; wait for the third occurrence to confirm the pattern is real.

#### Trigger B — risk (state-machine and high-impact aggregates)

For aggregates whose state changes follow a fixed list of allowed transitions or carry high operational risk, **define the transition method on the model from the first occurrence**. Do not wait for duplication. The cost of one wrong transition (a published document getting edited without review, a completed job getting restarted, an account being disabled incorrectly) can be high enough that it dominates the small upfront cost of writing the method.

An aggregate qualifies for Trigger B if **any** of these hold:

- It has a `status` / `state` column whose value names a position in a fixed lifecycle (`draft`, `pending_review`, `approved`, `published`, `archived`, …).
- It controls access, publication, irreversible work, external side effects, or compliance-relevant state.
- A wrong transition cannot be detected by tests alone — it only surfaces in support tickets, audit review, incident response, or inconsistent downstream data.

Concrete examples (illustrative):

```php
// app/Domains/Documents/Models/Document.php
final class Document extends BaseModel
{
    public function submitForReview(Employee $author): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw DocumentNotSubmittable::because($this, "status is {$this->status->value}");
        }

        $this->status = DocumentStatus::PendingReview;
        $this->submitted_by = $author->getKey();
        $this->submitted_at = CarbonImmutable::now();
    }

    public function approve(Employee $reviewer): void
    {
        if ($this->status !== DocumentStatus::PendingReview) {
            throw DocumentNotApprovable::because($this, "status is {$this->status->value}");
        }
        if ($reviewer->is($this->author)) {
            throw DocumentReviewerCannotBeAuthor::for($this, $reviewer);
        }

        $this->status = DocumentStatus::Approved;
        $this->approved_by = $reviewer->getKey();
        $this->approved_at = CarbonImmutable::now();
    }

    public function publish(): void
    {
        if ($this->status !== DocumentStatus::Approved) {
            throw DocumentNotPublishable::because($this, "status is {$this->status->value}");
        }

        $this->status = DocumentStatus::Published;
        $this->published_at = CarbonImmutable::now();
    }
}
```

The Application action then becomes a thin orchestrator:

```php
// app/Application/PublishDocument/PublishDocumentAction.php
final class PublishDocumentAction
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function execute(Document $document): void
    {
        $this->db->transaction(function () use ($document): void {
            $document->publish();
            $document->saveOrFail();

            event(new DocumentPublished(
                documentId: $document->getKey(),
                at: CarbonImmutable::now(),
            ));
        });
    }
}
```

Three properties this gets you that the action-only version cannot:

1. **The model refuses illegal moves.** Anyone who calls `$document->publish()` on an unapproved document gets an exception, even from tinker, a future scheduled job, or a teammate's controller. Bypassing requires writing raw `$document->status = ...; $document->save();` — which is a grep-able anti-pattern, not an accident.
2. **Invariants live in one place.** The "only approved documents can publish" rule and the "authors cannot approve their own drafts" rule — there is one file to read, one file to fix, one file to test.
3. **Action stays a coordinator.** Transaction, save, event. No business rules duplicated across `PublishDocumentAction`, `ScheduleDocumentPublicationAction`, `AutoPublishApprovedDocumentAction`. They all funnel through `$document->publish()`.

Naming for transition methods follows the lifecycle verb: `submitForReview`, `approve`, `publish`, `archive`, `fail`, `cancel`. Avoid CRUD verbs (`update`, `change`) — the verb should name the business event.

Trigger B does **not** mean "put everything on the model". Helper queries, formatters, cross-aggregate orchestration, persistence, side effects (events, jobs) all stay where they were — in builders, value objects, and Application actions. Only the **state-mutating method that enforces the transition rule** moves to the aggregate.

## BaseModel

`BaseModel` is the abstract base for non-authenticatable models. It is generic over its custom builder class via a single `@template` parameter and sets `BaseBuilder` as the default. Concrete models opt into a specific builder via `#[UseEloquentBuilder(<ModelName>Builder::class)]` plus a `@extends BaseModel<<ModelName>Builder>` annotation.

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Eloquent\Models;

use App\Infrastructure\Eloquent\Builders\BaseBuilder;
use App\Infrastructure\Eloquent\Concerns\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TBuilder of BaseBuilder = BaseBuilder
 *
 * @method static TBuilder query()
 * @method TBuilder newQuery()
 */
abstract class BaseModel extends Model
{
    use HasFactory;

    /** @var class-string<BaseBuilder> */
    protected static string $builder = BaseBuilder::class;
}
```

Three things make the pattern work:

- **Runtime wiring.** Laravel 13's default `newEloquentBuilder()` reads `#[UseEloquentBuilder]` on the concrete model and falls back to `static::$builder` otherwise. Setting `static::$builder = BaseBuilder::class` here makes `BaseBuilder` the default for every subclass that does not declare its own builder attribute. **Do not** override `newEloquentBuilder()` in this base — Laravel 13 already does the right thing.
- **Type propagation.** The class-level `@template TBuilder` plus `@method static TBuilder query()` / `@method TBuilder newQuery()` lets every subclass declare "my builder is X" via a single `@extends BaseModel<X>` annotation. The default `= BaseBuilder` covers models that do not need a custom builder.
- **Factory resolution.** The imported `HasFactory` is the **project's trait** at `App\Infrastructure\Eloquent\Concerns\HasFactory`, **not** Laravel's. See [Factories § auto-resolution](factories.md#auto-resolution) for the mechanism.

## BaseAuthenticatable

Authenticatable models cannot extend `BaseModel` because they need to extend Laravel's `Authenticatable`. They use the same default-builder mechanism via their own base class.

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Eloquent\Models;

use App\Infrastructure\Eloquent\Builders\BaseBuilder;
use App\Infrastructure\Eloquent\Concerns\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @template TBuilder of BaseBuilder = BaseBuilder
 *
 * @method static TBuilder query()
 * @method TBuilder newQuery()
 */
abstract class BaseAuthenticatable extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /** @var class-string<BaseBuilder> */
    protected static string $builder = BaseBuilder::class;
}
```

Traits that apply to **every** authenticatable model in the project may live here. The imported `HasFactory` is the project's trait — see [Factories § auto-resolution](factories.md#auto-resolution). Anything that only applies to *some* authenticatables belongs on the concrete model.

Sensitive attributes (`password`, `remember_token`, 2FA fields, etc.) should be hidden. Declare `#[Hidden(...)]` on the base class only if every authenticatable shares the same hidden set; otherwise declare it per concrete model.

## Database-backed magic properties

Every concrete Eloquent model must declare its database-backed magic properties in the model's class PHPDoc using `@property`. The source model file is the primary static-analysis contract; do **not** rely on generated IDE helper files as the source of truth for PHPStan / Larastan.

Declare one `@property` for each column the model owns:

- Primary keys, timestamps, nullable columns, hidden columns, and framework columns like `remember_token` all count.
- Use the post-cast read type for casted columns, e.g. `Carbon|null` for nullable `datetime` casts.
- Use the raw persisted type for encrypted strings / JSON blobs unless the model has an Eloquent cast that changes the public attribute type.
- Import classes used in PHPDoc, such as `Illuminate\Support\Carbon`, instead of fully qualifying them in every annotation.

Do **not** add getters, setters, or behaviour methods only to satisfy PHPStan. Add methods only when the behaviour-promotion rule in [On anaemic models](#on-anaemic-models) justifies them.

```php
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @extends BaseModel<EmployeeBuilder>
 */
final class Employee extends BaseModel
{
    // ...
}
```

## Wiring a model to a custom builder class

A model with a custom builder class:

1. Adds `#[UseEloquentBuilder(<ModelName>Builder::class)]` so the runtime returns the right builder instance.
2. Declares `@extends <BaseClass><<ModelName>Builder>` so PHPStan and the IDE narrow the return type of `query()` / `newQuery()`.

```php
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

/**
 * @extends BaseModel<<ModelName>Builder>
 */
#[UseEloquentBuilder(<ModelName>Builder::class)]
final class <ModelName> extends BaseModel
{
}
```

For authenticatable models, replace `BaseModel` with `BaseAuthenticatable`. No `@method` PHPDoc is needed on the model itself — the base class already declares `query()` / `newQuery()` parameterized over `TBuilder`, and the `@extends` annotation supplies the concrete type. The `protected static string $builder` property is **not** redeclared on the concrete model; `#[UseEloquentBuilder]` supersedes it at runtime.

The builder side of this contract is defined in [Builders § custom builder skeleton](builders.md#custom-builder-skeleton).

### Example: model wired to a custom builder

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Models;

use App\Domains\Employees\Builders\EmployeeBuilder;
use App\Infrastructure\Eloquent\Models\BaseAuthenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $last_login_at
 * @property Carbon|null $disabled_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @extends BaseAuthenticatable<EmployeeBuilder>
 */
#[UseEloquentBuilder(EmployeeBuilder::class)]
#[Fillable([
    'name',
    'email',
    'password',
    'email_verified_at',
    'last_login_at',
    'disabled_at',
])]
#[Hidden(['password', 'remember_token'])]
final class Employee extends BaseAuthenticatable
{
    protected $table = 'employees';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
```

### Example: model without a custom builder

```php
<?php

declare(strict_types=1);

namespace App\Domains\<DomainName>\Models;

use App\Infrastructure\Eloquent\Models\BaseModel;

final class <SimpleModel> extends BaseModel
{
    protected $table = '<table_name>';
}
```

This model uses `BaseBuilder` through `BaseModel`; no `#[UseEloquentBuilder]` attribute is needed.

## IDs

Aggregate roots use **UUIDv7** primary keys. The full project-wide rule and rationale lives in [Conventions § IDs](../conventions.md#ids); this section owns the **migration / model mechanics**.

### Migration

```php
Schema::create('employees', function (Blueprint $table): void {
    $table->uuid('id')->primary();

    $table->string('name');
    $table->string('email')->unique();
    // …
    $table->timestamps();
});
```

`uuid` resolves to native `uuid` on Postgres and `char(36)` on SQLite (in-memory test DB) and MySQL. Cross-DB by default; no extra cast needed.

### Model

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

final class Employee extends BaseAuthenticatable
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
```

- `HasUuids` is Laravel's first-party trait. It hooks model `creating` to assign `newUniqueId()` to the primary key column, marks the key as non-incrementing, and tells Eloquent the key type is `string` so route-model binding works.
- The default `HasUuids::newUniqueId()` returns `Str::orderedUuid()` (a UUIDv4 with a custom timestamp prefix). **We override it to return `Str::uuid7()`** so we get a real RFC-9562 UUIDv7 with proper time-ordering and tooling support.
- `final` and the rest of the model wiring stay the same; nothing else needs to know the PK changed.

### Foreign keys

When a table references an aggregate-root UUIDv7 PK, declare the foreign key as `uuid`, not `unsignedBigInteger`:

```php
$table->uuid('employee_id');
$table->foreign('employee_id')->references('id')->on('employees');
```

### Pivot / morph / framework tables

Tables that reference an aggregate root must use the matching `uuid` column type for that key. This includes:

- Spatie permission morph tables (`model_has_permissions.model_id`, `model_has_roles.model_id`) when the morph target is an aggregate root.
- Custom pivot tables linking aggregate roots.

Tables internal to the framework (`jobs`, `failed_jobs`, `sessions`, `cache`) keep their default `bigint` columns — they aren't morph targets for our aggregates.

### Factories

`Employee::factory()->create()` works unchanged; `HasUuids` runs in the model `creating` hook regardless of how the model is instantiated. No factory wiring is needed.

If a test ever needs to assert against a specific UUID, generate it ahead of time:

```php
$id = (string) Str::uuid7();
$employee = Employee::factory()->create(['id' => $id]);
```

### Route-model binding

Route declarations and controller signatures stay the same. Laravel's binder uses the model's `getRouteKeyName()` (default `id`) and `HasUuids` makes that resolution string-aware. No changes to `routes/*.php`.

```php
Route::get('employees/{employee}', [EmployeeController::class, 'show']);
//                       ^^^^^^^^   resolves to Employee::find($uuid)
```

## Attributes

Use Eloquent's PHP-attribute API for mass-assignment and serialization rules. Do **not** use the `protected $fillable` / `protected $hidden` array properties on new models.

- `#[Fillable([...])]` — explicit allowlist of mass-assignable columns. Preferred over `#[Guarded]`.
- `#[Hidden([...])]` / `#[Visible([...])]` — serialization rules.
- `#[UseEloquentBuilder(<X>Builder::class)]` — builder selection. Replaces the older pattern of redeclaring `protected static string $builder` on the concrete model.
- `#[ScopedBy(<X>Scope::class)]` — global scope. Used for default-safe queries (see § [Default-safe queries via global scope](#default-safe-queries-via-global-scope)).

These attributes are inherited by subclasses through Eloquent's class-attribute resolver; declare them on a base class only when every subclass should share the same value.

## Default-safe queries via global scope

> **When this pattern is allowed**
>
> Only on aggregates whose primary purpose is **identity / access control** — e.g. `Employee`, future `WorkspaceUser`, `ApiKey`. The pattern's reason for existing is to make Laravel's auth guard (`$request->user()`) automatically forget a subject who got disabled mid-session.
>
> **When this pattern is forbidden**
>
> - **Reporting and operational aggregates** — anything that participates in dashboards, audit review, capacity planning, external sync, or historical analysis. Examples: `Project`, `Document`, `ImportRun`, `WebhookDelivery`, `AuditEvent`, `ReportSnapshot`.
> - **Anything that gets summed, counted, or grouped in a report.** Hidden row-filtering at the query layer means a `->count()` or grouped query silently returns the wrong number. There is no error, just a wrong total. For reporting, that defect class is hard to detect.
> - **Audit / log-style tables** (`AuditEvent`, `WebhookDelivery`, `OutboxMessage`). Audit data is read precisely *because* something went wrong; hiding rows defeats the point.
>
> On those models, state filters (e.g. `->notSuspended()`, `->onlyActive()`) live as **explicit** builder methods. The caller chooses; the model never decides for them.
>
> **Why the asymmetry?** On an identity model, "forgetting to filter disabled rows" produces a security bug that's loud (the disabled user can still log in — someone notices fast). On a reporting model, "forgetting to include archived projects" produces a wrong number on a quarterly report that nobody catches until review. Loud failure mode → safe default helps. Silent failure mode → safe default hurts.

For an allowed model, the pattern is:

```php
// app/Domains/Employees/Scopes/ExcludeDisabledScope.php
final class ExcludeDisabledScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('disabled_at'));
    }
}
```

Attach with `#[ScopedBy]`:

```php
#[UseEloquentBuilder(EmployeeBuilder::class)]
#[ScopedBy(ExcludeDisabledScope::class)]
final class Employee extends BaseAuthenticatable { /* … */ }
```

Provide opt-out helpers on the builder, mirroring `withTrashed()` / `onlyTrashed()`:

```php
final class EmployeeBuilder extends BaseBuilder
{
    public function withDisabled(): static
    {
        return $this->withoutGlobalScope(ExcludeDisabledScope::class);
    }

    public function onlyDisabled(): static
    {
        return $this->withoutGlobalScope(ExcludeDisabledScope::class)
            ->whereNotNull($this->getModel()->qualifyColumn('disabled_at'));
    }
}
```

**Forbidden:** an `active()` builder method that filters out disabled rows. That makes "default" the unsafe behavior and forces every query to remember the opt-in. Invert it: scope at the model, opt out explicitly.

**Side benefit:** Laravel's auth guard resolution (`$request->user()`) goes through `Model::query()` and therefore picks up the global scope. A logged-in employee who gets disabled mid-session falls out of `$request->user()` automatically on the next request — no middleware change needed.

## What belongs on a model

- table (`$table`)
- casts (`casts()`)
- relationships
- mass-assignment via `#[Fillable]` / `#[Guarded]`
- serialization rules via `#[Hidden]` / `#[Visible]`
- builder selection via `#[UseEloquentBuilder]`
- simple **read-only** state helpers (`isDisabled()`, `hasConfirmedTwoFactor()`)
- attribute accessors / mutators (`Attribute::get(...)`)

## What does not belong on a model

A model that mutates its own persistent state is a **hidden action**. Extract it.

- Persistence calls inside model methods: `$this->save()`, `$this->update([...])`, `$this->forceFill([...])->save()`.
- Construction of third-party services (`new Google2FA(...)`, `new AlgoliaSearchClient(...)`) — see [Services](../services.md).
- Validation logic — that's a [request data](../http/request-data.md) concern.
- HTTP-aware logic (route URLs, session reads, redirects).
- Project-authored scope traits — prefer a builder class. See [Builders § what does not belong in a builder](builders.md#what-does-not-belong-in-a-builder).

Setting attributes via property assignment (`$this->foo = $bar`) is **fine** — when the *caller* (an action) will save.

### Traits on models

A trait on a model is allowed only if it contains **read-only state helpers** (no `save()`, no `update()`, no `forceFill()->save()`). The moment it writes, extract those methods into actions.

A trait that conflates reads and writes is the most common form of "hidden action" in a Laravel codebase. Watch for it.

## What does not belong in a base class

Base classes (`BaseModel`, `BaseAuthenticatable`) stay small and boring. They may contain shared technical behavior only:

- common Eloquent configuration
- common casts or shared traits
- UUID / ULID setup
- audit / pruning helpers
- shared builder wiring

They must not contain:

- business rules of any kind
- state transitions
- permission checks
- domain-specific validation

Those belong in domain models, builder classes, actions, or policies.

## Naming

Use:

```text
BaseModel
BaseAuthenticatable
BaseBuilder
<ModelName>Builder
```

Avoid:

```text
AbstractModel
CoreModel
MainModel
GlobalModel
<ModelName>Query
<ModelName>Scope
```

`<ModelName>Repository` is **reserved**, not banned. Do not name something a repository unless it actually is one (interface in `Domains/<X>/Contracts/` or `Application/<UseCase>/Contracts/`, implementation in `Infrastructure/Eloquent/Repositories/<X>/`, returns aggregates not models, hides the query mechanism). The default read mechanism in this project is the [builder](builders.md); repositories are opt-in. See [Repositories](repositories.md).

## Static analysis and IDE notes

The base classes are generic over their builder class via `@template TBuilder of BaseBuilder`. Concrete models opt into a specific builder with `@extends` plus `#[UseEloquentBuilder]`, and declare database-backed magic properties with `@property`:

```php
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @extends BaseModel<<ModelName>Builder>
 */
#[UseEloquentBuilder(<ModelName>Builder::class)]
final class <ModelName> extends BaseModel
{
}
```

For authenticatable models replace `BaseModel` with `BaseAuthenticatable`. Builder classes are also generic, but over the **model** they query — that side of the contract is defined in [Builders § custom builder skeleton](builders.md#custom-builder-skeleton).

Why this is the rule:

- A single `@extends` line on the model is enough — no per-method `@method static <X>Builder query()` boilerplate to maintain.
- `@property` lines make Eloquent's database-backed magic properties visible to PHPStan / Larastan without adding fake PHP properties or behaviour-only getters / setters.
- Adding a new helper to a builder class becomes immediately visible on every model that uses it; nothing else needs updating.
- Generated IDE helper files are optional editor support only. They are not the primary static-analysis source. If PhpStorm autocomplete misbehaves on a particular method, generated helpers may help the IDE, but the model's own PHPDoc must stay accurate.

## See also

- [Builders](builders.md) — the read-side surface a model wires itself to.
- [Factories](factories.md) — how a model is built for tests.
- [Actions](../actions.md) — where the writes that don't belong on a model go.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — what a model is allowed to do.
