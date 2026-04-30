# Repositories

> **Owns**
>
> - When to introduce a repository (the four triggers)
> - Where the port and the adapter live
> - Repository skeleton
> - Naming
> - The rule that the default read mechanism is the **builder**, not the repository
>
> **Forbids**
>
> - Pre-emptive repositories ("everything has a repository") — that is over-architecture
> - Repositories that are thin wrappers over a builder method — use the builder directly
> - Repositories returning Eloquent collections — return aggregates or DTOs
> - Repository methods named after the query mechanism (`->byId()`, `->byEmail()`) — name them after intent
>
> **See also**: [Builders](builders.md), [Models](models.md), [Ports and adapters](../ports-and-adapters.md), [Read models](../http/read-models.md), [Architecture](../architecture.md)

A repository is a class that **loads and saves aggregates**, hiding the query mechanism behind a port. It is opt-in in this project; the default read mechanism is the [builder](builders.md), and the default write mechanism is `$aggregate->saveOrFail()` inside an action.

## When to introduce one

Introduce a repository **only** when one of the following is true. Otherwise, use the builder.

1. **Reads need to return non-Eloquent aggregates.** Rare in this project; relevant when an aggregate is composed of multiple Eloquent models that must be hydrated together (e.g. a `LedgerTransaction` aggregate with its `Posting` children, where consumers should never see the children separately).
2. **The read shape is genuinely different from the table shape.** When loading "an Employee" requires joining four tables and producing a value-object-rich aggregate that bears little resemblance to a row.
3. **The write involves multiple aggregates that must be persisted as a unit.** When `$aggregate->saveOrFail()` from inside an action is not enough because the unit of work spans multiple roots and ordering matters.
4. **The persistence mechanism may change.** When there is a real prospect of swapping Eloquent for Doctrine, or for a different store entirely. Not a hypothetical "what if we change databases" — a real, planned migration.

If none of the four applies, **use the builder**. A class called `EmployeeRepository` whose only method is `findActiveByEmail($email)` is just renamed `EmployeeBuilder`-with-extra-steps.

## Where they live

Repositories are concrete classes by default. Most repositories have one production implementation (Eloquent), and an interface (port) is introduced only if a second implementation arrives — see [Ports and adapters § the trigger rule](../ports-and-adapters.md#the-trigger-rule).

- **Concrete repository** lives at `Infrastructure/Eloquent/Repositories/<X>/Eloquent<X>Repository.php`. Inject the concrete class directly into the consumer.
- **Interface (port), when introduced** lives where the caller lives:
  - `Domains/<X>/Contracts/<X>Repository.php` — when domain code consumes the repository.
  - `Application/<UseCase>/Contracts/<X>Repository.php` — when only Application actions consume it.

```text
app/Infrastructure/Eloquent/Repositories/Ledger/EloquentLedgerTransactionRepository.php
```

## Naming

- **Concrete repository:** `<Mechanism><Aggregate>Repository` — `EloquentLedgerTransactionRepository`. Strategy prefix earns its keep when a sibling is anticipated (e.g. `RedisIdempotencyKeyRepository` if you might also have an `EloquentIdempotencyKeyRepository`).
- **Interface (when introduced):** `<Aggregate>Repository`. Singular noun. `LedgerTransactionRepository`. No `Interface`/`Contract` suffix.
- **Methods:** named after **intent**, not mechanism.

| Good (intent) | Bad (mechanism) |
|---|---|
| `find(EmployeeId $id): ?Employee` | `findById(string $id): ?Employee` |
| `findActiveByEmail(Email $email): ?Employee` | `whereEmail(string $email): ?Employee` |
| `save(Employee $employee): void` | `update(Employee $employee): void` / `insert(...)` |
| `pendingReviewForReviewer(Employee $reviewer): iterable<Document>` | `whereStatusPendingAndReviewer(...)` |

Repositories speak the domain's vocabulary, not SQL.

## Skeleton — concrete repository

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Eloquent\Repositories\Ledger;

use App\Domains\Ledger\Models\LedgerTransaction;
use App\Domains\Ledger\ValueObjects\LedgerTransactionId;

final class EloquentLedgerTransactionRepository
{
    public function find(LedgerTransactionId $id): ?LedgerTransaction
    {
        return LedgerTransaction::query()
            ->with(['postings'])
            ->find($id->value);
    }

    public function save(LedgerTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $transaction->saveOrFail();
            foreach ($transaction->postings as $posting) {
                $posting->saveOrFail();
            }
        });
    }

    /** @return iterable<LedgerTransaction> */
    public function unreconciledOlderThan(\DateTimeImmutable $threshold): iterable
    {
        return LedgerTransaction::query()
            ->unreconciled()
            ->postedBefore($threshold)
            ->lazyById();
    }
}
```

Rules:

- `final`.
- Methods accept and return value objects and aggregates, not primitives or arrays.
- Iterables (`iterable<T>`) for collections; `?T` for "find one"; `void` for writes.
- Eager-loads everything the aggregate considers part of its boundary. The caller never lazy-loads from a returned aggregate.
- `save()` is the place where multi-table aggregate persistence happens. The Domain action calls `$repository->save($aggregate)`; it does not call `->saveOrFail()` on individual children.
- Composes builder methods (`->unreconciled()`, `->postedBefore()`) instead of inlining `where(...)` chains. The repository is the loader; the **builder still owns reusable read filters**.
- No paging concerns. Paging is a read-model concern; if you need it on a repository, you are probably building a read model — see [Read models](../http/read-models.md).
- No batch CRUD methods (`saveAll`, `deleteWhere`). Batch operations are use-case-specific; build them per Application action when actually needed.

## When NOT to introduce a repository

Symptoms that scream "use a builder, not a repository":

- The repository would have only a `find` and a `save`. That is just `Model::find()` and `$model->save()` with extra ceremony.
- The repository would wrap one or two builder methods. Use the builder directly; the call-site clutter is the same.
- The aggregate is a single Eloquent model with no children. Eloquent already does the load/save cleanly; a repository adds noise.
- The motivation is "to be testable." Eloquent-backed code is testable through factories and the database; you don't need a repository for that. You need a fake when there is genuinely an external dependency, and Eloquent is internal.

If you find yourself defending a repository on grounds of "maybe one day we'll switch databases" or "it's the DDD way," you do not have a use case yet. Wait until one of the four triggers is real.

## Repository vs read model

Repositories are for **writes and aggregate loads**. They return aggregates that you intend to mutate.

Read models are for **queries**. They return DTOs tuned for a screen. They do not need a repository.

If your "repository method" returns a paginated list of rows that the consumer will not mutate, it is a read model in disguise. See [Read models](../http/read-models.md) for the right home.

## See also

- [Builders](builders.md) — the default read mechanism this project uses instead of repositories.
- [Models](models.md) — aggregate roots, the things repositories load.
- [Ports and adapters](../ports-and-adapters.md) — the pattern repositories follow when introduced.
- [Read models](../http/read-models.md) — the right place for query-shaped data, not a repository.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — where ports, adapters, and builders sit relative to each other.
- [Glossary](../glossary.md) — definitions of *repository*, *aggregate*, *aggregate root*.
