# Architecture

> **Owns**
>
> - Modular monolith shape
> - Canonical `app/` folder tree
> - Layer responsibilities
> - Bounded context and aggregate vocabulary
> - Request/write/read flow diagrams
>
> **Forbids**
>
> - Per-class skeletons — see topic files
> - Naming style — see [Conventions](conventions.md)
> - Port wiring details — see [Ports and adapters](ports-and-adapters.md)
>
> **See also**: [Philosophy](philosophy.md), [Actions](actions.md), [Models](data/models.md), [Read models](application/read-models.md), [Cross-context communication](cross-context.md), [Transactions](transactions.md)

This file is the source of truth for where code lives and which layer can call which other layer.

## Project shape

The application is a **Laravel modular monolith with bounded contexts**: one deployable application with internal boundaries strong enough that a context could later be extracted, but without microservice ceremony today.

- `Application/` owns use cases, use-case input DTOs, query use cases, filters, read models, jobs, and result DTOs.
- `Domains/` owns bounded-context language, aggregate roots/parts, internal value objects, published identity contracts, domain events, exceptions, and policies colocated with the context.
- `Interfaces/` owns delivery: controllers, requests, resources, view models, middleware, providers, and routes per entry point.
- `Infrastructure/` owns framework/vendor integration and concrete external capabilities.

## Bounded contexts

A bounded context is defined by the language and rules it owns, not by who uses it.

Admin UI, business UI, public API, CLI commands, jobs, and scheduled tasks are **entry points**, not domain boundaries. They may reuse the same Application and Domain code when they operate on the same business concept.

Each `Domains/<Context>/` owns one model of the business. A context may reference another context by identity/value object, but it does not own or mutate another context's aggregate.

## Aggregates

An aggregate is the cluster of models that share an invariant boundary. The aggregate root is the Eloquent model other code references when talking to that aggregate.

```text
Domains/Orders/Models/Order.php          ← aggregate root
Domains/Orders/Models/OrderLine.php      ← aggregate part
```

Do not create a separate bounded context for an aggregate part unless it has its own lifecycle, identity, language, and business rules.

## Folder layout

```text
app/
├── Application/
│   └── <UseCaseOrContext>/
│       ├── Actions/
│       │   └── <Verb><Noun>Action.php       ← use-case write orchestration
│       ├── Jobs/
│       │   └── <Verb><Noun>Job.php          ← thin queued wrapper around an action
│       ├── Filters/
│       │   └── <Noun>Filter.php             ← pure filter/input DTOs
│       ├── ReadModels/                      ← cross-context readers live here
│       │   ├── <UseCase>Reader.php          ← read surface (queries grouped)
│       │   ├── <Noun>Row.php
│       │   └── <Noun>Page.php
│       ├── Contracts/                       ← only when an interface is justified
│       ├── Inputs/
│       │   └── <Verb><Noun>Input.php        ← pure Application input DTO, when useful
│       ├── <Verb><Noun>Action.php           ← acceptable for small use-case folders
│       ├── <Verb><Noun>Input.php            ← acceptable for small use-case folders
│       └── <Verb><Noun>Result.php           ← when justified
│
├── Domains/
│   ├── DomainException.php                  ← abstract root for domain failures
│   └── <Context>/
│       ├── Builders/                        ← reusable same-context Eloquent constraints
│       ├── Contracts/                       ← published contracts, identity VOs, ports when justified
│       ├── Database/
│       │   ├── Factories/
│       │   ├── Migrations/
│       │   └── Seeders/
│       ├── Enums/
│       ├── Events/
│       ├── Exceptions/
│       ├── Listeners/
│       ├── Models/                          ← aggregate roots and aggregate parts
│       ├── Policies/
│       └── ValueObjects/                    ← internal context vocabulary
│
├── Interfaces/
│   └── <EntryPoint>/                        ← AdminWeb, PartnerApi, PublicWeb, Console, …
│       ├── Controllers/
│       ├── Middleware/
│       ├── Providers/
│       ├── Requests/
│       ├── Resources/
│       ├── Routes/
│       └── ViewModels/
│
├── Infrastructure/
│   ├── Eloquent/
│   │   ├── Models/
│   │   ├── Builders/
│   │   └── Concerns/
│   ├── Auth/
│   ├── Cache/
│   ├── External/
│   ├── Mail/
│   └── Support/
│
└── Providers/
```

