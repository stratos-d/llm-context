# Adding a context

> **Owns**
>
> - The recipe for adding a new bounded context: folders to create, minimum files, wiring
> - The "context is done" checklist
> - One worked end-to-end example (fictional `Notes` context) showing the expected shape
>
> **Forbids**
>
> - Re-stating per-layer skeletons — every step links to the topic file that owns the rule
> - Showing a context that overlaps `Employees` — the example is intentionally fictional and minimal
>
> **See also**: [Architecture](architecture.md), [Conventions](conventions.md), [Actions](actions.md), [Authorization](authorization.md), [Exceptions](exceptions.md), [Cross-context communication](cross-context.md), [Models](data/models.md), [Testing](testing.md)

This file is for the moment when you need to add a *new bounded context* — not a new use case inside an existing one. Use the [threshold question](#when-to-add-a-new-context) below to decide which you are doing.

## When to add a new context

A new context is justified when **all** of these hold:

- The new concept has its own ubiquitous language: domain experts use words for it that don't appear in the existing contexts (or use the same word with a *different* meaning).
- It owns its own invariants — a rule that's true here but irrelevant or different elsewhere.
- It has a plausible independent lifecycle: rows in its tables can be created, mutated, and deleted on a different cadence than rows in any existing context.
- The cross-context coupling between it and the existing contexts is something you can express through one of the three sanctioned mechanisms (events, published actions, published read models — see [Cross-context communication](cross-context.md)). If the only way to make it work is foreign-keying directly into another context's tables, it isn't a separate context yet.

If only the first two hold, you probably have a new **aggregate** inside an existing context. If none of them hold, you have a new **use case** — a new Application action under `Application/<UseCase>/`, no new context folder.

## Recipe

The steps below are the canonical order. Each step links to the topic file that owns the rule.

### 1. Decide the context name

Pluralised, PascalCase, named in the project's vocabulary (`Notes`, `Documents`, `Notifications`, not `NoteService` or `Notepad`). The folder under `Domains/` will be `Domains/<ContextName>/`. See [Conventions § naming](conventions.md#naming).

### 2. Create the folder skeleton

Only create subfolders you have a file to put in. The full available shape is documented in [Architecture § folder layout](architecture.md#folder-layout); a minimal first commit usually has:

```text
app/Domains/<ContextName>/
├── Actions/
│   └── <Verb><Noun>Action.php          ← one or more Domain actions
├── Builders/
│   └── <ModelName>Builder.php
├── Database/
│   ├── Factories/
│   │   └── <ModelName>Factory.php
│   └── Migrations/
│       └── <timestamp>_create_<table>_table.php
├── Models/
│   └── <ModelName>.php                  ← the aggregate root
├── Policies/
│   └── <ModelName>Policy.php            ← only when the aggregate has authorization rules
└── Exceptions/                          ← only when this context throws domain exceptions
    ├── <ContextName>Exception.php       ← per-context abstract base
    └── <Name>.php                       ← concrete failures
```

Plus the matching Application slice if the use case is cross-context or has multi-step orchestration:

```text
app/Application/<UseCase>/
├── <Verb><Noun>Action.php               ← Application action
└── Jobs/                                ← only if any step is queued
    └── <Verb><Noun>Job.php
```

### 3. Migration

Aggregate root tables use UUIDv7 primary keys; FK columns referencing them are `uuid`. See [Conventions § IDs](conventions.md#ids) and [Models § IDs](data/models.md#ids).

```php
Schema::create('<table_name>', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    // … domain columns …
    $table->timestamps();
});
```

If a soft-disable column is appropriate (`disabled_at`, `archived_at`), add it now and plan a global scope at step 5. See [Models § default-safe queries via global scope](data/models.md#default-safe-queries-via-global-scope).

### 4. Model

The aggregate root extends `BaseModel` (or `BaseAuthenticatable` if it's an auth subject) and uses `HasUuids` with a `Str::uuid7()` override. See [Models](data/models.md).

```php
final class <ModelName> extends BaseModel
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected function casts(): array
    {
        return [/* … */];
    }
}
```

Mass-assignment and serialization rules go on the `#[Fillable(...)]` and `#[Hidden(...)]` attributes. See [Models § attributes](data/models.md#attributes).

### 5. Builder

One builder per aggregate root, even if it starts empty. The builder is where reusable read-side filters and orderings will accumulate. See [Builders](data/builders.md).

```php
final class <ModelName>Builder extends BaseBuilder
{
    // reusable filters / orderings as they're justified
}
```

Wire the model to the builder with `#[UseEloquentBuilder(<ModelName>Builder::class)]` and a `@extends BaseModel<<ModelName>Builder>` PHPDoc. Declare every database-backed magic column with `@property` in the model PHPDoc. See [Models § database-backed magic properties](data/models.md#database-backed-magic-properties) and [Models § wiring a model to a custom builder class](data/models.md#wiring-a-model-to-a-custom-builder-class).

### 6. Factory

Required for tests; the project's `HasFactory` trait auto-resolves the factory namespace from the model namespace, so `<ContextName>/Database/Factories/<ModelName>Factory.php` is found automatically. See [Factories § auto-resolution](data/factories.md#auto-resolution).

### 7. Actions

Place each action by the placement test ([Actions § the placement test](actions.md#the-placement-test)):

- *Aggregate-command* writes that change one aggregate's invariants → **Domain action** in `Domains/<ContextName>/Actions/`.
- *Use-case* orchestration that composes one or more Domain actions, opens transactions, calls services, dispatches jobs → **Application action** in `Application/<UseCase>/`.

Sole transaction root rule applies: only Application actions open `DB::transaction()`. See [Transactions](transactions.md).

If the action throws on precondition failure, define a domain exception (step 8) before throwing; if the action protects a per-aggregate authorization rule that depends on the actor, define a policy (step 9). Authorization that depends only on aggregate state stays as plain conditional code inside the Application action.

### 8. Domain exceptions (only when needed)

If any action in this context throws on invariant or precondition failure, create the per-context exception base + concrete failures. See [Exceptions](exceptions.md).

```text
Domains/<ContextName>/Exceptions/
├── <ContextName>Exception.php           ← abstract, extends App\Domains\DomainException
└── <SpecificFailure>.php                ← extends <ContextName>Exception
```

The central handler in `bootstrap/app.php` will map the exception to HTTP. Add the new failure class to the mapping table if its status code or shape isn't already covered.

### 9. Policy (only when needed)

If the aggregate has resource-level authorization rules (caller-acting-on-this-instance), add a policy. See [Authorization](authorization.md).

```php
// app/Domains/<ContextName>/Policies/<ModelName>Policy.php
final class <ModelName>Policy
{
    public function update(<ActorModel> $actor, <ModelName> $resource): bool { /* … */ }
}
```

Laravel auto-discovers it from the aggregate model's namespace; no `AuthServiceProvider::policies` registration is needed.

The policy is invoked from the **controller** via `Gate::authorize(...)`, never from inside an action.

### 10. Routes, request data, controller

Choose the entry point: most internal-write contexts will live under `Interfaces/AdminWeb/` (Inertia). External-API write contexts will live under a future `Interfaces/PartnerApi/`. Don't create a new entry point unless the *delivery shape* (transport + protocol) is genuinely different.

Inside the chosen entry point:

- **Route** in `Interfaces/<EntryPoint>/Routes/<group>.php`. See [Routes](http/routes.md).
- **Request data** in `Interfaces/<EntryPoint>/Requests/<UseCase>/<VerbNoun>Data.php` for any state-changing endpoint. See [Request data](http/request-data.md).
- **Controller** in `Interfaces/<EntryPoint>/Controllers/<Group>/<Name>Controller.php`. Calls one Application action and returns one response. See [Controllers](http/controllers.md).

### 11. Cross-context wiring (only when needed)

If this context emits domain events that another context reacts to, register the listener in the **reacting** context's `DomainServiceProvider`, never in the emitting one. See [Domain events § who listens](domain-events.md#who-listens) and [Cross-context communication § events as the default mechanism](cross-context.md).

If this context calls another context's Application action, the target action must be marked `@published` and accept primitives / DTOs / value objects only. See [Cross-context communication § published actions](cross-context.md#published-actions).

### 12. Tests

Two categories minimum:

- **Feature test** for the controller(s): asserts the HTTP shape, that the action ran, and that authorization works. Uses `RefreshDatabase` and the real Application action.
- **Unit test** for each Application action and each non-trivial Domain action: asserts the use case's normal vocabulary outcomes and the exception cases.

See [Testing § test discipline](testing.md#test-discipline) and [Testing § substituting collaborators in tests](testing.md#substituting-collaborators-in-tests).

## "Context is done" checklist

Before merging the first PR for a new context, confirm every box:

- [ ] Folder name matches the context's ubiquitous language (pluralised, PascalCase).
- [ ] Aggregate root migration uses `uuid('id')->primary()`.
- [ ] Aggregate root model uses `HasUuids` with `newUniqueId()` returning `Str::uuid7()`.
- [ ] FK columns into this context use `uuid` (not `unsignedBigInteger`).
- [ ] Builder exists for the aggregate root, even if empty.
- [ ] Factory exists and `<ModelName>::factory()->create()` succeeds.
- [ ] Every state-changing operation goes through an action — no `->save()` from a controller, no `->update()` from a model method.
- [ ] Application action is the **sole** transaction root for any multi-row write.
- [ ] No facade calls (`Auth::`, `Session::`, `Mail::`, `Route::` outside the routes file, `Request::` outside the controller) inside `Domains/<ContextName>/` or `Application/<UseCase>/`.
- [ ] No `now()` / `Carbon::now()` / `new DateTime()` — only `CarbonImmutable::now()`. See [Conventions § Time](conventions.md#time).
- [ ] Domain exceptions exist if any action throws on precondition failure; the central handler maps each one.
- [ ] `Gate::authorize(...)` lives in the controller, not in actions; policies live in `Domains/<ContextName>/Policies/`.
- [ ] Cross-context references (if any) go through events, published actions, or published read models — no direct model imports across context folders.
- [ ] Feature test covers the happy-path HTTP flow.
- [ ] Unit test covers each non-trivial action's normal-vocabulary outcomes and exception cases.
- [ ] No `try { ... } catch (DomainException $e) { ... }` in controllers — let the central handler map.
- [ ] If anything is queued, it lives at `Application/<UseCase>/Jobs/` and uses `->afterCommit()` when dispatched from inside a transaction.
- [ ] No empty service provider; the context's `DomainServiceProvider` exists only if there are bindings or listener registrations to make.

## Worked example: `Notes`

Illustrative only — no code shipped. The shape below is the minimum a new write-side context produces in its first PR.

### Tree

```text
app/Domains/Notes/
├── Actions/
│   └── ArchiveNoteAction.php
├── Builders/
│   └── NoteBuilder.php
├── Database/
│   ├── Factories/
│   │   └── NoteFactory.php
│   └── Migrations/
│       └── 2026_05_01_000000_create_notes_table.php
├── Exceptions/
│   ├── NotesException.php
│   └── NoteAlreadyArchived.php
├── Models/
│   └── Note.php
└── Policies/
    └── NotePolicy.php

app/Application/Notes/
├── ArchiveNoteAction.php                 ← Application action (transaction root + policy not needed if controller authorizes)
└── WriteNoteAction.php

app/Interfaces/AdminWeb/
├── Controllers/Notes/
│   ├── NotesController.php
│   └── ArchiveNoteController.php
├── Requests/Notes/
│   ├── WriteNoteData.php
│   └── ArchiveNoteData.php
└── Routes/web.php                         ← + 3 route lines
```

### Migration

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('author_id');
            $table->string('title');
            $table->text('body');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
            $table->foreign('author_id')->references('id')->on('employees');
        });
    }
};
```

### Model

```php
namespace App\Domains\Notes\Models;

use App\Domains\Notes\Builders\NoteBuilder;
use App\Infrastructure\Eloquent\Models\BaseModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $author_id
 * @property string $title
 * @property string $body
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @extends BaseModel<NoteBuilder>
 */
#[UseEloquentBuilder(NoteBuilder::class)]
#[Fillable(['author_id', 'title', 'body', 'archived_at'])]
final class Note extends BaseModel
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
```

### Builder

```php
namespace App\Domains\Notes\Builders;

use App\Infrastructure\Eloquent\Builders\BaseBuilder;

final class NoteBuilder extends BaseBuilder
{
    public function authoredBy(string $authorId): self
    {
        return $this->where('author_id', $authorId);
    }

    public function notArchived(): self
    {
        return $this->whereNull('archived_at');
    }
}
```

### Domain exceptions

```php
namespace App\Domains\Notes\Exceptions;

use App\Domains\DomainException;

abstract class NotesException extends DomainException {}
```

```php
namespace App\Domains\Notes\Exceptions;

final class NoteAlreadyArchived extends NotesException
{
    public function __construct(public readonly string $noteId)
    {
        parent::__construct("Note {$noteId} is already archived.");
    }
}
```

### Domain action

```php
namespace App\Domains\Notes\Actions;

use App\Domains\Notes\Exceptions\NoteAlreadyArchived;
use App\Domains\Notes\Models\Note;
use Carbon\CarbonImmutable;

final class ArchiveNoteAction
{
    public function execute(Note $note): void
    {
        if ($note->isArchived()) {
            throw new NoteAlreadyArchived($note->getKey());
        }

        $note->archived_at = CarbonImmutable::now();
        $note->saveOrFail();
    }
}
```

### Application action

```php
namespace App\Application\Notes;

use App\Domains\Notes\Actions\ArchiveNoteAction as ArchiveNoteDomainAction;
use App\Domains\Notes\Models\Note;

final class ArchiveNoteAction
{
    public function __construct(
        private ArchiveNoteDomainAction $archiveNoteDomainAction,
    ) {}

    public function execute(Note $note): void
    {
        // Single-aggregate write: no transaction wrap needed (one save() is atomic).
        $this->archiveNoteDomainAction->execute($note);
    }
}
```

### Policy

```php
namespace App\Domains\Notes\Policies;

use App\Domains\Employees\Models\Employee;
use App\Domains\Notes\Models\Note;

final class NotePolicy
{
    public function archive(Employee $actor, Note $note): bool
    {
        return $note->author_id === $actor->getKey();
    }
}
```

### Controller

```php
namespace App\Interfaces\AdminWeb\Controllers\Notes;

use App\Application\Notes\ArchiveNoteAction;
use App\Domains\Notes\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class ArchiveNoteController
{
    public function __construct(
        private ArchiveNoteAction $archiveNoteAction,
    ) {}

    public function __invoke(Note $note): RedirectResponse
    {
        Gate::authorize('archive', $note);

        $this->archiveNoteAction->execute($note);

        return redirect()->route('notes.index');
    }
}
```

### Route

```php
Route::post('notes/{note}/archive', ArchiveNoteController::class)
    ->name('notes.archive');
```

### Feature test

```php
test('an author can archive their own note', function (): void {
    $author = Employee::factory()->create();
    $note = Note::factory()->for($author, 'author')->create();

    actingAs($author)
        ->post(route('notes.archive', $note))
        ->assertRedirect(route('notes.index'));

    expect($note->refresh()->isArchived())->toBeTrue();
});

test('archiving an already-archived note throws NoteAlreadyArchived', function (): void {
    $note = Note::factory()->archived()->create();

    expect(fn () => app(ArchiveNoteAction::class)->execute($note))
        ->toThrow(NoteAlreadyArchived::class);
});
```

### What this example shows

- UUIDv7 PK on the aggregate (`uuid('id')->primary()` + `HasUuids` + `Str::uuid7()`).
- FK to another context's aggregate (`author_id` references `employees.id`) using `uuid`.
- Domain action throws a domain exception; Application action is a thin orchestration layer.
- No `DB::transaction()` because the use case is a single-row write — see [Transactions § when not to wrap](transactions.md).
- `CarbonImmutable::now()`, never `now()`.
- Authorization at the controller (`Gate::authorize`), policy in the aggregate's context.
- Controller stays slim: bind, authorize, call action, redirect.
- No central exception-handler wiring shown — the abstract `NotesException` would be added to `bootstrap/app.php` only if its mapping differs from `DomainException`'s default; concrete failures don't need to be listed individually. See [Exceptions § central mapping](exceptions.md).

What it deliberately doesn't show, because the rule is *don't introduce until justified*:

- An interface for `ArchiveNoteAction` — only one implementation, trigger rule not met.
- A `NoteId` value object — would arrive once `string` is no longer descriptive enough or once `Str::isUuid()`-style checks duplicate across the codebase. See [Value objects § when to introduce one](data/value-objects.md#when-to-introduce-one).
- A `NotesServiceProvider` — would arrive only when the context has bindings or listener registrations.
- A `Note` domain event — would arrive only when another context needs to react to note state changes.
- A `Notes` published read model or published action — would arrive only when another context needs to read or invoke into Notes.

## See also

- [Architecture](architecture.md) — folder layout, layer responsibilities, request flow.
- [Conventions](conventions.md) — strict types, naming, time, IDs.
- [Actions](actions.md) — the placement test, signature rules, what an action can / cannot call.
- [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md) — the cross-cutting concerns referenced from steps 8–9 and 11.
- [Cross-context communication](cross-context.md) — the three sanctioned mechanisms a new context uses to talk to existing ones.
- [Models](data/models.md), [Builders](data/builders.md), [Factories](data/factories.md) — the data-side topic files.
- [Testing](testing.md) — the test-discipline rules step 12 references.
- [Anti-patterns](anti-patterns.md) — grep-friendly red flags the checklist is designed to prevent.
