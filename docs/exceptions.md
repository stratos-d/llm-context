# Exceptions

> **Owns**
>
> - Domain exception hierarchy
> - Where exceptions are thrown
> - Delivery-layer mapping
>
> **Forbids**
>
> - HTTP/framework response types inside domain exceptions
> - Catching domain exceptions in controllers just to convert them manually
>
> **See also**: [Models](data/models.md), [Actions](actions.md), [Architecture](architecture.md)

Domain exceptions are typed failures for violated invariants or failed preconditions.

Aggregates may throw domain exceptions to protect behavior:

```php
$order->cancel($employeeId); // may throw CannotCancelShippedOrder
```

Application actions may throw domain exceptions when the precondition belongs to the use case rather than one aggregate:

```php
if (! $organizationAccessPolicy->canGrant($actorId, $organizationId)) {
    throw CannotGrantOrganizationAccess::for($actorId, $organizationId);
}
```

## Hierarchy

```text
Domains/DomainException.php
Domains/Orders/Exceptions/OrderException.php
Domains/Orders/Exceptions/CannotCancelShippedOrder.php
```

The root exception is abstract. Each context may define an abstract context base, and concrete failures extend it.

## Naming

Name the failed business operation or invariant:

```text
CannotCancelShippedOrder
CannotChangeRevokedAccess
CannotGrantDuplicateAccess
CannotArchiveAlreadyArchivedOrganization
```

Avoid generic names such as `InvalidStateException` unless the domain language is genuinely generic.

## What exceptions contain

Domain exceptions may contain IDs, value objects, enum values, and safe diagnostic strings. They should not contain HTTP responses, requests, resources, controllers, or Eloquent models from other contexts.

## Mapping to delivery

The central exception handler maps domain exceptions to HTTP/Inertia/API responses. Controllers should not catch domain exceptions only to convert them into responses.

## See also

- [Models](data/models.md) — aggregate behavior and invariant protection.
- [Actions](actions.md) — use-case preconditions.
