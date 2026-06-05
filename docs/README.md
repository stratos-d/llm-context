# Project Documentation

This directory holds reusable architectural and operational guidance for Laravel modular monolith applications. Each file owns one topic; rules should be defined in one place and linked elsewhere.

## By Topic

### Stance

- [Philosophy](philosophy.md) — architectural stance, pragmatic DDD tradeoffs, and non-negotiable boundaries. **Read this first.**

### Foundation

- [Conventions](conventions.md) — language-level rules, naming, time, comments, IDs, and PHPDoc discipline.
- [Architecture](architecture.md) — folder layout, layer responsibilities, bounded contexts, aggregates, write/read flows.
- [Adding a context](adding-a-context.md) — recipe for adding a bounded context end-to-end.
- [Cross-context communication](cross-context.md) — identity references, published actions/read models, domain events, cross-context FK posture.
- [Authorization](authorization.md) — policy placement and delivery-boundary authorization rules.
- [Exceptions](exceptions.md) — domain exception hierarchy and mapping.
- [Ports and adapters](ports-and-adapters.md) — when to introduce interfaces and adapter boundaries.

### Domain And Data

- [Models](data/models.md) — Eloquent aggregate roots/parts, meaningful behavior, persistence boundary.
- [Value objects](data/value-objects.md) — value-object and identity-object rules.
- [Builders](data/builders.md) — reusable same-context Eloquent constraints.
- [Repositories](data/repositories.md) — required-when-used aggregate persistence (write side).
- [Factories](data/factories.md) — factory location and conventions.

### Application

- [Actions](actions.md) — Application use-case orchestration.
- [Transactions](transactions.md) — use-case transaction ownership.
- [Jobs](jobs.md) — queued use-case delivery.
- [Domain events](domain-events.md) — event naming, payloads, aggregate recording, dispatch timing.
- [Services](services.md) — concrete service wrappers around framework/vendor SDKs.
- [Read models](application/read-models.md) — the `{Context}Reader` read surface, the data-access rule, filters, and projection DTOs.

### HTTP / Delivery

- [Routes](http/routes.md) — route file split, URL nesting, route naming.
- [Controllers](http/controllers.md) — controller placement and shape.
- [Request data](http/request-data.md) — request-backed input validation and authorization.
- [View data](http/view-data.md) — resource/view-model response shaping.
- [Frontend structure](frontend/structure.md) — frontend entry-point boundaries.

### Quality

- [Anti-patterns](anti-patterns.md) — grep-friendly red flags for architectural drift.
- [Testing](testing.md) — test discipline and layer-aligned coverage.
- [Glossary](glossary.md) — definitions for terms used across the docs.

## Reading Order

1. [Philosophy](philosophy.md)
2. [Architecture](architecture.md)
3. [Models](data/models.md)
4. [Actions](actions.md)
5. [Transactions](transactions.md)
6. [Cross-context communication](cross-context.md)
7. [Read models](application/read-models.md)
8. [Authorization](authorization.md)
9. [Exceptions](exceptions.md)
10. [Domain events](domain-events.md)
11. [Ports and adapters](ports-and-adapters.md)
12. [Jobs](jobs.md)
13. [Controllers](http/controllers.md)
14. [Request data](http/request-data.md)
15. [View data](http/view-data.md)
16. [Anti-patterns](anti-patterns.md)
17. [Testing](testing.md)

## For Agents

- This file is the high-signal entry point for reusable guidance.
- Read only the topic files relevant to the current task.
- For backend structural work, the most important files are [Architecture](architecture.md), [Models](data/models.md), [Actions](actions.md), and [Anti-patterns](anti-patterns.md).

## Single-Source-Of-Truth Invariant

Every rule should be owned by exactly one file. If the same rule is defined in two places, keep the owner and replace duplicates with links.
