# Adding A Context

> **Owns**
>
> - The practical recipe for adding a bounded context
> - Minimum folders/files
> - How write and read flows are wired
>
> **Forbids**
>
> - Creating contexts for aggregate parts
> - Creating empty folders for future code
> - Splitting by UI role instead of language/rules
>
> **See also**: [Architecture](architecture.md), [Models](data/models.md), [Actions](actions.md), [Read models](application/read-models.md), [Cross-context communication](cross-context.md)

A bounded context is added when the application has a distinct language and rule set worth protecting. Do not create a context because a screen, route group, or actor exists.

## Minimum write-side shape

```text
app/Domains/<Context>/
├── Models/
│   └── <Aggregate>.php
├── Database/
│   ├── Factories/
│   └── Migrations/
└── Exceptions/                 ← only when aggregate/use-case failures exist

app/Application/<ContextOrUseCase>/
└── <Verb><Noun>Action.php
```

Add only folders with files.

## Aggregate root vs part

Create one context for the aggregate root and its parts:

```text
Domains/Orders/Models/Order.php
Domains/Orders/Models/OrderLine.php
```

Avoid:

```text
Domains/OrderLines/
```

unless `OrderLine` has its own identity, lifecycle, language, and rules independent of `Order`.

## Write flow

1. Delivery validates input and authorizes caller/resource.
2. Controller/command/job calls an Application action.
3. Application action loads the aggregate.
4. Application action calls aggregate behavior for meaningful operations.
5. Application action persists the aggregate or delegates persistence to a repository.
6. Application action dispatches recorded domain events after persistence/commit.

```php
final readonly class CancelOrderAction
{
    public function execute(OrderId $orderId, EmployeeId $cancelledBy): void
    {
        $order = Order::query()->findOrFail($orderId->toString());

        $order->cancel($cancelledBy);
        $order->saveOrFail();
    }
}
```

## Read flow

Use Application queries/read models for list/table/dashboard/report screens:

```text
app/Application/<Context>/Queries/List<Aggregates>Query.php
app/Application/<Context>/Filters/<Aggregate>ListFilter.php
app/Application/<Context>/ReadModels/<Aggregate>ListRow.php
app/Application/<Context>/ReadModels/<Aggregate>ListPage.php
```

Request data builds pure filter DTOs. Do not pass HTTP request objects into Application query/filter/read-model classes.

## Cross-context references

If the new context references another context, store identity/value objects, not foreign Eloquent models.

```text
Domains/<Other>/Contracts/<OtherId>.php
```

Same-context foreign keys and Eloquent relations are fine. Cross-context foreign keys are avoided by default.

## Done checklist

- [ ] Context name reflects domain language, not UI role.
- [ ] Aggregate root and aggregate parts are modeled in one context.
- [ ] Meaningful behavior lives on the aggregate root.
- [ ] Application action owns orchestration and persistence.
- [ ] Read queries live under Application.
- [ ] Cross-context references use IDs/value objects or published contracts.
- [ ] Tests cover aggregate invariants, Application action flow, and query shape.
