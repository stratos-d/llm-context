# Transactions

> **Owns**
>
> - The transaction-root rule: only **Application actions** open `DB::transaction()`
> - The "no transaction wrap for single-row writes" rule
> - The nesting policy (forbidden in this project)
> - What runs inside vs outside the commit boundary
> - Cross-context call behaviour relative to transactions
>
> **Forbids**
>
> - `DB::transaction()` inside Domain actions, controllers, services, listeners, jobs, models
> - Re-stating action / model / event rules — those live in their own files
>
> **See also**: [Actions](actions.md), [Architecture](architecture.md), [Cross-context communication](cross-context.md), [Domain events](domain-events.md), [Anti-patterns](anti-patterns.md)

A database transaction is a **commit boundary**: a set of writes that succeed or fail together. The project pins one place where that boundary is opened, so a reader can answer "is this atomic?" by looking at exactly one file: the Application action that owns the use case.

## The rule

**Only Application actions open `DB::transaction()`.** Domain actions, controllers, concrete services, listeners, jobs, middleware, and models never open one.

The Application action is the **transaction root**: the single point where the use case's commit boundary is decided. Everything it composes — Domain actions, services, event dispatches — runs inside that boundary.

```php
// Application/EmployeeAuth/SomeUseCaseAction.php
final class SomeUseCaseAction
{
    public function __construct(
        private SomeDomainAction $someDomainAction,
        private OtherDomainAction $otherDomainAction,
    ) {}

    public function execute(/* … */): Result
    {
        return DB::transaction(function () {
            $this->someDomainAction->execute(/* … */);
            $this->otherDomainAction->execute(/* … */);

            return Result::ok();
        });
    }
}
```

## When *not* to wrap

A single-row write is already atomic at the database level. Wrapping `$model->save()` in `DB::transaction()` adds zero safety and one layer of noise. Don't.

```php
// ❌ pointless
DB::transaction(function () use ($employee): void {
    $employee->last_login_at = $clock->now();
    $employee->save();
});

// ✅ same guarantee, less ceremony
$employee->last_login_at = $clock->now();
$employee->save();
```

Open a transaction only when the use case writes:

- More than one row across one or more tables that must commit together.
- One row across multiple statements (e.g. `update` + `delete` in the same logical step).
- A write *plus* a side effect that must roll back if the write fails (event whose listener also writes; outbox row; etc.).

If the use case is "load an aggregate, change one field, save it", no wrap.

## Domain actions never wrap

A Domain action protects an aggregate invariant. Whether its caller wants the work in a transaction is the **caller's** decision, not the Domain action's. A Domain action that opens its own transaction:

