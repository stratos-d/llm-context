# Models And Aggregates

> **Owns**
>
> - Eloquent models as aggregate roots and aggregate parts
> - Aggregate behavior and invariant placement
> - Persistence boundary for aggregate methods
> - Base model conventions
>
> **Forbids**
>
> - Saving from inside aggregate behavior methods
> - Framework delivery concerns on models
> - Cross-context Eloquent model imports on write models
> - Query/read-model placement rules — see [Read models](../application/read-models.md)
>
> **See also**: [Architecture](../architecture.md), [Actions](../actions.md), [Transactions](../transactions.md), [Domain events](../domain-events.md), [Value objects](value-objects.md)

Eloquent models may be aggregate roots in this Laravel-pragmatic architecture. They hold state, casts, relationships, read helpers, and meaningful behavior that protects invariants.

Aggregate methods mutate **in-memory state only**. They do not call `save()`, `update()`, `delete()`, `event()`, `dispatch()`, HTTP/session/auth helpers, queues, mailers, or external services.

Persistence happens after behavior, in the Application action or in a repository that hides aggregate persistence details.

## Aggregate root vs aggregate part

A model is an aggregate root when it has its own identity, lifecycle, language, and behavior boundary. A model is an aggregate part when it exists only inside another aggregate's consistency boundary.

```text
Domains/Orders/Models/Order.php       ← aggregate root
Domains/Orders/Models/OrderLine.php   ← aggregate part
```

Do not create a separate bounded context for an aggregate part unless it has its own lifecycle, identity, language, and rules.

## Meaningful behavior belongs on aggregates

Use aggregate methods for operations that protect business meaning or invariants:

```php
$order->cancel(cancelledBy: $employeeId);
$order->addLine(productId: $productId, quantity: $quantity);
$order->changeLineQuantity(lineId: $lineId, quantity: $quantity);
$access->revoke(revokedBy: $employeeId);
$organization->archive(archivedBy: $employeeId);
```

Bad style for meaningful behavior:

```php
$order->status = OrderStatus::Cancelled;
$order->cancelled_at = CarbonImmutable::now();
$order->save();
```

Preferred style:

```php
$order->cancel(cancelledBy: $employeeId);
$order->saveOrFail();
```

Simple field updates may stay in the Application action when there is no meaningful invariant:

```php
$profile->display_name = $input->displayName;
$profile->saveOrFail();
```

Do not invent behavior methods for every setter. Add behavior where it names a real domain operation or prevents invalid state.

## Aggregate behavior rules

Aggregate methods:

- use business verbs (`cancel`, `archive`, `revoke`, `grant`, `changeRole`);
- validate preconditions and invariants;
- throw domain exceptions for invalid operations;
- mutate only the aggregate's in-memory state;
- may record domain events for later dispatch;
- may alter aggregate parts through the aggregate root.

Aggregate methods must not:

- call `save()`, `saveOrFail()`, `update()`, `delete()`, or repository methods;
- call `event()`, `dispatch()`, queues, mailers, HTTP clients, or facades for side effects;
- import another bounded context's Eloquent model;
- accept request/resource/controller/view-model objects.

## Aggregate parts are changed through the root

Child entities/aggregate parts should be altered through the aggregate root, not directly through controllers, actions, repositories, raw pivot operations, or unrelated services.

```php
$order->changeLineQuantity(lineId: $lineId, quantity: $quantity);
$order->saveOrFail();
```

This keeps invariant checks in one place.

## Domain exceptions from aggregates

Aggregates may throw domain exceptions to protect invariants:

```php
final class Order extends BaseModel
{
    public function cancel(EmployeeId $cancelledBy): void
    {
        if ($this->status === OrderStatus::Shipped) {
            throw CannotCancelShippedOrder::for($this->getKey());
        }

        if ($this->status === OrderStatus::Cancelled) {
            throw CannotCancelAlreadyCancelledOrder::for($this->getKey());
        }

        $this->status = OrderStatus::Cancelled;
        $this->cancelled_by = $cancelledBy->toString();
        $this->cancelled_at = CarbonImmutable::now();
    }
}
```

The central exception handling rules live in [Exceptions](../exceptions.md).

## Recording domain events

Aggregates may record events while changing state:

```php
final class Order extends BaseModel
{
    /** @var list<object> */
    private array $recordedEvents = [];

    public function cancel(EmployeeId $cancelledBy): void
    {
        // invariant checks + state mutation...

        $this->recordedEvents[] = new OrderCancelled(
            orderId: OrderId::fromString($this->getKey()),
            cancelledBy: $cancelledBy,
        );
    }

    /** @return list<object> */
    public function releaseDomainEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
```

Application code persists the aggregate, then dispatches released events. See [Domain events](../domain-events.md).

## Cross-context references

A model in one bounded context should not import another context's Eloquent model for write-side domain behavior.

Allowed:

```php
use App\Domains\Organizations\Contracts\OrganizationId;

final class EmployeeOrganizationAccess extends BaseModel
{
    public function grant(OrganizationId $organizationId, EmployeeId $employeeId): void
    {
        $this->organization_id = $organizationId->toString();
        $this->employee_id = $employeeId->toString();
    }
}
```

Avoid:

```php
use App\Domains\Organizations\Models\Organization;
```

Same-context relationships and foreign keys are fine. Cross-context database foreign keys are avoided by default; store IDs/value objects and add indexes.

## BaseModel and base classes

Project base models should centralize only mechanics common to every model: builder defaults, factory resolution, common casts/traits that truly apply everywhere.

Do not put business behavior in base classes. Behavior belongs on the concrete aggregate root that owns the language.

## Magic properties and casts

Every concrete model should document database-backed magic properties in PHPDoc and define casts explicitly. The model file is the source of truth for static analysis.

## See also

- [Actions](../actions.md) — use-case orchestration and persistence.
- [Transactions](../transactions.md) — commit boundaries.
- [Value objects](value-objects.md) — identity and primitive promotion.
- [Cross-context communication](../cross-context.md) — context boundaries.
