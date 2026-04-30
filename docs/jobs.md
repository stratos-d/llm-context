# Jobs

> **Owns**
>
> - Where queued jobs live (`Application/<UseCase>/Jobs/<Verb><Noun>Job.php`)
> - The thin-wrapper rule: a job's `handle()` calls one Application action and nothing else
> - Where listeners live and when they should queue vs dispatch a job
> - Job naming, payload, and serialisation rules
> - Forbidden patterns
>
> **Forbids**
>
> - Business logic inside `Job::handle()`
> - Eloquent models in job payloads (use IDs + the action loads from the DB)
> - Jobs in `Domains/<X>/` or `Infrastructure/`
> - Re-stating Laravel's queue mechanics — see Laravel docs for retry / backoff / failed-job framework parts
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Domain events](domain-events.md), [Transactions](transactions.md), [Anti-patterns](anti-patterns.md)

A queued job is a **use case dispatched onto a queue instead of triggered by an HTTP request**. Same shape as a controller call: dispatcher hands payload to a thin wrapper, wrapper calls one Application action, action does the work. The queue is a delivery mechanism, not a programming model.

## Where they live

```text
app/Application/<UseCase>/Jobs/<Verb><Noun>Job.php
```

Jobs live alongside the Application action they wrap, under the same `<UseCase>/` folder. Reasons:

- A job is a delivery shape for a use case, just like a controller. Co-locating with the use case keeps "all the ways this work is triggered" in one place.
- Jobs are framework-agnostic in spirit (they pass through Laravel's queue, but they don't read HTTP). Putting them in `Application/` matches that.
- `Domains/` is reserved for domain code without delivery concerns; jobs touch the queue, which is delivery.
- `Infrastructure/` is for vendor wrappers and base classes; jobs are use-case glue, not infrastructure primitives.

A use case may have **zero or one** job. If you find yourself needing a second job for the same use case, the use case is doing two things — split it.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Application\Notifications\Jobs;

use App\Application\Notifications\SendWelcomeEmailAction;
use App\Domains\Employees\ValueObjects\EmployeeId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'notifications';

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

- **`final`**.
- **`implements ShouldQueue`** — every job in this folder is queued. There is no synchronous-only job; if you don't need the queue, call the Application action directly.
- **Constructor-promoted `readonly` properties** carrying primitives, value objects, and IDs. No Eloquent models — the action reloads from the DB by ID.
- **One method**, `handle()`, type-hinting the Application action it wraps. Laravel injects it from the container.
- **No business logic in `handle()`.** One call. If the body needs more than one statement, the use case belongs in the action and the job is too clever.

## Naming

`<Verb><Noun>Job`. Same shape as actions; the `*Job` suffix carries the queue-delivery category.

```text
SendWelcomeEmailJob
RetryFailedNotificationJob
ImportDirectoryRecordsBatchJob
RegenerateActivityProjectionJob
```

Avoid generic suffixes (`ProcessJob`, `WorkerJob`, `BackgroundJob`) — they describe the mechanism, not the work.

## Payload rules

