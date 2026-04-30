# Architecture

> **Owns**
>
> - The shape of the project (modular monolith with bounded contexts)
> - The canonical `app/` folder tree
> - The layer responsibilities table
> - The aggregate / aggregate-root vocabulary applied to Eloquent models
> - Where actions live (Domain vs Application — use-case-vs-aggregate-command criterion)
> - The request-flow diagram
>
> **Forbids**
>
> - Per-class skeletons — see the relevant topic file
> - Naming style and language rules — see [Conventions](conventions.md)
> - Port and adapter wiring details — see [Ports and adapters](ports-and-adapters.md)
>
> **See also**: [Philosophy](philosophy.md), [Conventions](conventions.md), [Ports and adapters](ports-and-adapters.md), [Actions](actions.md), [Cross-context communication](cross-context.md), [Transactions](transactions.md), [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md), [Models](data/models.md), [README](README.md)

This file is the single source of truth for **where things live** and **which layer can call which other layer**. Every other doc assumes the layout below. The architectural stance behind these layers is described in [Philosophy](philosophy.md); this file is the layout that materialises that stance.

> **A note on naming.** This project uses three top-level folder names that are easy to conflate: **`Application/`**, **`Domains/`**, **`Interfaces/`**. Each has a precise meaning below and **none of them refer to PHP language keywords**:
>
> - `Application/` is the **application layer** (use-case orchestration). Not PHP's `Application::class` anything.
> - `Domains/` is the per-**bounded-context** folder. Not a DDD generic "domain layer" — the project's bounded contexts live directly here.
> - `Interfaces/` is the **delivery layer** (driving-adapter code: controllers, requests, view models, routes, middleware, providers). It comes from the hexagonal-architecture use of "user-side interfaces"; it is **not** PHP `interface` declarations. PHP interfaces (ports) live with the capability that needs them (`Application/<UseCase>/Contracts/` or `Domains/<X>/Contracts/`) — see [Ports and adapters](ports-and-adapters.md).
>
> The names are kept because renaming is a large refactor with no offsetting benefit once the disambiguation is known. New contributors should read this box once and then trust the folders.

## Project shape

The application is a **modular monolith with bounded contexts**: one Laravel application with internal boundaries strong enough that any context could later be extracted, but no microservice or service-mesh complexity until that day arrives.

- One deployable, one database, one HTTP entry.
- Each `Domains/<X>/` is a **bounded context**: an isolated model with its own ubiquitous language, its own schema, and its own rules. Cross-context references go through events, application actions, or published contracts — never through model imports or foreign keys to another context's tables.
- Cross-context workflows live in `Application/<UseCase>/`, not inside any one context.
- Every external boundary (auth, mail, notifications, search APIs, etc.) is crossed through a **concrete service** in `Infrastructure/<Capability>/<Strategy>/`, with an interface (port) introduced only when justified — see [Ports and adapters](ports-and-adapters.md).
- Infrastructure concerns (concrete services, base Eloquent classes, framework-agnostic helpers) are isolated under `Infrastructure/`.

Extraction is a **future option**, not a current goal. Premature service splitting is rejected.

### Bounded contexts and aggregates

Within a bounded context, the cluster of one or more models that share an invariant boundary is an **aggregate**. The model code references when it talks about an aggregate is the **aggregate root** — the Eloquent model in `Domains/<X>/Models/<Name>.php`. Aggregates are loaded and saved as a unit through the root.

Operational test for whether something is its own bounded context: *if two teams could maintain it independently with only a written contract between them, it is a bounded context. If splitting them would require constant cross-team coordination, you have one context, not two.*

Folder convention: the `Domains/` name is retained for backwards compatibility; treat each `Domains/<X>/` as a bounded context. See [Glossary](glossary.md) for the term.

## Folder layout

