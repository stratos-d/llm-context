# Glossary

**Aggregate.** A cluster of one or more models that share an invariant boundary. Mutated through the aggregate root. See [Models](data/models.md).

**Aggregate root.** The Eloquent model other code references when working with an aggregate. Owns meaningful behavior and invariant checks. See [Models](data/models.md).

**Aggregate part.** A model/entity that exists inside an aggregate root's lifecycle and consistency boundary. Changed through the root, not independently.

**Application action.** A use-case orchestrator in `Application/<UseCase>/`. Loads aggregates, calls aggregate behavior, persists, manages transactions, and coordinates side effects. See [Actions](actions.md).

**Application query.** A read-side use case in `Application/<ContextOrUseCase>/Queries/` that returns read-model DTOs. See [Read models](application/read-models.md).

**Bounded context.** An isolated model with its own language, rules, schema ownership, and contracts. Each `Domains/<Context>/` is a bounded context. See [Architecture](architecture.md).

**Concrete service.** A named wrapper around framework/vendor/external capability, usually in `Infrastructure/<Capability>/<Strategy>/`. See [Services](services.md).

**Context map.** Documentation of how bounded contexts depend on one another and through which mechanism. See [Cross-context communication](cross-context.md).

**CQRS-lite.** Code-level separation between write models/aggregates and read queries/read-model DTOs, without separate read/write databases. See [Read models](application/read-models.md).

**Domain event.** A past-tense fact recorded by an aggregate and dispatched by Application code after persistence/commit. See [Domain events](domain-events.md).

**Domain exception.** A typed business failure thrown by an aggregate or Application action when an invariant or precondition fails. See [Exceptions](exceptions.md).

**Entry point.** A delivery surface such as AdminWeb, API, CLI, queue, or scheduler. Entry points are not bounded contexts. See [Architecture](architecture.md).

**Identity value object.** A value object that identifies an aggregate owned by a bounded context. Published cross-context identity value objects live in `Domains/<Context>/Contracts/`. See [Cross-context communication](cross-context.md).

**Interfaces layer.** Delivery layer under `Interfaces/<EntryPoint>/`: controllers, requests, resources, routes, view models, middleware, providers. See [Architecture](architecture.md).

**Job.** A queued delivery wrapper that calls one Application action. See [Jobs](jobs.md).

**Policy.** Laravel authorization adapter colocated with the aggregate context and invoked at the delivery boundary. Not domain behavior. See [Authorization](authorization.md).

**Port.** An interface owned by the caller when an external capability or multiple implementation boundary is justified. See [Ports and adapters](ports-and-adapters.md).

**Published action.** An Application action deliberately exposed for other bounded contexts to call, marked with `@published`. See [Cross-context communication](cross-context.md).

**Published read model.** An Application query/read-model DTO shape deliberately exposed as a cross-context read contract. See [Cross-context communication](cross-context.md).

**Read model.** A DTO result returned by an Application query for lists, dashboards, reports, or projections. See [Read models](application/read-models.md).

**Repository.** Optional persistence abstraction for loading/saving aggregates when direct Eloquent is insufficient. See [Repositories](data/repositories.md).

**Result object.** A typed immutable object returned by an Application action when the use case has data or outcomes that a primitive cannot communicate clearly. See [Actions](actions.md).

**Transaction root.** The Application action that opens the use-case `DB::transaction()` boundary. See [Transactions](transactions.md).

**Ubiquitous language.** Business terms used consistently within a bounded context.

**Use case.** One goal accomplished by a caller through an Application action.

**Value object.** An immutable object whose equality is by value and which carries validation, identity, or repeated business meaning. See [Value objects](data/value-objects.md).
