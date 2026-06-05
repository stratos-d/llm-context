---
name: llm-context-backend
description: Use when changing backend architecture, data layer, HTTP layer, actions, transactions, jobs, policies, domain events, services, or tests.
---

# Backend LLM Context

Use this skill before making backend structural changes. Read only the files that match the current task.

## Start Here

- `docs/llm-context/philosophy.md` — architectural stance, DDD tradeoffs, and why the model is intentionally pragmatic.
- `docs/llm-context/architecture.md` — folder layout, layers, bounded contexts, aggregates, and request flow.

## Topic Map

- `docs/llm-context/adding-a-context.md` — adding a new bounded context end-to-end.
- `docs/llm-context/actions.md` — domain actions vs application actions, placement rules, result objects, and write orchestration.
- `docs/llm-context/transactions.md` — transaction ownership, when to wrap work, and the no-nested-transaction rule.
- `docs/llm-context/authorization.md` — policy placement and where authorization checks belong.
- `docs/llm-context/exceptions.md` — domain exception hierarchy, throw/catch policy, and delivery-layer mapping.
- `docs/llm-context/jobs.md` — queued use cases, payload rules, `afterCommit`, and listener-vs-job split.
- `docs/llm-context/domain-events.md` — event naming, payload rules, emitters, listeners, and cross-context propagation.
- `docs/llm-context/services.md` — concrete service wrappers around framework/vendor SDKs.
- `docs/llm-context/cross-context.md` — collaboration between bounded contexts.
- `docs/llm-context/ports-and-adapters.md` — when to introduce interfaces and adapter boundaries.
- `docs/llm-context/testing.md` — test discipline, collaborator substitution, and merge checklist.
- `docs/llm-context/anti-patterns.md` — grep-friendly red flags for layer leaks and architectural drift.

## Data Layer

- `docs/llm-context/data/models.md` — base models, aggregate roots/parts, casts, relationships, and model boundaries.
- `docs/llm-context/data/value-objects.md` — when to introduce value objects and how to wire casts.
- `docs/llm-context/data/builders.md` — custom Eloquent builders and reusable read filters.
- `docs/llm-context/data/repositories.md` — repository trigger rules and port/adapter layout.
- `docs/llm-context/data/factories.md` — factory location, auto-resolution, and named states.

## HTTP Layer

- `docs/llm-context/http/routes.md` — route file split, URL nesting, and route naming.
- `docs/llm-context/http/controllers.md` — controller placement, shape, and what must not live in controllers.
- `docs/llm-context/http/request-data.md` — request-backed input data and validation boundaries.
- `docs/llm-context/http/view-data.md` — Inertia/page-prop shaping for delivery responses.
- `docs/llm-context/http/read-models.md` — list/table/dashboard/report queries and CQRS-lite read models.
