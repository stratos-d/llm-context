# Cross-context communication

> **Owns**
>
> - The rule for how one bounded context calls into another
> - The three sanctioned mechanisms (domain events, published actions, published read models) and when each applies
> - The "published action" concept: a deliberately public Application action that other contexts may call
> - The forbidden cross-context patterns
> - The context map (which contexts depend on which, in what direction)
> - The current honesty about in-process domain events vs reliable cross-process delivery
>
> **Forbids**
>
> - Restating the action / event / read-model rules — those live in their own files
> - Microservice / queue / outbox specifics — out of scope until those mechanisms exist
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Domain events](domain-events.md), [Read models](http/read-models.md), [Transactions](transactions.md), [Anti-patterns](anti-patterns.md)

A bounded context is an isolated model with its own ubiquitous language, schema, and rules. Two contexts may share a database, a deployment, and a request lifecycle, but they may not share **types**, **tables**, or **call sites**. This file describes how they collaborate without sharing those things.

The default posture is **integration through Application code, not through Domain code.** Domain code in `Domains/<X>/` exists in its own world; the moment it imports anything from another `Domains/<Y>/`, the boundary is broken.

## The three sanctioned mechanisms

```text
Context A                                Context B
─────────                                ─────────
emits                                    listens
   ↓                                        ↑
   └────────► Domain event ─────────────────┘     (default for side effects)


Application                              Application
   action                                    or
                                             ↓
            ────► Published action ─────►  Domain action
                                                              (for synchronous results from a deliberately public capability)


calls                                    publishes
  ↓                                          ↑
  └─────────► Published read model ──────────┘     (for cross-context queries)
```

