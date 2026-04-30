# Domain events

> **Owns**
>
> - Event naming (past-tense, aggregate-prefixed)
> - Where events live (`Domains/<X>/Events/`)
> - Payload rules (primitives + value objects + IDs only — never models)
> - Who emits (actions only) and who listens (the listening context)
> - The "audit logging and job dispatching belong in listeners, not in actions" rule
>
> **Forbids**
>
> - Emitting events from controllers, models, traits, or adapters
> - Putting Eloquent models in event payloads
> - Listeners that mutate state in the *emitting* context
> - Using events as a request/response mechanism (events are fire-and-forget)
>
> **See also**: [Actions](actions.md), [Architecture](architecture.md), [Value objects](data/value-objects.md), [Glossary](glossary.md)

A domain event is a **past-tense statement of fact**: something happened in the domain that other parts of the system might care about. Events are this project's primary mechanism for cross-context propagation; they are also how cross-cutting concerns (audit logging, async work) are kept out of the action that did the original work.

> Names like `EmployeeRegistered`, `DocumentPublished`, `ActivityRecorded` are illustrative.

## Where they live

```text
app/Domains/<ContextName>/Events/<NounVerbedPastTense>.php
```

Events live in the **emitting** context, never in the listening context. A `EmployeeRegistered` event fired from `Domains/Employees/` lives at `Domains/Employees/Events/EmployeeRegistered.php`, even when the only listener is in `Domains/Notifications/`.

A bounded context that exposes events as part of its public surface is, in practice, **publishing a contract**. Treat changes to an event class with the same care as changes to a port.

## Naming

Past-tense, aggregate-prefixed:

```text
EmployeeRegistered
EmployeeLoggedIn
EmployeeTwoFactorEnabled
EmployeeTwoFactorDisabled

DocumentCreated
DocumentApproved
DocumentPublished
DocumentArchived

ActivityRecorded
ActivityRedacted

DirectoryRecordImported
```

Avoid:

- Present-tense or imperative names (`RegisterEmployee`, `ApproveDocument`) — those are command/action names, not event names.
- Generic suffixes (`*Event`, `*Notification`) — every class in `Events/` is an event; the suffix is redundant.
- Names that describe the *cause* rather than the *fact* (`UserClickedRegister` is a UI event, not a domain event; `EmployeeRegistered` is the domain fact).

The verb is past tense; the noun is the aggregate that changed.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Events;

use App\Domains\Employees\ValueObjects\EmployeeId;
use Carbon\CarbonImmutable;

