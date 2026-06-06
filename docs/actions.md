# Actions

> **Owns**
>
> - Application action naming and placement
> - Use-case orchestration rules
> - Transaction boundary rules at the use-case level
> - Result-object convention
> - What actions may and may not call
>
> **Forbids**
>
> - HTTP concerns — see [Controllers](http/controllers.md)
> - Validation — see [Request data](http/request-data.md)
> - Owning business invariants that belong on aggregates — see [Models](data/models.md)
>
> **See also**: [Philosophy](philosophy.md), [Architecture](architecture.md), [Transactions](transactions.md), [Models](data/models.md), [Domain events](domain-events.md), [Ports and adapters](ports-and-adapters.md), [Exceptions](exceptions.md)

An action is an **Application-layer use case**. It owns the steps needed to accomplish one goal for one caller. It coordinates work; it does not become the long-term owner of important business rules.

```text
Controller / Command / Job
    -> Application Action
        -> Aggregate behavior
            -> Persistence
```

## Placement

Actions live under `app/Application/<UseCase>/`:

```text
app/Application/Orders/
├── CancelOrderAction.php
├── CancelOrderInput.php        ← pure Application input DTO, when useful
├── CancelOrderResult.php        ← only when justified
└── Inputs/
    └── CancelOrderInput.php     ← optional folder form for larger use-case groups
```

There is no `Domains/<Context>/Actions/` layer in this architecture. Aggregate-specific behavior belongs on the aggregate root. Use-case orchestration belongs in Application.

## Naming

`<Verb><Noun>Action`:

```text
CancelOrderAction
GrantOrganizationAccessAction
CreateOrganizationWithOwnerAction
RecordEmployeeLoginAction
CompleteTwoFactorChallengeAction
```

The name should describe the use case in business language. Avoid generic classes such as `OrderService`, `AccessManager`, or `Helper`.

## What an Application action does

An Application action may:

- authorize or coordinate authorization decisions that are not already handled at the delivery boundary;
- load aggregates by identity;
- open the use-case transaction when multiple writes must commit together;
- call meaningful aggregate behavior (`$order->cancel(...)`, `$access->revoke(...)`);
- perform simple low-risk field updates when no domain behavior is present;
- persist aggregates with `saveOrFail()` or through a repository;
- call concrete services or ports for external capabilities;
- dispatch recorded domain events after persistence/commit;
- return `void`, a domain value, `bool`, or a typed result object.

## What an Application action must not do

An Application action must not:

- return HTTP, Inertia, redirect, JSON response, or resource objects;
- accept `Request`, `FormRequest`, entry-point request data objects, controller, resource, or view-model objects;
- call framework delivery helpers such as `request()`, `redirect()`, `back()`, `session()`, `Auth::guard()`, or Inertia helpers;
- directly mutate important lifecycle/status fields instead of calling aggregate behavior;
- import another bounded context's Eloquent model for write-side domain work;
- hide major side effects in a generic service class.

Bad style for meaningful behavior:

```php
$order->status = OrderStatus::Cancelled;
$order->cancelled_at = CarbonImmutable::now();
$order->save();
```

Preferred style:

```php
$order->cancel(cancelledBy: $actorId);
$order->saveOrFail();
```

Simple field updates can stay simple:

```php
$profile->display_name = $input->displayName;
$profile->saveOrFail();
```

## Skeleton

```php
final readonly class CancelOrderAction
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function execute(OrderId $orderId, EmployeeId $cancelledBy): void
    {
        $events = $this->db->transaction(function () use ($orderId, $cancelledBy): array {
            $order = Order::query()->findOrFail($orderId->toString());

            $order->cancel(cancelledBy: $cancelledBy);
            $order->saveOrFail();

            return $order->releaseDomainEvents();
        });

        foreach ($events as $event) {
            event($event);
        }
    }
}
```

Notes:

- The action owns the transaction boundary.
- The aggregate owns the invariant-protecting behavior.
- The aggregate does not save itself.
- Events are dispatched after persistence/commit by default. Dispatch inside the transaction only when the listener intentionally participates in the same commit boundary. See [Domain events](domain-events.md).

## Result objects

Return a typed `*Result` only when at least one is true:

1. The caller does not already have the returned data.
2. The use case has three or more normal outcome states.

Otherwise return `void`, `bool`, a value object, or the aggregate/value the caller needs.

```php
final readonly class VerifyEmployeeCredentialsResult
{
    private function __construct(
        public bool $verified,
        public bool $requiresTwoFactorChallenge,
        public ?EmployeeId $employeeId,
    ) {}

    public static function verified(EmployeeId $employeeId): self
    {
        return new self(true, false, $employeeId);
    }

    public static function pendingTwoFactor(EmployeeId $employeeId): self
    {
        return new self(true, true, $employeeId);
    }

    public static function invalid(): self
    {
        return new self(false, false, null);
    }
}
```

Result-object rules:

- Co-locate the result with the action.
- Use `final readonly class`.
- Do not return HTTP/framework types.
- Do not create a result object for one boolean unless the domain language requires named outcomes.

## Signature rules

- Use one public method named `execute()`.
- Constructor-inject dependencies; do not call `app()` inside method bodies.
- Inject concrete services unless a port/interface is justified.
- Prefer IDs, primitives, value objects, and pure Application input DTOs over route-bound Eloquent models.
- **When a write takes three or more inputs, or any `list`/array, accept a single `<Verb><Noun>Input` DTO** rather than a long parameter list. Trivial one- or two-scalar writes stay as scalar parameters.
- Application input DTOs are a plain `final readonly class` (never a `spatie/laravel-data` object) and live near the action, at `Application/<ContextOrUseCase>/<Verb><Noun>Input.php` or `Application/<ContextOrUseCase>/Inputs/<Verb><Noun>Input.php`.
- Do not accept request data classes from `Interfaces/<EntryPoint>/Requests/`. The request object maps itself to the input DTO via `toInput()` at the delivery boundary — see [Request data § from request to action](http/request-data.md#from-request-to-action).
- Load the aggregate inside the action, especially for behavior-heavy operations and transactional writes.
- Accept aggregate roots only when the caller legitimately owns the loaded aggregate and no transaction/reload boundary is needed.
- Prefer identity value objects for cross-context references.

## See also

- [Models](data/models.md) — aggregate behavior and persistence boundary.
- [Transactions](transactions.md) — transaction ownership.
- [Domain events](domain-events.md) — recording and dispatching events.
- [Read models](application/read-models.md) — query side; not actions.
- [Ports and adapters](ports-and-adapters.md) — when to introduce interfaces.
