# Philosophy

> **Owns**
>
> - The project's architectural stance
> - Which DDD ideas are adopted pragmatically
> - The compromise between Laravel ergonomics and domain boundaries
>
> **Forbids**
>
> - Per-layer skeletons — see the relevant topic file
> - Strict or academic DDD rules that add ceremony without protecting behavior
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Models](data/models.md), [Ports and adapters](ports-and-adapters.md), [Glossary](glossary.md)

Read this file before any other. Every other doc assumes the stance below.

## What this project is

**A Laravel modular monolith with bounded contexts, Application-layer use cases, Eloquent aggregate roots, and pragmatic DDD boundaries.**

That sentence is the single self-description:

- Laravel remains the implementation platform; Eloquent models are allowed to be aggregate roots.
- `Application/` owns use cases and orchestration.
- `Domains/` owns business language, aggregate state, meaningful behavior, invariants, domain events, and domain exceptions.
- `Interfaces/` owns delivery: HTTP, CLI, jobs as dispatchers, routes, request/response shaping.
- `Infrastructure/` owns framework/vendor integration details and concrete external capabilities.

## What this project is not

- It is not a textbook DDD codebase. It does not require repositories, interfaces, specifications, or domain events everywhere.
- Important business behavior belongs on aggregate roots, not scattered through controllers, services, or use-case scripts.
- It is not a CRUD app dressed up in DDD vocabulary. Bounded contexts are real and enforced.
- It is not "whatever Laravel allows." Fat controllers, cross-context model imports, hidden writes in services, and request objects leaking into Application or Domain code are rejected.

## Core flow

Writes follow one consistent shape:

```text
Controller / Command / Job
    -> Application Action
        -> Aggregate / Domain model behavior
            -> Persistence
```

Reads follow a separate shape:

```text
Controller
    -> validated request data
        -> pure filter/input DTO
            -> Application Query
                -> ReadModel DTO result
                    -> Resource / ViewModel / response
```

Simple field updates may stay simple inside an Application action when no meaningful invariant is present. Meaningful business operations should be named as aggregate methods.

```php
// Simple, low-risk field update inside the Application action is acceptable.
$employee->display_name = $input->displayName;
$employee->saveOrFail();
```

```php
// Meaningful lifecycle behavior belongs on the aggregate.
$order->cancel($cancelledBy);
$order->saveOrFail();
```

## What we adopt from DDD

### Strategic

- **Bounded contexts.** Each `Domains/<Context>/` owns one language, one model, and one set of rules.
- **Ubiquitous language.** Names in code mirror the words domain experts use. The same word may mean different things in different contexts.
- **Context boundaries.** Contexts reference each other through identity value objects, published Application capabilities, published read models, or domain events — not through foreign Eloquent models.

### Tactical, when justified

- **Aggregates.** Eloquent models can be aggregate roots. Aggregate roots protect meaningful behavior and invariants.
- **Value objects.** Use them when a primitive carries validation, identity, comparison, or repeated business meaning.
- **Domain exceptions.** Aggregates and Application actions throw typed domain exceptions when invariants or preconditions fail.
- **Domain events.** Aggregates may record events that Application code dispatches after persistence/commit.
- **Ports/adapters.** Introduce interfaces for external capabilities or when multiple implementations genuinely exist. Do not wrap every internal class.

## What stays pragmatic

- Repositories are optional, not default. Use them when they hide aggregate persistence details or return non-Eloquent aggregates.
- Interfaces are optional, not default. Use concrete services until substitution or external-boundary isolation earns the abstraction.
- Domain events are optional, not default. Use them when another part of the system reacts to something that happened.
- Value objects are optional, not default. Promote primitives when the primitive no longer carries enough meaning safely.
- Simple field edits are allowed in Application actions. Do not invent aggregate methods for every setter.

## Non-negotiable boundaries

- **Application actions orchestrate; aggregates protect behavior.** Important business rules do not live in controllers, jobs, resources, or random services.
- **Aggregates do not persist themselves.** Aggregate methods mutate in-memory state only. Persistence happens in the Application action or repository.
- **Domain code does not import HTTP, sessions, guards, tokens, requests, resources, Inertia, or controllers.**
- **Cross-context references use identity/value objects or published contracts, not foreign Eloquent models.**
- **Cross-context database foreign keys are avoided by default.** Same-context foreign keys remain fine.
- **Read models and queries live in Application by default.** HTTP owns request/response concerns; Domain owns behavior.
- **Tests are written alongside behavior.** Invariants, orchestration, query shape, and delivery mapping should be programmatically checked.

## How to use this file

If topic files appear to conflict, resolve in this order:

1. This file for architectural stance.
2. [Architecture](architecture.md) for layer placement.
3. The topic file for skeletons and detailed rules.
4. [Anti-patterns](anti-patterns.md) for grep-friendly violations.

## See also

- [Architecture](architecture.md) — layout and layer responsibilities.
- [Actions](actions.md) — Application use-case orchestration.
- [Models](data/models.md) — aggregate roots and aggregate behavior.
- [Read models](application/read-models.md) — Application-layer query side.
- [Ports and adapters](ports-and-adapters.md) — external capability boundaries.
- [Glossary](glossary.md) — definitions for project terms.