The same rules apply as to domain events ([Domain events § payload rules](domain-events.md#payload-rules)):

- **IDs as value objects** (`EmployeeId`, `DocumentId`).
- **Other value objects** (`Email`, `Locale`).
- **Primitives** (`string`, `int`, `bool`, `CarbonImmutable`).

Never:

- An Eloquent model. The job may serialise and run minutes later — the model's state will be stale, and `SerializesModels` papers over a bad design.
- A closure, resource, or anything not safely serialisable.
- A bag of mixed scalars (`array $data`). Spell out the fields.

If the action needs the aggregate, it loads it from the ID in the action body. The job carries identity, not state.

## Dispatching jobs

From an Application action (allowed):

```php
final class RegisterEmployeeAction
{
    public function execute(/* … */): Employee
    {
        // … create employee …

        SendWelcomeEmailJob::dispatch(EmployeeId::fromModel($employee));

        return $employee;
    }
}
```

From a Domain action: **forbidden**. Domain actions don't know about delivery side effects; they emit domain events, and a listener decides whether to dispatch a job.

From a controller: **forbidden**. The same rule that forbids `event(...)` and persistence in controllers applies to `dispatch(...)`. Move the dispatch into an Application action.

## Jobs and transactions

A job runs in its **own** request lifecycle. Its `handle()` method is the new use-case entry point and the Application action it calls is its own transaction root. The dispatching action's transaction has already committed by the time the job runs (because of the `afterCommit` rule below).

**Always dispatch jobs after the transaction commits.** Otherwise the queue worker can pick the job up, run it, and look for data the rolled-back transaction never persisted. Two equally valid options:

- Use Laravel's `dispatch()->afterCommit()` chained call.
- Use Laravel's queue config flag `'after_commit' => true` to make this the default for all queued jobs.

The project standard is **opt-in via `afterCommit()`** at the call site, so the boundary is visible in the action body.

```php
SendWelcomeEmailJob::dispatch($employeeId)->afterCommit();
```

See [Transactions § what runs inside the transaction](transactions.md#what-runs-inside-the-transaction).

## Listeners and jobs

Listeners and jobs are different shapes:

- **Listener** — synchronous handler for a domain event. Lives in the *reacting* context (`Domains/<X>/Listeners/`). Runs inside the emitter's transaction. See [Domain events § who listens](domain-events.md#who-listens).
- **Job** — queued use case. Lives in `Application/<UseCase>/Jobs/`. Runs in its own request lifecycle.

The pattern for non-trivial async work is **listener that dispatches a job**, not a queued listener:

```php
// Domains/Notifications/Listeners/SendWelcomeEmailToNewEmployee.php
final class SendWelcomeEmailToNewEmployee
{
    public function handle(EmployeeRegistered $event): void
    {
        SendWelcomeEmailJob::dispatch($event->employeeId)->afterCommit();
    }
}
```

This keeps listeners trivial and synchronous (one statement: dispatch a job), keeps jobs as the only place where async work lives, and gives one consistent place to look when investigating a queue failure.

A listener may run synchronously and inline if its work is genuinely cheap and side-effect-free (writing an audit row, invalidating a cache). Anything that does I/O or might fail goes through a job.

## Failed jobs

Laravel's failed-job table records jobs whose retries have been exhausted. The project's posture today:

- **Retries**: leave `tries` at the framework default unless the use case has a documented reason otherwise.
- **Backoff**: leave at default; override per-job only when the failure mode is well-understood.
- **`failed()` method on the job**: implement when the failure should produce a domain event of its own (e.g. `WelcomeEmailDeliveryFailed`). The `failed()` method dispatches *another* job or fires a domain event; it does not contain logic.

Reliable cross-process delivery (outbox-pattern guarantees) is **not** in scope today; see [Cross-context communication § reliable delivery — current honesty](cross-context.md#reliable-delivery--current-honesty).

## Forbidden patterns

| Forbidden | Why | Right shape |
| --------- | --- | ----------- |
| Job in `app/Jobs/` (Laravel default flat folder) | Loses use-case grouping | Move to `Application/<UseCase>/Jobs/` |
| Job in `Domains/<X>/Jobs/` | Domain code doesn't know about delivery | Move to `Application/<UseCase>/Jobs/` |
| Job in `Infrastructure/Jobs/` | Infrastructure is vendor wrappers, not use-case glue | Move to `Application/<UseCase>/Jobs/` |
| Eloquent model in a job's constructor | Stale state, large payload, hidden serialisation | Pass IDs as value objects; the action reloads |
| Business logic inside `handle()` | The job is supposed to be a thin wrapper | Move logic into the Application action |
| `dispatch(...)` inside a controller, model, or Domain action | Same reason `event(...)` is forbidden in those layers | Dispatch from the Application action |
| Two distinct jobs sharing the same payload class via inheritance | The payload is the action's input; share through the action, not through job inheritance | Each job has its own constructor; no `BaseJob` |
| Job that runs synchronously (`SHOULD_QUEUE = false`) | If it shouldn't queue, it's an action call, not a job | Inline the call to the Application action |
| `JobMiddleware` doing logic the action should do | Cross-cutting middleware (rate-limit, throttle, single-execution) is fine; business logic is not | Move logic to the action |

## See also

- [Actions](actions.md) — the action a job wraps.
- [Domain events § async listeners](domain-events.md#async-listeners) — when a listener should dispatch a job vs run inline.
- [Transactions § common shapes](transactions.md#common-shapes) — `afterCommit` rule for dispatching jobs.
- [Cross-context communication](cross-context.md) — listeners belong to the reacting context; jobs are an Application-layer concern.
- [Anti-patterns § job misuse](anti-patterns.md) — grep-friendly red flags.
- [Glossary](glossary.md) — definition of *Job*.
