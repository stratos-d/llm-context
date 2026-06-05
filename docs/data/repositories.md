# Repositories

> **Owns**
>
> - When to introduce a repository
> - Repository placement and naming
> - How repositories relate to aggregate persistence
>
> **Forbids**
>
> - A repository for every model
> - Thin wrappers over `Model::query()` or `saveOrFail()`
> - Repositories owning use-case transaction boundaries
> - Repositories for paginated screen queries — see [Read models](../application/read-models.md)
>
> **See also**: [Models](models.md), [Builders](builders.md), [Actions](../actions.md), [Transactions](../transactions.md), [Read models](../application/read-models.md)

A repository is optional. It loads and saves aggregates when direct Eloquent loading/saving would leak persistence details into the Application action.

Default posture:

```text
Application action loads aggregate with Eloquent/builder
Application action calls aggregate behavior
Application action calls saveOrFail()
```

Introduce a repository only when that default becomes unclear or unsafe.

## When to introduce one

Use a repository when at least one is true:

1. **Aggregate persistence spans root + children** and callers should not know the save order.
2. **Aggregate loading is materially different from table shape** and must hydrate a complete consistency boundary.
3. **The persistence mechanism is genuinely variable** or external.
4. **The repository name describes a domain persistence capability**, not a query convenience.

Do not introduce a repository just to be "DDD" or "testable." Eloquent-backed code is tested with factories and the database.

## Transaction boundary

Application actions own use-case transactions. Repositories may perform narrow persistence internals, but they should not decide the use-case boundary.

Preferred flow:

```text
Application action opens transaction
Application action loads aggregate
Application action calls aggregate behavior
Repository/action persists aggregate
Application action dispatches events after persistence/commit
```

If a repository method sounds like a use case (`cancelOrder`, `grantAccess`, `archiveOrganization`), it is probably an Application action.

## Placement

Concrete repositories live in Infrastructure because they are persistence adapters:

```text
app/Infrastructure/Eloquent/Repositories/Orders/EloquentOrderRepository.php
```

Introduce an interface only when a second implementation exists or is imminent. The interface lives with the caller:

```text
app/Application/Orders/Contracts/OrderRepository.php
```

or, rarely, in a domain context's published contracts when domain code truly needs the port.

## Naming

- Concrete class: `<Mechanism><Aggregate>Repository`, e.g. `EloquentOrderRepository`.
- Interface when justified: `<Aggregate>Repository`.
- Methods are named by intent: `find`, `save`, `nextPendingReview`, not `whereStatusAndReviewer`.

## Skeleton

```php
final class EloquentOrderRepository
{
    public function find(OrderId $orderId): ?Order
    {
        return Order::query()
            ->with(['lines'])
            ->find($orderId->toString());
    }

    public function save(Order $order): void
    {
        $order->saveOrFail();

        foreach ($order->lines as $line) {
            $line->saveOrFail();
        }
    }
}
```

The Application action decides whether this runs inside a transaction.

## Repository vs read model

Repositories load aggregates for behavior. Read models answer queries for screens/reports and return DTOs.

If the consumer will not mutate the returned data, it is probably a read model, not a repository.

## See also

- [Read models](../application/read-models.md) — query-shaped data.
- [Transactions](../transactions.md) — use-case commit boundaries.
- [Models](models.md) — aggregate roots and parts.