final readonly class EmployeeTwoFactorEnabled
{
    public function __construct(
        public EmployeeId $employeeId,
        public CarbonImmutable $enabledAt,
    ) {}
}
```

Rules:

- `final readonly class`. An event is an immutable fact.
- Public readonly properties for the payload.
- Constructor-promoted; no logic in the constructor beyond storing values.
- No methods other than getters (and those usually unnecessary on `readonly` properties).
- No `Dispatchable`, `Queueable`, or `SerializesModels` traits unless absolutely necessary. The project dispatches events synchronously in-process by default; if a specific event needs to be queued, the *listener* declares `ShouldQueue`, not the event.

## Payload rules

The payload contains only:

- **Aggregate IDs as value objects** (`EmployeeId`, `DocumentId`). Never raw integers — IDs are typed.
- **Other value objects** (`Email`, `Locale`, `DateRange`).
- **Primitives** (`string`, `int`, `bool`, `CarbonImmutable`) when no value object exists for the field yet.

The payload **never** contains:

- An Eloquent model. Models are tied to request scope and serialise badly; events outlive the request. If a listener needs the aggregate, it loads it by ID.
- An array of mixed scalars (`array $data`). Spell out the fields.
- Closures, resources, or anything that can't be safely serialised.
- Cross-context model references. Cross-context payloads use IDs only.

When in doubt: *if you serialise this event, store it for a year, and replay it, will it still mean the same thing?* If no, the payload is wrong.

## Who emits

Only **actions** emit events. Domain actions and Application actions both may.

```php
final class EnableEmployeeTwoFactorAction
{
    public function execute(Employee $employee): void
    {
        $employee->two_factor_secret = /* ... */;
        $employee->two_factor_confirmed_at = CarbonImmutable::now();
        $employee->saveOrFail();

        event(new EmployeeTwoFactorEnabled(
            employeeId: EmployeeId::fromModel($employee),
            enabledAt: CarbonImmutable::instance($employee->two_factor_confirmed_at),
        ));
    }
}
```

This Domain action does not wrap `DB::transaction()` — single-row writes don't need it, and Domain actions never open one. When an Application action *is* the use case's transaction root, it wraps the call to this Domain action; the `event(...)` then fires inside the same transaction, which is the correct behaviour for synchronous in-process listeners. See [Transactions](transactions.md).

Rules:

- **Emit after the write you're announcing.** The order in the example matters: persist first, then emit — a listener that observes the event finds the persisted state in the same transaction.
- **If the use case has a wrapping `DB::transaction()` and you want the event to fire only on commit**, dispatch a [job](jobs.md) with `->afterCommit()` instead. Synchronous listeners that mutate state are intended to roll back with the emitter.
- **Never emit from**: controllers, request data, models, traits, builders, view models, resources, adapters. Emitting from anywhere except an action puts the "this happened" knowledge outside the only place that knows whether it really happened.
- **Do not emit twice for the same fact.** If two actions both result in "Employee logged in," they should both end up calling the same lower-level action that emits the single canonical event.

## Who listens

Listeners live in the **context that reacts**, not in the context that emits. A listener for `EmployeeRegistered` that creates a notification preference record lives in `Domains/Notifications/Listeners/CreateNotificationPreferencesForEmployee.php`, not in `Domains/Employees/`.

Wire listeners in the listening context's service provider:

```php
// app/Providers/DomainServiceProvider.php (or a per-context provider)

protected $listen = [
    \App\Domains\Employees\Events\EmployeeRegistered::class => [
        \App\Domains\Notifications\Listeners\CreateNotificationPreferencesForEmployee::class,
        \App\Domains\Notifications\Listeners\SendWelcomeEmailToEmployee::class,
    ],
];
```

### What listeners do

- **Mutate state in the listening context.** A listener loads its own aggregate(s) and calls a Domain action of *its* context. It does not write to the emitting context's tables.
- **Trigger async work.** A listener that should run on a queue declares `implements ShouldQueue` on itself.
- **Audit logging.** Persist an audit row, write to a structured log, send a metric. The action does not know there is an auditor; the auditor knows there is an action.
- **Send notifications / mail.** Through a `Mailer` port — see [Ports and adapters § what goes through a port](ports-and-adapters.md#what-goes-through-a-port).

### What listeners do not do

- **Mutate state in the emitting context.** If `Employees` emits and `Employees` also listens to mutate `Employees`, the work belongs in the action, not split across an event boundary. Events are for *cross-context* or *cross-concern* propagation, not for within-context flow control.
- **Communicate back to the emitter.** Listeners are fire-and-forget. If the emitter needs a response, it is calling an action, not emitting an event.
- **Throw exceptions that the emitter must handle.** A listener that fails should log and either retry (via the queue) or fail loudly out-of-band; it must not break the emitting transaction.

## The "audit logging and job dispatching belong in listeners" rule

This is the rule that keeps Application actions thin.

When you find yourself writing:

```php
// inside an Application action
public function execute(Employee $employee): void
{
    $this->disableEmployeeAction->execute($employee);

    AuditLog::write('employee.disabled', employee_id: $employee->id);   // ← wrong
    dispatch(new SendDisabledEmployeeEmail($employee));                  // ← wrong
    Cache::forget("employee.permissions.{$employee->id}");               // ← wrong
}
```

…stop. The action's job is "disable the employee." Auditing, mail, and cache invalidation are *consequences* of that fact, not part of the act.

Refactor: emit one event, move the consequences to listeners.

```php
// the Domain action
public function execute(Employee $employee): void
{
    $employee->disabled_at = CarbonImmutable::now();
    $employee->saveOrFail();

    event(new EmployeeDisabled(
        employeeId: EmployeeId::fromModel($employee),
        disabledAt: CarbonImmutable::instance($employee->disabled_at),
    ));
}

