# Cross-Context Communication

> **Owns**
>
> - How bounded contexts reference and collaborate with each other
> - Published actions, published read models, and domain events
> - Cross-context identity/reference rules
> - Cross-context database foreign-key posture
>
> **Forbids**
>
> - Cross-context Eloquent model imports in Domain/write models
> - Cross-context database foreign keys by default
> - Calling another context's controllers, requests, resources, or private models
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Models](data/models.md), [Read models](application/read-models.md), [Domain events](domain-events.md)

A bounded context owns its language, rules, write model, and schema. Another context may reference it, but does not own or mutate its aggregate.

## Identity references

Across bounded contexts, reference another aggregate by identity/value object, not by its Eloquent model.

Accepted:

```php
use App\Domains\Employees\Contracts\EmployeeId;
use App\Domains\Organizations\Contracts\OrganizationId;

final class EmployeeOrganizationAccess extends BaseModel
{
    public function grant(EmployeeId $employeeId, OrganizationId $organizationId): void
    {
        $this->employee_id = $employeeId->toString();
        $this->organization_id = $organizationId->toString();
    }
}
```

Avoid in Domain/write models:

```php
use App\Domains\Employees\Models\Employee;
use App\Domains\Organizations\Models\Organization;
```

`EmployeeId` identifies an Employee aggregate. It does not load the employee, prove it exists, or prove it is active. Existence/status checks happen in Application actions through public queries, services, contracts, or published read models.

## Identity value object placement

Identity value objects are published contracts of the owning bounded context:

```text
Domains/Employees/Contracts/EmployeeId.php
Domains/Users/Contracts/UserId.php
Domains/Organizations/Contracts/OrganizationId.php
```

Other contexts may depend on those identity contracts. They should not depend on the owning context's Eloquent model.

Do not duplicate `EmployeeId` inside another context just to avoid depending on `Employees`. Depending on `App\Domains\Employees\Contracts\EmployeeId` is allowed because it is a published boundary type. Depending on `App\Domains\Employees\Models\Employee` from another context is not allowed.

## Database references

Same bounded context:

```text
Eloquent relations and database foreign keys are fine.
```

Different bounded contexts:

```text
Store ID/value object.
Add an index.
Avoid DB-level foreign key constraints by default.
Do not import the other context's Eloquent model in Domain/write models.
```

A shared database does not mean shared ownership.

## Collaboration mechanisms

Use the lowest-coupling mechanism that fits the need:

| Mechanism | Use when | Coupling |
|---|---|---|
| Domain event | Another context reacts to something that happened and no synchronous result is needed | Loose |
| Published Application action | Caller needs a synchronous capability/result the target context deliberately exposes | Medium |
| Published read model/query | Caller needs foreign read data or a projection | Medium |
| Identity value object | Caller only needs to store or pass a reference | Low |

## Published Application actions

A published action is an Application action that a context deliberately exposes for other contexts to call.

Rules:

- Mark it with `@published` in the class docblock.
- Inputs are primitives, DTOs, or value objects, not another context's Eloquent model.
- Outputs are primitives, DTOs, value objects, or result objects, not another context's Eloquent model.
- Idempotency is documented if retry is possible.
- Renaming is a boundary-breaking change.

Internal Application actions remain the default. Publish only when another context actually needs the capability.

## Published read models

Read models are Application-layer query results by default. A published read model/query is a deliberate contract used by another context.

Published read models may own cross-context joins because they are projections, not write models. They return DTOs and do not leak aggregates.

## Domain events

Use domain events when:

- the emitter does not need a result;
- the reaction is separate from the original use case;
- multiple listeners may exist over time;
- the emitter should not know who listens.

Events carry identity/value objects, not Eloquent models. See [Domain events](domain-events.md).

## Forbidden patterns

| Forbidden | Why | Right shape |
|---|---|---|
| Importing another context's `Models/<X>` in a Domain/write model | Leaks foreign aggregate ownership | Store an ID/value object; query through Application/published read model when needed |
| Cross-context DB foreign key | Makes another context's schema part of this context's invariant | Store ID + index |
| Raw cross-context pivot writes | Bypasses the aggregate/context that owns access/membership rules | Use the owning aggregate behavior or published action |
| Calling another context's controller/request/resource | Delivery is private to entry points | Expose an Application action or read model |
| Joining another context in a builder/write model | Builder belongs to one context's write/read helper surface | Use an Application query/read model |

## Context examples

When modeling organization-related systems, split contexts by language and lifecycle, not by UI role. For example, access/membership rules around organizations may deserve their own context when they are not purely employee, user, or organization lifecycle rules.

Use examples like this to test boundaries; do not copy the names unless they match the application's language.

## See also

- [Architecture](architecture.md) — bounded context and layer layout.
- [Actions](actions.md) — published Application actions.
- [Read models](application/read-models.md) — Application queries and published projections.
- [Domain events](domain-events.md) — event rules.