Create folders only when there are files to put in them.

## Layer responsibilities

| Layer | Lives at | Owns | Must not do |
|---|---|---|---|
| Controller | `Interfaces/<EntryPoint>/Controllers/` | Translate delivery input into Application calls and shape delivery output | Business rules, direct writes, complex queries |
| Request data | `Interfaces/<EntryPoint>/Requests/` | Validate, authorize caller/resource when appropriate, expose typed input | Persistence, mutation, query execution |
| Resource/View model | `Interfaces/<EntryPoint>/Resources`, `ViewModels` | Response/page-prop shaping | Business behavior, writes |
| Application action/input | `Application/<UseCase>/` | Write use case orchestration, use-case input DTOs, transaction boundary, persistence coordination | HTTP/session/Inertia concerns, request data classes, owning aggregate invariants |
| Context reader (read model) | `Domains/<Context>/ReadModels/` (single-context), `Application/<UseCase>/ReadModels/` (cross-context) | The context's read surface: tuned queries returning DTOs/scalars/booleans, including authorization-decision support | Writes, business mutation, request objects |
| Repository | `Infrastructure/Eloquent/Repositories/<Aggregate>/` | Aggregate load-to-mutate and save (the only write-side query home) | Screen/list queries (that is a reader), use-case orchestration |
| Aggregate root/model | `Domains/<Context>/Models/` | State, casts, relationships, meaningful behavior, invariant protection | Saving itself, framework delivery concerns, cross-context model imports |
| Builder | `Domains/<Context>/Builders/` | Reusable same-context Eloquent constraints | Writes, request parsing, response shaping |
| Domain event | `Domains/<Context>/Events/` | Published fact that happened in a context | Eloquent models in payloads, side effects |
| Policy | `Domains/<Context>/Policies/` | Laravel authorization adapter colocated with the aggregate context | Business mutation, persistence, transactions, HTTP/session/request helper calls |
| Concrete service | `Infrastructure/<Capability>/<Strategy>/` | Framework/vendor/external capability implementation | Business rules, hidden persistence |
| Port/interface | `Application/.../Contracts` or `Domains/<Context>/Contracts` | External capability or published contract when justified | Implementation |
| Job | `Application/<UseCase>/Jobs/` | Queue delivery wrapper around one Application action | Business logic in `handle()`, Eloquent model payloads |

## Write flow

```text
Route / CLI / Queue
    -> Request data / command payload
        -> Controller / Command / Job
            -> Application Action
                -> Aggregate behavior
                -> Persistence
                -> Domain-event dispatch after persistence/commit
```

## Read flow

```text
Route
    -> Request data validates input
        -> Controller builds pure filter/input DTO
            -> Application Query
                -> ReadModel DTO result
                    -> Resource / ViewModel / response
```

## Cross-context communication

A bounded context never imports another context's Eloquent models or mutates another context's tables as if they were its own. Use:

- identity/value objects for references;
- domain events for reactions;
- published Application actions for synchronous capabilities;
- published read models for query-shaped data.

See [Cross-context communication](cross-context.md).

## See also

- [Actions](actions.md) — write use cases.
- [Models](data/models.md) — aggregate roots and behavior.
- [Read models](application/read-models.md) — query side.
- [Transactions](transactions.md) — commit boundaries.
- [Cross-context communication](cross-context.md) — context collaboration.
- [Anti-patterns](anti-patterns.md) — boundary violations.
