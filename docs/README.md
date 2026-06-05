# Project documentation

This directory holds the project's architectural and operational guidelines. Each file owns a single topic; rules are defined in exactly one place and cross-linked elsewhere.

If you are a human contributor or an AI agent arriving cold, start with the **Reading order** below. Each topic file opens with a short *owns / forbids / see also* block so a 10-second skim is enough to know whether you are in the right place.

## By topic

### Stance

- [Philosophy](philosophy.md) — the project's self-description, which DDD ideas are adopted and which are not, the pragmatic compromises and why each was made. **Read this first.**

### Foundation

- [Conventions](conventions.md) — language-level rules (`strict_types`, `final` / `abstract`, namespace = folder, naming style).
- [Architecture](architecture.md) — the canonical `app/` folder tree, the layer-responsibilities table, bounded contexts, aggregates, the request flow.
- [Adding a context](adding-a-context.md) — the recipe for adding a new bounded context: folders, minimum files, wiring, "context is done" checklist, worked `Notes` example.
- [Cross-context communication](cross-context.md) — how bounded contexts collaborate: domain events, published actions, published read models, the context map, current honesty about reliable delivery.
- [Authorization](authorization.md) — where Policies live (per-aggregate, auto-discovered), where authorization is invoked (delivery boundary), the split between caller-level / resource-level / business-rule.
- [Exceptions](exceptions.md) — the `DomainException` hierarchy, throw / catch policy, mapping to HTTP / Inertia in `bootstrap/app.php`.
- [Ports and adapters](ports-and-adapters.md) — when to introduce an interface and when not to; strategy-prefix naming for variant-anticipated services; container wiring.

### Frontend

- [Frontend structure](frontend/structure.md) — canonical `resources/js` entry-point boundaries and frontend layout.

### Data layer

- [Models](data/models.md) — `BaseModel`, `BaseAuthenticatable`, concrete model wiring, attributes, anaemic-model framing, behaviour-promotion rule.
- [Value objects](data/value-objects.md) — when to introduce one (three-occurrence rule), skeleton, Eloquent cast pattern.
- [Builders](data/builders.md) — `BaseBuilder`, when to introduce a custom builder, filter naming convention.
- [Repositories](data/repositories.md) — opt-in, the four triggers, port + adapter layout. Default read mechanism is the builder.
- [Factories](data/factories.md) — factory location, the project's `HasFactory` trait, named-state convention.

### HTTP layer

- [Routes](http/routes.md) — file split per entry point, URL nesting rule, `<resource>.<verb>` naming convention.
- [Controllers](http/controllers.md) — controller skeleton, single-action vs resource style, what does not belong in a controller.
- [Request data](http/request-data.md) — request-backed `Data` objects, validation attributes, `authorize()` vs policies.
- [View data](http/view-data.md) — Inertia page-prop shaping for single-entity details and multi-source pages.
- [Read models](http/read-models.md) — list / table / dashboard / report queries; CQRS-lite split between aggregates and projections.

### Write side

- [Actions](actions.md) — placement test, Domain action vs Application action, `execute()` signature, result objects, what an action can / cannot call.
- [Transactions](transactions.md) — the sole-root rule: only Application actions open `DB::transaction()`. When to wrap, when not to, no nesting, cross-context behaviour.
- [Jobs](jobs.md) — queued use cases as thin wrappers; payload rules; `afterCommit` discipline; listener-vs-job split.
- [Domain events](domain-events.md) — naming, payload rules, who emits, who listens. The mechanism for cross-context propagation and cross-cutting concerns.
- [Services](services.md) — concrete service classes wrapping framework / vendor SDKs; statelessness rule, strategy prefix.

### Quality

- [Anti-patterns](anti-patterns.md) — grep-friendly red-flag tables for layer leaks, framework-coupling, preemptive abstraction, action / result shape, routing, cross-context leaks. Plus a worked refactor example.
- [Testing](testing.md) — test discipline, commands, substituting collaborators in tests, PR / merge checklist.

### Reference

- [Glossary](glossary.md) — flat A–Z definitions for every DDD-flavoured term used across the docs, with links to the file that owns each rule.

## Reading order

For a contributor unfamiliar with the project's conventions, read in this order. The progression starts with the architectural stance, then moves through language rules, layer layout, the boundary-crossing pattern, and outward through the layers in roughly the same direction the request flow runs.

1. [Philosophy](philosophy.md)
2. [Conventions](conventions.md)
3. [Architecture](architecture.md)
4. [Adding a context](adding-a-context.md)
5. [Actions](actions.md)
6. [Transactions](transactions.md)
7. [Cross-context communication](cross-context.md)
8. [Authorization](authorization.md)
9. [Exceptions](exceptions.md)
10. [Ports and adapters](ports-and-adapters.md)
11. [Services](services.md)
12. [Jobs](jobs.md)
13. [Frontend structure](frontend/structure.md)
14. [Models](data/models.md)
15. [Value objects](data/value-objects.md)
16. [Builders](data/builders.md)
17. [Repositories](data/repositories.md)
18. [Factories](data/factories.md)
19. [Routes](http/routes.md)
20. [Request data](http/request-data.md)
21. [Controllers](http/controllers.md)
22. [View data](http/view-data.md)
23. [Read models](http/read-models.md)
24. [Domain events](domain-events.md)
25. [Anti-patterns](anti-patterns.md)
26. [Testing](testing.md)

[Glossary](glossary.md) is a reference, not a reading-order entry; consult it when a term is unfamiliar.

## For agents

- This file is the high-signal entry point for the project's reusable guidance. Tool-specific agent files such as `AGENTS.md` are intentionally not committed here.
- When making code changes, the layer rules in [Architecture § layer responsibilities](architecture.md#layer-responsibilities) and the signals in [Anti-patterns § red flags](anti-patterns.md#red-flags) are the two tables to keep in mind.

## Single-source-of-truth invariant

Every rule in these docs is owned by exactly one file. If you find the same rule defined in two places, that is a bug — fix the duplicate by deleting it and replacing it with a link to the owner.
