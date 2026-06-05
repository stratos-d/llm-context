# Jobs

> **Owns**
>
> - Queued use-case delivery
> - Job placement and payload rules
> - Listener-vs-job split
>
> **Forbids**
>
> - Business logic in `handle()`
> - Eloquent models in payloads
> - Jobs in `Domains/` or `Infrastructure/`
>
> **See also**: [Actions](actions.md), [Domain events](domain-events.md), [Transactions](transactions.md)

A job is a queued delivery mechanism for one Application action.

```text
Queue payload -> Job::handle() -> Application action
```

## Placement

```text
app/Application/<UseCase>/Jobs/<Verb><Noun>Job.php
```

## Skeleton

```php
final class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly EmployeeId $employeeId,
    ) {}

    public function handle(SendWelcomeEmailAction $action): void
    {
        $action->execute($this->employeeId);
    }
}
```

Rules:

- `final`.
- Constructor carries primitives, value objects, and IDs.
- No Eloquent models in payloads.
- `handle()` calls one Application action and nothing else.
- If the body needs branching or persistence, move it into the action.

## Dispatching

Application actions may dispatch jobs when queue delivery is part of the use case or reaction flow.

Controllers and aggregate methods should not dispatch jobs directly. Controllers call actions; aggregates record domain events; listeners/actions decide whether to queue work.

Use `afterCommit()` when the job needs committed data:

```php
SendWelcomeEmailJob::dispatch($employeeId)->afterCommit();
```

## Listeners and jobs

A listener handles a domain event in the reacting context. If the reaction is non-trivial, slow, or failure-prone, the listener dispatches a job and the job calls an Application action.

```php
final class SendWelcomeEmailToNewEmployee
{
    public function handle(EmployeeRegistered $event): void
    {
        SendWelcomeEmailJob::dispatch($event->employeeId)->afterCommit();
    }
}
```

## See also

- [Actions](actions.md) — the action a job wraps.
- [Domain events](domain-events.md) — event payload and listener rules.
- [Transactions](transactions.md) — after-commit timing.