| Mechanism | Use when | Lives at | Coupling |
| --------- | -------- | -------- | -------- |
| **Domain event** | One context observes that something happened in another and reacts asynchronously (or in-process today; see § [Reliable delivery — current honesty](#reliable-delivery--current-honesty)) | Emitter: `Domains/<Emitter>/Events/`. Listener: `Domains/<Listener>/` registers a handler in its `DomainServiceProvider`. | Loosest. The emitter does not know who listens. |
| **Published action** | Caller needs a synchronous result *and* the target context has chosen to make this capability publicly callable | `Application/<UseCase>/<Verb><Noun>Action.php`, marked as published in its docblock | Medium. Caller imports a specific class. |
| **Published read model** | Caller needs to *query* foreign data, possibly joined with its own | The producing context's `ReadModels/` (see [Read models](http/read-models.md)) | Medium. Caller depends on the result DTO shape, not on the query mechanism. |

When in doubt, prefer **events** for side effects, **published read models** for queries, and reach for **published actions** only when neither fits.

## Published actions

Most Application actions exist to serve a single use case driven by one entry-point controller. Those are **internal**: only their own controller calls them.

A **published action** is an Application action a context has deliberately exposed for *other contexts* to call. Two things make an action published:

1. **Intent.** The action is named in the target context's outward vocabulary, not in entry-point vocabulary. `CreateClientAction`, not `CreateClientFromAdminFormAction`.
2. **Marker.** Its class docblock starts with `@published` (a project-only doctag — no framework reads it; it's a grep-target for reviewers and for this rule):

```php
/**
 * @published Used by other bounded contexts as a synchronous integration point.
 */
final class CreateClientAction
{
    public function execute(/* … */): CreateClientResult
    {
        // …
    }
}
```

Rules for published actions:

- **Inputs are primitives or DTOs**, never another context's aggregate or model. The caller has no business holding a foreign Eloquent instance.
- **Outputs are primitives, DTOs, or value objects**, never another context's model. If the caller needs foreign data, it gets a published read model or an ID + `whereId()` lookup against a published read model — not the model.
- **Idempotency is documented** if the caller might retry.
- **Authorization is the caller's job** at the entry-point boundary; published actions assume a trusted in-process caller.
- **Renames break the boundary.** A published action's name is part of its contract; renaming is a breaking change other contexts must adapt to in lockstep.

Internal Application actions remain the default. Promote to published only when a second context actually needs to call.

## Published read models

A read model that crosses context boundaries lives in the **producing** context. It is responsible for the JOIN; the consuming context calls it and receives DTOs.

Forbidden alternatives:

- The consumer reaching into the producer's tables with its own builder.
- A JOIN written inside the consumer.
- A foreign-key constraint linking consumer table → producer table.

Allowed alternative when needed: store an opaque ID locally, pass it to the producer's published read model when the foreign data is wanted.

The full read-model rules — placement, DTO shape, when read models earn their keep — live in [Read models](http/read-models.md).

## Domain events

Used when:

- The emitter does not need a result.
- The reaction is logically *separate* from the original use case (sending an email, recalculating a projection, notifying another context).
- Multiple listeners may exist over time, and the emitter should not know about any of them.

Emitter and listener rules — naming, payload (primitives + value objects only, never models), where listeners register — live in [Domain events](domain-events.md). This file owns only the cross-context part: a handler subscribed to another context's event lives in the **listener's** `DomainServiceProvider`, not the emitter's.

## Forbidden patterns

| Forbidden | Why | Right shape |
| --------- | --- | ----------- |
| `use App\Domains\<Other>\Models\<X>` inside `Domains/<This>/` | Domain code may not see another context's types | Cross-context call goes through Application; Domain code stays inside its own folder |
| `use App\Domains\<Other>\Actions\<X>` inside `Domains/<This>/` or inside an Application action that does not own a cross-context use case | Domain actions are never cross-context callable; Application actions cross only via *published* actions | Either accept that this is a published action and mark it `@published`, or split the use case |
| Foreign key column referencing another context's table | Schemas are context-private | Store an opaque ID; project foreign data via published read models or events |
| `JOIN` to another context's table inside a builder | Same | A published read model in the producer owns the join |
| Calling another context's controller, request data object, view model, or resource | Delivery is private to its entry point | Whatever the caller needs, expose through Application or a read model |
| Subscribing to another context's event from inside `Domains/<Other>/` | Listeners belong to the *reacting* context | Register the handler in the reacting context's `DomainServiceProvider` |
| Adding `@published` to an action because you'd like to call it once from a test | The marker is a public-API contract, not a convenience | Test the action directly without the marker; only mark `@published` when a *second context* needs the call |

## Reliable delivery — current honesty

The project today emits domain events **in-process**, via Laravel's synchronous event dispatcher. That works today because:

- There is one bounded context with side effects (`Employees`).
- Listeners run inside the same request lifecycle and the same database transaction (so `event(...)` followed by a transaction rollback safely undoes the listener's work too — *if and only if* the listener's writes are inside the same transaction).
- No cross-process publication exists.

This is **not reliable cross-process delivery**. When the second context lands and listeners need to be guaranteed-delivered (or another service needs the events), this file will document an outbox pattern in a separate plan. Until then, do not assume:

- A listener that fails will be retried automatically. It will not.
- A listener whose writes commit before the emitter's transaction commits will roll back together. They will not, unless the listener participates in the same transaction (which is the default for synchronous Laravel events).
- An event that needs to reach a downstream service will reach it. Today it is consumed only by in-process listeners.

Rule for now: when reliable delivery matters more than convenience, **don't use a domain event** — call a published action synchronously and let the failure surface as an exception the caller can handle. The outbox option arrives when it arrives.

## Context map

Maintained as the project grows. When a new bounded context is introduced, add it to this table along with its relationship to existing contexts.

Relationship vocabulary:

- **Customer–Supplier.** The downstream depends on the upstream's vocabulary; the upstream accommodates the downstream's needs in its public surface.
- **Conformist.** The downstream conforms to the upstream's vocabulary unconditionally; no negotiation.
- **ACL (Anti-corruption layer).** The downstream wraps the upstream's vocabulary at its boundary, translating into its own terms.
- **Shared kernel.** A small, jointly-owned module both contexts depend on (use sparingly).
- **Published language.** A vocabulary the upstream publishes that downstreams adopt verbatim.

| From | → | To | Relationship | Mechanism |
| ---- | - | -- | ------------ | --------- |
| (current contexts and their dependencies will be listed here as they emerge) | | | | |

## See also

- [Architecture § cross-context communication](architecture.md#cross-context-communication) — short summary; this file is the long form.
- [Actions](actions.md) — Application action shape; published actions are the published subset.
- [Transactions](transactions.md) — what is and isn't atomic across a cross-context call.
- [Domain events](domain-events.md) — emitter / listener / payload rules.
- [Read models](http/read-models.md) — the read-side projections published actions and listeners may produce.
- [Anti-patterns](anti-patterns.md) — grep-friendly red flags for cross-context leaks.
- [Glossary](glossary.md) — definitions for *bounded context*, *context map*, *published action*, *published read model*.
