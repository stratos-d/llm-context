# Repositories

> **Owns**
>
> - The write-side half of the data-access rule
> - When a repository is required vs. not created
> - Repository placement, naming, and the property-naming allowance
>
> **Forbids**
>
> - Aggregate load/save inline in an action, controller, resource, or service
> - A speculative repository with nothing to persist
> - Repositories owning use-case transaction boundaries
> - Repositories for screen queries — that is a reader, see [Read models](../application/read-models.md)
>
> **See also**: [Read models](../application/read-models.md), [Models](models.md), [Builders](builders.md), [Actions](../actions.md), [Transactions](../transactions.md)

A repository is the **write-side** half of the [data-access rule](../application/read-models.md#the-data-access-rule): aggregate load-to-mutate and save go through a repository, never inline in an action or controller. Reads go through a [`{Context}Query`](../application/read-models.md); nothing else executes queries.

## Required when used, never speculative

Do **not** create a repository with nothing to persist. But the moment a context has *any* aggregate write or load-to-mutate, it goes in a repository — it is not left inline "because it's simple." This is the deliberate trade for the data-access rule: one obvious home per aggregate's persistence, no stray `Model::query()` / `save()` scattered across actions and controllers.

A repository loads and saves aggregate roots through their root (children cascade from the root, per [Models](models.md)).

## Transaction boundary

Application actions own use-case transactions. Repositories perform persistence internals; they do not decide the use-case boundary.

```text
Application action opens transaction
Application action loads aggregate (repository)
Application action calls aggregate behavior
Repository persists aggregate
Application action dispatches events after persistence/commit
```

If a repository method sounds like a use case (`cancelOrder`, `grantAccess`, `archiveOrganization`), it is an Application action, not a repository method.

## Placement

Concrete repositories live in Infrastructure because they are persistence adapters:

```text
app/Infrastructure/Eloquent/Repositories/Orders/OrderRepository.php
```

Introduce an interface only when a second implementation exists or is imminent; it lives with the caller (`app/Application/Orders/Contracts/OrderRepository.php`), or rarely in a domain context's published contracts. With a single implementation, inject the concrete class directly.

## Naming

- **Concrete class: `<Aggregate>Repository`**, e.g. `OrderRepository`. The `Infrastructure/Eloquent/Repositories/` location already conveys the mechanism, so the class name does not restate it — same principle as [Conventions § avoid](../conventions.md#avoid) (no suffix/prefix that restates the folder).
- **When a port is justified** (≥2 implementations): the **interface** takes the bare `<Aggregate>Repository` name, and each concrete is disambiguated by its mechanism prefix — `EloquentOrderRepository`, `ApiOrderRepository` — implementing `OrderRepository`. Promoting a single concrete to a port is a mechanical rename at that point.
- Methods named by intent: `find`, `save`, `findByEmail`, `nextPendingReview` — not `whereStatusAndReviewer`.
- **Injected property:** `$orderRepository` (the class name camelCased). When a mechanism-prefixed concrete is injected directly (a port coexists), the prefix may drop in the property — `EloquentOrderRepository $orderRepository`. See [Conventions § variable names match the class](../conventions.md#naming).

## Skeleton

```php
final class OrderRepository
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

## Repository vs query

Repositories load aggregates for behavior and persist them. A `{Context}Query` answers reads for screens/reports/decision-support and returns DTOs. If the consumer will not mutate the returned data, it is a query, not a repository.

## See also

- [Read models](../application/read-models.md) — the read-side counterpart and the canonical statement of the data-access rule.
- [Transactions](../transactions.md) — use-case commit boundaries.
- [Models](models.md) — aggregate roots and parts.
