# Glossary

> **Owns**
>
> - One-line definitions for every DDD-flavoured term used across the docs
> - The link from each term to the file that owns its operative rule
>
> **Forbids**
>
> - Detailed explanations — those live in the owning file
> - Synonyms or aliases that are not actually used in the codebase
>
> **See also**: [README](README.md), [Philosophy](philosophy.md), [Architecture](architecture.md)

This is a flat A–Z reference. Each entry is one or two sentences plus a link to the file that owns the rule. Use it to look up a term without re-reading the topic doc.

## A

**Adapter.** Historical hexagonal-architecture term for a concrete class that translates between the project's vocabulary and an underlying mechanism. In this project an adapter is just a [concrete service](#concrete-service); the term is kept for cross-reference. See [Ports and adapters](ports-and-adapters.md).

**Aggregate.** Within a bounded context, the cluster of one or more models that share an invariant boundary. Loaded and saved as a unit through the aggregate root. See [Architecture § aggregates](architecture.md).

**Aggregate root.** The single model that other code references when it talks about an aggregate. The Eloquent model that lives in `Domains/<X>/Models/<Name>.php` is the aggregate root. Uses **UUIDv7** as its primary key. See [Architecture § aggregates](architecture.md).

**Aggregate ID.** The primary key of an aggregate root. Always **UUIDv7** in this project; `bigint` autoincrement is reserved for internal-only tables (pivots, framework tables, audit logs). See [Conventions § IDs](conventions.md#ids) and [Models § IDs](data/models.md#ids).

**Aggregate part.** A model that lives inside an aggregate's consistency boundary but is not the aggregate root — no independent identity, no independent lifecycle, loaded only through the root, mutated only by the root's actions. Keeps `bigint` autoincrement PKs. Contrast with [aggregate root](#aggregate-root). See [Models § aggregate root vs aggregate part](data/models.md#aggregate-root-vs-aggregate-part).

**Anaemic domain model.** A domain model whose state-mutating behaviour lives outside the model (in actions). Deliberate compromise in this project. See [Philosophy § what we deliberately do not adopt](philosophy.md#what-we-deliberately-do-not-adopt).

**Anti-corruption layer (ACL).** A driven adapter that translates a third party's vocabulary and shape into the project's vocabulary, preventing the third party's model from leaking inward. Every wrapper under `Infrastructure/External/` is an ACL by default. See [Services](services.md).

**Application action.** An action that owns a use case: a single goal a single actor accomplishes in a single interaction with the system. Lives in `Application/<UseCase>/`. See [Actions](actions.md).

**Application layer.** The folder `app/Application/`. Contains use-case orchestration, ports for capabilities used by use cases, and result objects. May call domain code; must not call interface code. See [Architecture](architecture.md).

## B

**Bounded context.** An isolated model with its own ubiquitous language, schema, and rules. Each `Domains/<X>/` is a bounded context. Cross-context references go through events, application actions, or published contracts. See [Architecture § cross-context communication](architecture.md).

**Builder.** A read-side query class for one model: `<Model>Builder extends BaseBuilder`. Owns reusable filters, orderings, and eager-load presets. Replaces ad-hoc `Model::query()->where(...)` chains. See [Builders](data/builders.md).

## C

**`CarbonImmutable`.** The only allowed time-source class in this project. All wall-clock reads use `CarbonImmutable::now()`; tests freeze time with `CarbonImmutable::setTestNow($instant)`. Mutable `Carbon`, the `now()` helper, and `new DateTime(...)` are forbidden. See [Conventions § Time](conventions.md#time).

**Composition root.** The location where dependencies for an entry point are wired. In this project, the entry point itself plus (when bindings are needed) `Interfaces/<EntryPoint>/Providers/<EntryPoint>ServiceProvider.php`. The composition root is the polymorphism boundary: capabilities specific to one entry point don't need to be substitutable for capabilities of another.

**Concrete service.** A `final` class in `Infrastructure/<Capability>/<Strategy>/` that encapsulates a composite or named operation against the framework or a vendor SDK. Default shape for external-boundary code; an interface is introduced only when justified. See [Ports and adapters](ports-and-adapters.md), [Services](services.md).

**Context map.** A document or table describing how bounded contexts depend on each other (upstream/downstream, conformist, anti-corruption layer, shared kernel). Lives in [Architecture § cross-context communication](architecture.md).

**Contextual binding.** Laravel's container feature for resolving the same interface to different implementations based on the consumer (`$this->app->when(X)->needs(Y)->give(Z)`). Used when an interface has multiple implementations. See [Ports and adapters § container wiring](ports-and-adapters.md#container-wiring).

**Controller.** An HTTP request adapter (driving adapter). Lives in `Interfaces/<EntryPoint>/Controllers/`. Translates HTTP into one action call and one response. See [Controllers](http/controllers.md).

**CQRS-lite.** Separation of write models (aggregates, mutated through actions) and read models (tuned queries returning DTOs). The project adopts this in code, not in infrastructure. See [Read models](http/read-models.md).

## D

**Delivery shape.** The transport-and-protocol pair an entry point uses (Inertia/session for AdminWeb, JSON/token for PartnerApi). Different delivery shapes get different adapters. See [Ports and adapters](ports-and-adapters.md).

**Domain action.** An action that owns an aggregate command: invariant-protecting state mutation on one aggregate. Lives in `Domains/<X>/Actions/`. See [Actions](actions.md).

**Domain exception.** A typed failure thrown by a Domain or Application action when an invariant or precondition is violated. Lives at `Domains/<X>/Exceptions/<Name>.php`. The project root `App\Domains\DomainException` is abstract; per-context bases extend it; concrete failures (e.g. `EmployeeAlreadyDisabled`) extend the per-context base. The central exception handler in `bootstrap/app.php` maps it to HTTP. See [Exceptions](exceptions.md).

**Domain event.** A past-tense, named event emitted by an action when business state changes (`EmployeeRegistered`, `DocumentPublished`). Carries primitives and value objects, never models. Listened to in the *reacting* context, not the emitter's. See [Domain events](domain-events.md).

**Driven adapter.** A concrete service the application calls *out to* (mail, notifications, auth, third-party APIs). In this project, just a concrete class in `Infrastructure/<Capability>/<Strategy>/`; an interface is introduced only when ≥2 implementations coexist. See [Ports and adapters](ports-and-adapters.md).

**Driving adapter.** A class that calls *into* the application (controllers, queue jobs). Always concrete; the framework provides their stable shape. See [Ports and adapters](ports-and-adapters.md).

## E

**Entry point.** A single delivery shape that the application serves: `AdminWeb`, `PartnerApi`, etc. Each entry point owns a tree under `Interfaces/<EntryPoint>/` and a service provider that wires its bindings. See [Architecture § folder layout](architecture.md#folder-layout).

## F

**Fake adapter.** An in-memory implementation of an interface, used when tests or dev/staging genuinely need a substitute. Lives in `Infrastructure/<Capability>/Fake/`. **Not the default** — most code is exercised via feature tests against the production service. Introduce a fake only when the real service has a side effect tests can't tolerate (network call, etc.). See [Testing § substituting collaborators in tests](testing.md#substituting-collaborators-in-tests).

## H

**Hexagonal architecture.** The architectural pattern of an application core surrounded by ports, with adapters on the outside translating between the core and external systems. This project adopts the *layering* and *anti-corruption* parts but not the mandatory-port part — see [Philosophy](philosophy.md) and [Ports and adapters](ports-and-adapters.md).

## I

**Inbound adapter.** Synonym for driving adapter.

**Infrastructure layer.** The folder `app/Infrastructure/`. Contains base Eloquent classes, adapters for every external boundary, and framework-agnostic utilities. Depends on Application and Domain ports; never depended on by them. See [Architecture](architecture.md).

**Interfaces layer.** The folder `app/Interfaces/`. Contains the per-entry-point delivery code: controllers, request data, view models, middleware, routes, providers. Composes Application actions; never contains business logic. See [Architecture](architecture.md).

## J

**Job.** A queued-delivery wrapper for an Application action. Lives at `Application/<UseCase>/Jobs/<Verb><Noun>Job.php`. The `handle()` method calls one Application action and nothing else; payloads are primitives, value objects, and IDs (never Eloquent models). Dispatched with `->afterCommit()` from inside transaction roots. See [Jobs](jobs.md).

## L

**Listener.** A handler for a domain event, living in the **reacting** context (`Domains/<X>/Listeners/`). Synchronous by default; runs inside the emitter's transaction. Non-trivial async work belongs in a Job dispatched by the listener, not in the listener itself. See [Domain events § who listens](domain-events.md#who-listens) and [Jobs § listeners and jobs](jobs.md#listeners-and-jobs).

## P

**Policy.** Laravel's authorization mechanism. Lives at `Domains/<X>/Policies/<Aggregate>Policy.php`, auto-discovered from the aggregate model. Invoked at the **delivery boundary** (controller via `Gate::authorize`, or request-data `authorize()`), never inside Application or Domain actions. See [Authorization](authorization.md).

**Port.** A PHP `interface` describing a capability in the project's vocabulary. Lives where the capability is *used* (`Application/<UseCase>/Contracts/` or `Domains/<X>/Contracts/`), never where it's implemented. **Optional, not default**: introduced only when ≥2 implementations coexist or one is imminent. See [Ports and adapters § the trigger rule](ports-and-adapters.md#the-trigger-rule).

**Published action.** An Application action that a bounded context has deliberately exposed for *other contexts* to call. Marked with the `@published` doctag in its class docblock. Inputs and outputs are primitives, DTOs, or value objects — never another context's model. Renaming it is a breaking change. See [Cross-context communication § published actions](cross-context.md#published-actions).

**Published read model.** A read model the producing context exposes for other contexts to query. Owns any cross-context JOINs in one place. The consuming context calls it and receives DTOs, never aggregates. See [Cross-context communication § published read models](cross-context.md#published-read-models).

## R

**Read model.** A class that produces query results in a shape tuned for one screen, returning DTOs rather than aggregates. Used for dashboards, lists, reports. The cross-context-no-joins rule is explicitly relaxed for read models; a read model that crosses contexts is a [published read model](#published-read-model). See [Read models](http/read-models.md).

**Repository.** A class that loads and saves aggregates, hiding the query mechanism. Opt-in in this project; not the default. The default read mechanism is the builder. Introduced only when one of four triggers applies. See [Repositories](data/repositories.md).

**Request data object.** A `spatie/laravel-data` class that validates and types one HTTP endpoint's input. Lives in `Interfaces/<EntryPoint>/Requests/`. See [Request data](http/request-data.md).

**Resource.** A `JsonResource` that shapes one model into one response payload. Lives in `Interfaces/<EntryPoint>/Resources/`. See [View data](http/view-data.md).

**Result object.** A typed, immutable value returned by an Application action describing the use-case outcome. Co-located with its action; suffix `Result` (e.g. `VerifyEmployeeCredentialsResult`). Introduced only when the caller doesn't already have the returned data, OR there are 3+ outcome states. See [Actions § when to return a result object](actions.md#when-to-return-a-result-object).

## S

**Service.** A `final` concrete class in `Infrastructure/`. Synonym for *concrete service*. See [Services](services.md).

**Shared kernel.** The folder `Infrastructure/Support/`, where framework-agnostic, business-meaning-free helpers live. Strict rule: anything that names a thing in the business does not belong here. See [Architecture § Infrastructure/Support/ — shared-kernel rule](architecture.md#infrastructuresupport--shared-kernel-rule).

**Specification.** A DDD pattern for composable business-rule predicates. **Not adopted in this project**; builder methods cover the same use case. See [Philosophy](philosophy.md).

**Stash.** A short-form name for a class that durably holds inter-request domain memory (e.g. `SessionPendingLoginStash`). Lives in `Infrastructure/<Capability>/<Strategy>/`. See [Services](services.md).

**Strategy prefix.** A naming convention: when a concrete service represents a strategy with plausible siblings (`Session*`, `Token*`, `Smtp*`, `Algolia*`), prefix the class name with the strategy. The next variant slots in obviously parallel without renaming the original. See [Ports and adapters § strategy prefix for variant-anticipated services](ports-and-adapters.md#strategy-prefix-for-variant-anticipated-services).

## T

**Transaction root.** The single point where a use case opens its `DB::transaction()` boundary. In this project, **only Application actions** are transaction roots. Domain actions, controllers, services, listeners, and jobs never open one. Single-row writes don't need a wrap (one `save()` is atomic). See [Transactions](transactions.md).

## U

**Ubiquitous language.** Names in code that mirror the words domain experts use. The same word may mean different things in different bounded contexts. See [Philosophy](philosophy.md).

**Use case.** A single goal a single actor accomplishes in a single interaction with the system, even if it spans multiple steps internally. Owned by an Application action. See [Actions](actions.md).

## V

**Value object.** An immutable, identity-by-value class wrapping a primitive (or a tuple of primitives) that has validation rules or domain meaning. `Email`, `DateRange`, `EmployeeId`, `PendingLogin`. Lives in `Domains/<X>/ValueObjects/` or co-located with the action that introduces it. Promoted from a primitive after the same primitive appears in three or more signatures **and** carries an invariant the primitive can't. See [Value objects](data/value-objects.md).

## See also

- [Philosophy](philosophy.md) — the project's stance on each of the DDD ideas above.
- [Architecture](architecture.md) — the folder layout and layer responsibilities the terms apply to.
- [README](README.md) — reading order for a cold start.
