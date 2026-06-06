# Testing

> **Owns**
>
> - What to test at each layer
> - Test placement
> - PR checklist
>
> **Forbids**
>
> - Shipping behavior without tests
> - Weakening tests to make a build pass
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Models](data/models.md), [Read models](application/read-models.md), [Anti-patterns](anti-patterns.md)

Every behavioral change ships with tests.

## What to test

### Feature tests

Feature tests cover HTTP routes end-to-end:

- happy path;
- validation failures;
- authorization boundary;
- expected response/page-prop/resource shape;
- persisted state or emitted/queued side effects when relevant.

### Unit tests

Unit-test code with meaningful branching independent of HTTP:

- aggregate behavior and invariant failures;
- Application action outcome branches;
- value objects;
- result objects;
- builder constraints;
- pure services.

### Integration tests

Integration-test database-backed read models/queries and infrastructure services:

- query filtering, ordering, pagination, DTO projection;
- framework/vendor service wrappers;
- persistence behavior that depends on real database constraints.

## Test placement

```text
tests/Feature/<EntryPoint>/<Feature>Test.php
tests/Unit/Application/<UseCase>/<Action>Test.php
tests/Unit/Domains/<Context>/Models/<Aggregate>Test.php
tests/Unit/Domains/<Context>/ValueObjects/<ValueObject>Test.php
tests/Integration/Domains/<Context>/Queries/<Context>QueryTest.php
tests/Integration/Infrastructure/Eloquent/Repositories/<Aggregate>/<Repository>Test.php
tests/Integration/Infrastructure/<Capability>/<Strategy>/<Service>Test.php
```

## PR checklist

- [ ] Controllers call Application actions/queries; no business rules inline.
- [ ] Meaningful state transitions are aggregate methods, not raw status assignments.
- [ ] Aggregate methods do not persist or dispatch framework side effects.
- [ ] Application actions own multi-write transactions.
- [ ] Read models/queries return DTOs and do not accept HTTP request objects.
- [ ] No cross-context Eloquent model imports in Domain/write models.
- [ ] Cross-context references use IDs/value objects or published contracts.
- [ ] Feature tests cover route behavior.
- [ ] Unit tests cover aggregate/action branches.
- [ ] Integration tests cover non-trivial queries and infrastructure services.