```text
app/
├── Application/
│   └── <UseCase>/
│       ├── Contracts/                   ← only when an interface is justified
│       │   └── <ServiceName>.php
│       ├── Jobs/                        ← queued use cases (thin wrappers)
│       │   └── <Verb><Noun>Job.php
│       ├── <Verb><Noun>Action.php       ← use-case actions
│       ├── <Verb><Noun>Result.php       ← typed result objects, when justified
│       └── <ConceptName>.php            ← DTOs / value objects (no suffix)
│
├── Domains/
│   ├── DomainException.php             ← abstract project-wide root for domain failures
│   └── <ContextName>/                   ← each folder is a bounded context
│       ├── Actions/
│       │   └── <Verb><Noun>Action.php   ← aggregate-command actions
│       ├── Builders/
│       │   └── <ModelName>Builder.php   ← reusable read filters / orderings
│       ├── Concerns/
│       │   └── <PureTrait>.php          ← read-only / state-helper traits only
│       ├── Contracts/                   ← only when an interface is justified
│       │   └── <ServiceName>.php
│       ├── Scopes/
│       │   └── <ScopeName>.php          ← global scopes (e.g. ExcludeDisabledScope)
│       ├── Database/
│       │   ├── Factories/
│       │   │   └── <ModelName>Factory.php
│       │   ├── Migrations/
│       │   └── Seeders/
│       ├── Enums/
│       │   └── <Name>.php
│       ├── Events/
│       │   └── <NounVerbedPastTense>.php ← domain events emitted from this context
│       ├── Exceptions/                  ← only when this context throws domain exceptions
│       │   ├── <ContextName>Exception.php  ← per-context abstract base
│       │   └── <Name>.php               ← concrete failure types
│       ├── Listeners/                   ← handlers for events this context reacts to
│       │   └── <Verb><Noun>Listener.php
│       ├── Models/
│       │   └── <ModelName>.php           ← aggregate roots and aggregate parts
│       ├── Policies/                    ← per-aggregate authorization (Laravel auto-discovered)
│       │   └── <Aggregate>Policy.php
│       └── ValueObjects/
│           └── <Name>.php
│
├── Interfaces/
│   └── <EntryPoint>/                    ← AdminWeb, PartnerApi, PublicWeb, …
│       ├── Controllers/
│       │   └── <Group>/
│       │       └── <Name>Controller.php
│       ├── Middleware/
│       │   └── <Name>.php
│       ├── Providers/                   ← only when bindings are needed
│       │   └── <EntryPoint>ServiceProvider.php   ← composition root for the entry point
│       ├── Requests/
│       │   └── <Group>/
│       │       └── <Name>Data.php
│       ├── Resources/
│       │   └── <Name>Resource.php
│       ├── Routes/
│       │   └── <name>.php               ← e.g. auth.php, profile.php
│       └── ViewModels/
│           └── <Page>ViewModel.php
│
├── Infrastructure/
│   ├── Eloquent/
│   │   ├── Models/
│   │   │   ├── BaseModel.php
│   │   │   └── BaseAuthenticatable.php
│   │   ├── Builders/
│   │   │   └── BaseBuilder.php
│   │   └── Concerns/
│   │       └── HasFactory.php
│   │
│   ├── Auth/                            ← concrete services for auth/identity
│   │   ├── Session/
│   │   │   └── Session<ServiceName>.php
│   │   └── Token/
│   │       └── Token<ServiceName>.php
│   │
│   ├── External/                        ← concrete services for remote I/O
│   │   └── <Capability>/                ← Search, Notifications, Webhooks, …
│   │       └── <Strategy><ServiceName>.php
│   │
│   ├── Mail/
│   │   └── <Strategy>Mailer.php
│   │
│   ├── Cache/
│   │   └── <Strategy>DomainCache.php
│   │
│   └── Support/
│       └── <UtilityName>.php            ← framework-agnostic helpers
│
└── Providers/
    ├── AppServiceProvider.php           ← default bindings (sparingly)
    └── DomainServiceProvider.php        ← per-context bindings, route loading
```

Notes:

