# Domain Events

> **Owns**
>
> - Domain event naming and payload rules
> - Aggregate event recording
> - Dispatch timing after persistence/commit
> - Listener placement
>
> **Forbids**
>
> - Eloquent models in event payloads
> - Framework dispatch calls inside aggregate behavior
> - Treating events as reliable cross-process delivery without an outbox
>
> **See also**: [Models](data/models.md), [Actions](actions.md), [Transactions](transactions.md), [Jobs](jobs.md), [Cross-context communication](cross-context.md)

A domain event is a fact that happened in a bounded context and may matter elsewhere.

Events are part of a context's public language. Changing an event name or payload is a contract change.

## Naming

Use past-tense business facts:

```text
OrderCancelled
OrganizationArchived
EmployeeOrganizationAccessGranted
MembershipSuspended
```

Avoid technical names like `OrderUpdated` unless the domain really speaks that way.

## Payload rules

Payloads contain primitives, enums, timestamps, and value objects/identity objects. Do not put Eloquent models in events.

```php
final readonly class OrderCancelled
{
    public function __construct(
        public OrderId $orderId,
        public EmployeeId $cancelledBy,
        public CarbonImmutable $cancelledAt,
    ) {}
}
```

## Recording events on aggregates

Aggregates may record events while enforcing behavior:

```php
$order->cancel($employeeId);
$order->saveOrFail();

foreach ($order->releaseDomainEvents() as $event) {
    event($event);
}
```

The aggregate records the fact. Application code dispatches it after persistence.

Aggregate methods should not call `event(...)` directly. That couples domain behavior to Laravel's dispatcher and makes persistence/commit timing harder to reason about.

## Dispatch timing

Preferred direction:

```text
Aggregate records event
Application action/repository persists aggregate
Transaction commits
Application layer dispatches recorded events
```

If a listener intentionally participates in the same transaction, dispatch inside the transaction and document why. Otherwise dispatch recorded events after commit, or dispatch a job with `afterCommit()` when queued work depends on committed data.

## Listener placement

A listener lives in the reacting context, not the emitting context.

```text
Domains/Orders/Events/OrderCancelled.php
Domains/Billing/Listeners/ReleaseOrderHoldListener.php
```

Listeners should be small. If the reaction is non-trivial or async, the listener dispatches a job and the job calls an Application action.

## Reliable delivery

In-process Laravel events are not reliable cross-process delivery. If guaranteed delivery matters, document and introduce an outbox/retry mechanism before depending on events as durable integration.

Until then, use published Application actions for synchronous results that must fail visibly.

## See also

- [Cross-context communication](cross-context.md) — when to use events vs published actions/read models.
- [Transactions](transactions.md) — event dispatch relative to commit boundaries.
- [Jobs](jobs.md) — async reactions.