- Pretends to be a use case (it isn't — that's the Application action's job).
- Hides the commit boundary from the use-case file the reviewer is reading.
- Surprises the next caller, who wraps the call in their own transaction and now has nested-savepoint semantics they didn't ask for.

If a Domain action mutates multiple rows that must commit together, that is **fine** — it just trusts that whoever called it opened the transaction. If the only callers today are an Application action that wraps and a controller that doesn't, the controller is wrong: it should be calling an Application action that wraps, not the Domain action directly.

## Controllers, services, listeners, jobs never wrap

| Layer | What it does about transactions |
| ----- | ------------------------------- |
| Controller | Calls one Application action; never opens a transaction itself |
| Concrete service in `Infrastructure/` | Performs one composite framework operation; never opens a transaction (a service that also commits is a hidden action — split it) |
| Listener (synchronous) | Runs inside the emitter's transaction by default in Laravel; do not open a nested one |
| Job (queued) | The job is its own request lifecycle — its `handle()` method is treated like a controller and calls one Application action |
| Middleware | Never |
| Model | Never |

## No nested transactions

Laravel's `DB::transaction()` supports nesting via savepoints. **The project does not.** The rule is one transaction per use case, opened at the Application action.

If you find yourself wanting a nested wrap, the cause is one of:

- A Domain action opening its own transaction. Remove that wrap; it belongs at the Application layer.
- An Application action calling another Application action that also wraps. That's a use-case-from-a-use-case shape; the inner action should be a Domain action, or the work should be inlined, or the outer action should not wrap.
- A listener trying to "be safe" with its own transaction. It's already inside one synchronously; nested savepoints add complexity, not safety.

## Cross-context calls and transactions

A cross-context call (see [Cross-context communication](cross-context.md)) does **not** introduce a new commit boundary by itself. The behaviour depends on the mechanism:

| Mechanism | Inside the same transaction? |
| --------- | --------------------------- |
| Synchronous domain event (Laravel default) | Yes. The listener's writes commit / roll back with the emitter. |
| Published action call from one Application action to another | The inner published action's writes are inside the outer's transaction (Laravel does not auto-rewrap). The outer is the root; the inner does not open its own. |
| Future queued event / outbox / cross-process call | No — but those aren't built yet. See [Cross-context communication § reliable delivery](cross-context.md#reliable-delivery--current-honesty). |

A consequence: today, a synchronous listener that throws will roll back the *emitter's* writes. That is a feature, not a bug, but it means listeners must be **safe to fail** — failure is not isolated.

## What runs inside the transaction

Inside the `DB::transaction()` callback:

- All persistence calls related to the use case (`$model->save()`, `$model->delete()`, builder bulk updates).
- Domain action calls.
- `event(...)` calls — synchronous listeners run inside the same transaction by default.

What does **not** run inside the transaction:

- HTTP responses. Controllers compose actions and then return; the transaction has already closed.
- External I/O that the use case must not roll back (sending a webhook, calling a third-party API). Once the network call has happened, no rollback can undo it. If a use case needs to perform external I/O *and* persist a record of it, that is the [outbox pattern](cross-context.md#reliable-delivery--current-honesty)'s job — not implemented yet.
- Validation. Request data is validated before the controller calls the action; failure short-circuits before any DB work.

## Common shapes

### Use case writes one row only

No transaction wrap. The Application action calls a Domain action (or modifies and saves the aggregate inline); the single `save()` is atomic.

### Use case writes across multiple aggregates

`DB::transaction()` at the top of the Application action. Each Domain action mutates one aggregate; all of them commit together.

### Use case writes one aggregate and emits a domain event

Wrap **if** any synchronous listener writes data that must roll back together with the use case (the common case). No wrap if all listeners are pure side effects to non-database systems — but those are usually outbox candidates anyway.

### Use case calls a published action in another context

The outer Application action wraps. The inner published action does not (it is itself an Application action, but composed). Both contexts' writes commit together.

## Consequences for the existing code

Following from the rule above:

- `RecordEmployeeLoginAction` (Domain action, single-row write) — no wrap. ✅ already correct.
- `DisableEmployeeTwoFactorAction` (Domain action, single-row multi-column write) — no wrap. ✅ corrected.
- `EnableEmployeeTwoFactorAction` (Domain action, single-row multi-column write) — no wrap. ✅ corrected.
- `ConfirmEmployeeTwoFactorAction`, `ConsumeEmployeeRecoveryCodeAction`, `RegenerateEmployeeTwoFactorRecoveryCodesAction` (Domain actions, single-row writes) — no wrap. ✅ already correct.

When an Application action is added that composes more than one Domain action *and* the composition must commit together, that Application action will be the first place `DB::transaction()` appears in the codebase. Until then, the absence of any `DB::transaction(...)` call is a feature, not an oversight.

## See also

- [Actions § signature rules](actions.md#signature-rules) — short-form transaction guidance; this file is the long form.
- [Architecture § layer responsibilities](architecture.md#layer-responsibilities) — confirms Application action is the orchestration layer.
- [Cross-context communication § reliable delivery](cross-context.md#reliable-delivery--current-honesty) — what's *not* covered by the current transactional model.
- [Anti-patterns § framework-coupling and infrastructure leaks](anti-patterns.md#framework-coupling-and-infrastructure-leaks) — grep-friendly signals of misplaced transactions.
