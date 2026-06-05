# Transactions

> **Owns**
>
> - Transaction-root rule
> - When to wrap writes
> - What runs inside or after a transaction
> - Repository relationship to transaction boundaries
>
> **Forbids**
>
> - Transactions in controllers, models, aggregate methods, listeners, services, or jobs
> - Nested use-case transactions
>
> **See also**: [Actions](actions.md), [Models](data/models.md), [Domain events](domain-events.md), [Repositories](data/repositories.md)

A transaction is a commit boundary. The project pins that boundary to one place: the Application action that owns the use case.

## Rule

Only **Application actions** open use-case transactions.

Aggregates, controllers, jobs, listeners, policies, request data, concrete services, and repositories do not decide use-case transaction boundaries.

```php
final readonly class GrantOrganizationAccessAction
{
    public function __construct(private DatabaseManager $db) {}

    public function execute(GrantAccessInput $input): void
    {
        $events = $this->db->transaction(function () use ($input): array {
            $access = EmployeeOrganizationAccess::grant(
                employeeId: $input->employeeId,
                organizationId: $input->organizationId,
                role: $input->role,
            );

            $access->saveOrFail();

            return $access->releaseDomainEvents();
        });

        foreach ($events as $event) {
            event($event);
        }
    }
}
```

## When to wrap

Open a transaction when the use case writes more than one row, aggregate, or table that must commit together.

Do not wrap a single `saveOrFail()` when the single row write is already atomic and there is no related side effect that must be coordinated.

## Aggregates do not wrap

Aggregate methods mutate in-memory state and throw domain exceptions. They do not know whether their caller needs a transaction.

```php
$order->cancel($employeeId);    // no DB transaction here
$order->saveOrFail();           // Application persists
```

## Repositories do not own use-case transactions

Repositories may hide aggregate persistence details, especially when saving a root plus children. They should not generally open the use-case transaction.

Preferred flow:

```text
Application action opens transaction
Application action loads aggregate
Application action calls aggregate behavior
Repository/action persists aggregate
Transaction commits
Application action dispatches events after commit
```

Repository-level transactions are only acceptable for narrow persistence internals that are not the use-case boundary. If a repository method name sounds like a business use case, it probably belongs in an Application action.

## No nested transactions

One use case has one transaction root. If an Application action needs another operation inside the same transaction, extract the shared behavior to an aggregate method, domain service, repository persistence method, or private Application helper that does not open its own transaction.

## Events and jobs

- Domain events recorded by aggregates are dispatched after the aggregate has been persisted and the transaction has committed by default.
- Synchronous domain-event dispatch inside the transaction is only for listeners that intentionally participate in the same commit boundary.
- Jobs should be dispatched after commit when they depend on committed data.

See [Domain events](domain-events.md) and [Jobs](jobs.md).

## See also

- [Actions](actions.md) — Application action shape.
- [Models](data/models.md) — aggregate behavior and persistence boundary.
- [Repositories](data/repositories.md) — when repositories are justified.