- **No `Http/` folder inside `Domains/`.** Delivery (controllers, requests, resources, routes, middleware, providers) lives at `Interfaces/<EntryPoint>/`. A domain that knows about HTTP is a leaky domain.
- **No `Services/` folder inside `Domains/`.** External capabilities are concrete services in `Infrastructure/<Capability>/<Strategy>/`. When an interface is justified (multiple implementations coexisting), it lives in `Application/<UseCase>/Contracts/` or `Domains/<X>/Contracts/`. See [Ports and adapters](ports-and-adapters.md).
- **No `Actions/` folder inside `Interfaces/<EntryPoint>/`.** Delivery-coupled writes (web-guard login, token issuing, cookie regeneration) are entry-point-specific concrete services in `Infrastructure/`, not actions. See [Actions § where delivery-coupled writes go](actions.md#where-delivery-coupled-writes-go).
- **Each entry point in `Interfaces/`** owns its own `Controllers/`, `Middleware/`, `Requests/`, `Resources/`, `ViewModels/`, and `Routes/`. `AdminWeb` (Inertia / session) and `PartnerApi` (token / JSON) cannot share a controller, request data object, view model, or response shape — they share **Application actions** (and, when justified, interfaces with multiple implementations).
- **An entry point's `Providers/` folder exists only when there are bindings to register.** Don't create empty providers; Laravel auto-resolves concrete classes.
- `Infrastructure/Eloquent/{Models, Builders, Concerns}` mirrors Laravel's own `Illuminate\Database\Eloquent\{Models, Builders, Concerns}` layout.
- A context folder need not contain every subfolder. Create a subfolder only when you have a file to put in it.

### `Infrastructure/Support/` — shared-kernel rule

`Infrastructure/Support/` is the only folder where framework-agnostic, **business-meaning-free** helpers may live. It is also the most common dumping ground in any growing codebase, so the rule is strict.

**Allowed**: pure helpers with no domain meaning — generic functional utilities, small adapters around language gaps, things that could plausibly ship as a separate library. Example shapes (illustrative): a `Result<T,E>` sum type, a `Stringable` decorator, a small assertion helper.

**Forbidden**: anything that names a thing in the business. `Email`, `EmployeeId`, `ActivityEvent`, `PendingLogin` — those carry meaning specific to a context and live with the context that owns them (`Domains/<X>/ValueObjects/`) or with the use case that introduces them (`Application/<UseCase>/`).

**Promotion rule**: when a class in `Infrastructure/Support/` acquires a domain meaning over time — its name shows up in conversations about *what the system does*, not *how it does things mechanically* — move it into the appropriate `Domains/<X>/` or `Application/<UseCase>/` folder. Folder accretion is rejected at review.

If you're unsure whether something belongs in `Support/` or in a domain, the operational test is: *if a domain expert read the class name, would they understand what it does?* If yes, it has business meaning and does not belong in `Support/`.

## Layer responsibilities

Each row defines what one layer *owns* and what it is *forbidden* from doing. A change that crosses these boundaries either belongs in another layer or means a missing layer needs to be introduced.

| Layer | Lives at | Responsibility | Forbidden |
| ----- | -------- | -------------- | --------- |
| Controller | `Interfaces/<EntryPoint>/Controllers/` | Translate HTTP → Application action + port calls → HTTP response | Queries, writes, business rules, framework-auth/session/mail facade calls |
| Request data | `Interfaces/<EntryPoint>/Requests/` | Validate + authorize + type the inbound request | Persistence, mutation, calling actions |
| Resource | `Interfaces/<EntryPoint>/Resources/` | Shape one model → one response payload | Multi-model assembly (use a view model), business rules |
| View model | `Interfaces/<EntryPoint>/ViewModels/` | Compose multiple sources into one Inertia page-prop shape | Business rules, persistence |
| Provider | `Interfaces/<EntryPoint>/Providers/` | Bind interfaces to concrete classes when binding is needed (composition root) | Business rules, runtime branching; existing without bindings to register |
| Application action | `Application/<UseCase>/` | Own a use case end-to-end; compose Domain actions; call concrete services or interfaces; **open the transaction** (sole transaction root, see [Transactions](transactions.md)); return result/bool/void | HTTP concerns; Inertia; framework-auth/session/mail calls; Interface code |
| Domain action | `Domains/<X>/Actions/` | Own one aggregate command (invariant-protecting state mutation) | Cross-context calls; HTTP; framework-coupled delivery work; **opening `DB::transaction()`** (the Application action does that) |
| Builder | `Domains/<X>/Builders/` | Reusable read filters / orderings | Writes of any kind |
| Aggregate (Model) | `Domains/<X>/Models/` | State, casts, relationships, derived-state helpers | Writes (`save()`, `update()`, `forceFill()->save()`); reaching into other contexts |
| Global scope | `Domains/<X>/Scopes/` | Default-safe queries (e.g. exclude disabled employees) | Query mutation outside the documented opt-out points |
| Port (interface) | `Application/<UseCase>/Contracts/` or `Domains/<X>/Contracts/` | Describe a capability in the project's vocabulary, **only when ≥2 implementations coexist** | Implementation; living in `Infrastructure/` |
| Concrete service | `Infrastructure/<Capability>/<Strategy>/` | Encapsulate a composite or named operation against the framework / a vendor SDK | Business rules; cross-context calls; persistence as a side concern |
| Policy | `Domains/<X>/Policies/<Aggregate>Policy.php` | Per-aggregate authorization decisions (Laravel auto-discovered); see [Authorization](authorization.md) | Reading HTTP / session / request scope; deciding business rules (those live in the Application action) |
| Domain exception | `Domains/<X>/Exceptions/<Name>.php` | Typed failure thrown by a Domain or Application action when an invariant or precondition is violated; see [Exceptions](exceptions.md) | HTTP knowledge; framework types; being caught in the controller (the central handler maps) |
| Listener | `Domains/<X>/Listeners/<Verb><Noun>Listener.php` | Synchronous handler in the **reacting** context for an event from another context | Mutating state in the emitting context; doing non-trivial async work inline (dispatch a Job instead) |
| Job | `Application/<UseCase>/Jobs/<Verb><Noun>Job.php` | Thin wrapper that delivers an Application action via the queue; see [Jobs](jobs.md) | Business logic in `handle()`; Eloquent models in the payload; living anywhere outside `Application/` |

### Request flow

```text
Frontend page (Inertia)
  ↓ submits to
Route  →  Request data  →  Controller  ┐
                                       ├→  Application action  →  Domain action  →  Aggregate / Builder
                                       │             ↓
                                       │         Concrete service in Infrastructure/  (or interface, when justified)
                                       │             ↓
                                       │         persistence / event / external I/O
                                       └→  Concrete service (e.g. SessionEmployeeAuthenticator)
```

Each arrow points one way. The dependency direction is fixed: **`Interfaces → Application → Domain → (Aggregates, Builders, Value Objects)`**, with `Infrastructure/` consumed by `Interfaces/` and `Application/` (never the other way around). A layer **never** reaches around the layer above it: an action never knows about HTTP, an action never injects a request data object, a builder never writes, an Application action never calls Interface code, a Domain action never imports another context's models.

## Where does an action live?

Two placements, one decision. The full rule lives in [Actions § the placement test](actions.md#the-placement-test); the canonical short form is:

- **Domain action** — owns an aggregate command. Lives at `Domains/<X>/Actions/<Verb><Noun>Action.php`. Changes when the business rules of one aggregate change.
- **Application action** — owns a use case. Lives at `Application/<UseCase>/<Verb><Noun>Action.php`. Changes when the use case changes (steps reordered, new step added, authorization tightened). May touch one bounded context or several; the placement test is *what would force this to change?*, not *how many contexts does it touch?*

There is **no third placement** under `Interfaces/<EntryPoint>/Actions/`. Delivery-coupled writes (web-guard login, token issuing, cookie regeneration) are entry-point-specific concrete services in `Infrastructure/`, not actions. See [Ports and adapters](ports-and-adapters.md).

When in doubt, ask which sentence describes the change:

- *"This protects an Employee invariant"* → Domain action under `Domains/Employees/Actions/`.
- *"This is the Login use case"* → Application action under `Application/EmployeeAuth/`.
- *"This logs an Employee into a web session"* → not an action; it is the `SessionEmployeeAuthenticator` concrete service in `Infrastructure/Auth/Session/`.

## Cross-context communication

A bounded context **never** imports models, builders, or actions from another context's `Domains/<X>/` folder. The three sanctioned mechanisms — **domain events**, **published actions**, **published read models** — and the rule for when each applies live in [Cross-context communication](cross-context.md). The context map (which contexts depend on which, in what direction, with what relationship) lives there too.

## See also

- [Philosophy](philosophy.md) — the architectural stance these layers materialise.
- [Ports and adapters](ports-and-adapters.md) — how external boundaries are crossed.
- [Conventions](conventions.md) — the language rules sitting on top of this layout.
- [Frontend structure](frontend/structure.md) — the canonical `resources/js` entry-point boundaries.
- [Models](data/models.md), [Builders](data/builders.md), [Factories](data/factories.md) — the data side.
- [Routes](http/routes.md), [Controllers](http/controllers.md), [Request data](http/request-data.md), [View data](http/view-data.md) — the HTTP boundary.
- [Actions](actions.md), [Services](services.md), [Transactions](transactions.md) — the write side.
- [Cross-context communication](cross-context.md) — how bounded contexts collaborate.
- [Authorization](authorization.md), [Exceptions](exceptions.md), [Jobs](jobs.md) — the three cross-cutting concerns that ride alongside the action layer.
- [Anti-patterns](anti-patterns.md) — grep-friendly signals when a layer boundary is broken.
- [Glossary](glossary.md) — definitions for the DDD-flavoured terms used above.