// listeners (each in its own file, in the appropriate context)
// Domains/Audit/Listeners/RecordEmployeeDisabledAuditEntry.php
// Domains/Notifications/Listeners/SendEmployeeDisabledEmail.php
// Domains/Permissions/Listeners/InvalidateEmployeePermissionsCache.php
```

The action does one thing. Each listener does one thing. New consequences (a Slack notification, a webhook, a metric) become new listeners — the action does not change.

## Listener skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Listeners;

use App\Application\Notifications\Contracts\Mailer;
use App\Application\Notifications\WelcomeEmail;
use App\Domains\Employees\Events\EmployeeRegistered;
use App\Domains\Employees\Models\Employee;

final class SendWelcomeEmailToEmployee
{
    public function __construct(
        private Mailer $mailer,
    ) {}

    public function handle(EmployeeRegistered $event): void
    {
        $employee = Employee::query()->findOrFail($event->employeeId->value);

        $this->mailer->send(new WelcomeEmail($employee));
    }
}
```

Rules:

- `final`. Same as everywhere.
- Constructor-promoted dependencies (ports, never adapters).
- One public `handle(EventClass $event): void` method.
- One event type per listener. A listener that handles multiple unrelated events is a refactor target.
- The listener loads its own aggregate by ID; it does not receive the model in the payload.

## Async listeners

A listener that should run on a queue:

```php
final class SendWelcomeEmailToEmployee implements ShouldQueue
{
    public string $queue = 'notifications';
    // ...
}
```

The event itself stays synchronous; only the listener queues. This keeps the payload-must-be-serialisable rule visible at the listener level (which is where it actually matters).

## Cross-context boundaries through events

Events are the **default** mechanism for cross-context propagation:

- Emitter: doesn't know who listens.
- Listener: lives in the reacting context; loads its own aggregates; calls its own actions.
- Payload: opaque IDs + value objects.

This keeps each context independently deployable in code organisation: `Domains/Employees/` could be removed and replaced with a stub that emits the same events, and `Domains/Notifications/`'s code would not need to change.

For cases where the caller needs a synchronous result, use an Application action that calls Domain actions across contexts directly. See [Architecture § cross-context communication](architecture.md#cross-context-communication).

## Testing events

Use Laravel's `Event::fake()` only for capabilities that *do not* have a port (events themselves don't, by design — they are a framework primitive used as a domain mechanism). Two test patterns:

**Test the action emits the event:**

```php
public function test_disabling_an_employee_emits_the_event(): void
{
    Event::fake();
    $employee = Employee::factory()->create();

    $this->disableEmployeeAction->execute($employee);

    Event::assertDispatched(EmployeeDisabled::class, fn ($event): bool =>
        $event->employeeId->value === $employee->getKey()
    );
}
```

**Test a listener does its job:**

```php
public function test_welcome_email_listener_sends_email(): void
{
    $fakeMailer = new RecordingMailer();
    $this->app->bind(Mailer::class, fn () => $fakeMailer);
    $employee = Employee::factory()->create();

    (new SendWelcomeEmailToEmployee($fakeMailer))->handle(new EmployeeRegistered(
        employeeId: EmployeeId::fromModel($employee),
        registeredAt: CarbonImmutable::now(),
    ));

    $this->assertCount(1, $fakeMailer->sent);
}
```

Note the listener test substitutes the `Mailer` collaborator inline rather than booting `Mail::fake()`. The same rule applies as everywhere else: prefer the lightest-weight substitute. See [Testing § substituting collaborators in tests](testing.md#substituting-collaborators-in-tests).

## See also

- [Actions § what an action can call](actions.md#what-an-action-can-call) — events are listed as a thing actions emit.
- [Cross-context communication](cross-context.md) — events as one of three sanctioned cross-context mechanisms.
- [Transactions](transactions.md) — in-process listeners run inside the emitter's transaction; what that means for failure handling.
- [Jobs](jobs.md) — when a listener should dispatch a job instead of running synchronously.
- [Ports and adapters § what goes through a port](ports-and-adapters.md#what-goes-through-a-port) — listeners use ports for any external work they trigger.
- [Value objects § identifier value objects](data/value-objects.md#identifier-value-objects) — the type used for event-payload IDs.
- [Glossary](glossary.md) — definition of *domain event*.
