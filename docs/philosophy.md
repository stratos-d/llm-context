# Philosophy

> **Owns**
>
> - The project's self-description and architectural stance
> - Which DDD ideas are adopted and which are deliberately not
> - The pragmatic compromises and the reason each one was made
>
> **Forbids**
>
> - Layer rules — see [Architecture](architecture.md)
> - Per-layer skeletons — see the relevant topic file
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Ports and adapters](ports-and-adapters.md), [Glossary](glossary.md)

Read this file before any other. Every other doc assumes the stance below.

## What this project is

**A Laravel modular monolith with a layered architecture, DDD-flavoured naming, pragmatically scoped abstractions, and a deliberately anaemic domain model.**

That sentence is the single self-description. Each phrase in it carries weight; the rest of this file unpacks them.

## What this project is not

- It is not a textbook DDD codebase. It does not have rich aggregate roots with private setters and behaviour methods that enforce all invariants. State mutation lives in actions, not on aggregates.
- It is not a hexagonal-purist codebase. The presentation layer (controllers, request data, view models) is named `Interfaces/`, but it is not strictly an inbound-adapter set in the Cockburn sense — it carries some application-shaping responsibility for ergonomic reasons.
- It is not a CRUD app dressed up in DDD vocabulary. Bounded contexts are real and enforced; cross-context dependencies go through events, application actions, or published contracts — never through model imports or foreign keys.
- It is not "do whatever Laravel suggests." Default Laravel patterns that conflict with the boundaries below (fat controllers, model-self-saves, scope traits, facade calls in business code) are project anti-patterns.

## What we adopt from DDD

### Strategic

- **Bounded contexts.** Each `Domains/<X>/` is a bounded context: an isolated model with its own ubiquitous language, its own schema, and its own rules. Cross-context references use events, application actions, or published contracts.
- **Ubiquitous language.** Names in code mirror the words the domain experts use. `Employee`, not `User`. `Document`, not `Record`. The same word may mean different things in two contexts and that is fine — it is the whole point.
- **Context maps.** The relationship between contexts is documented (upstream/downstream, conformist, anti-corruption layer) so that future changes know which way each dependency points.

### Tactical (selected, not blanket)

- **Aggregate-command-shaped actions.** Every state change goes through a named action; the verb describes the operation, the noun the aggregate it changes. See [Actions](actions.md).
- **Value objects** for any primitive that has validation rules or whose identity is by value (`Email`, `DateRange`, `EmployeeId`). When a primitive shows up in three or more signatures across a context **and** carries an invariant the primitive can't, it is promoted. See [Value objects](data/value-objects.md).
- **Domain events** for cross-context glue and for any side effect that is not the point of the action. The action mutates state and emits an event; listeners do the rest. See [Domain events](domain-events.md).
- **Anti-corruption layers** for every external system whose model we do not own. The third party's vocabulary stops at the adapter boundary and never reaches into actions or domain code.

### Architectural

- **One-way dependency flow.** `Interfaces → Application → Domain → (Aggregates, Builders, Value Objects)`. Application never calls Interfaces. Domain never knows about Application.
- **External capabilities are concrete services in `Infrastructure/<Capability>/<Strategy>/`.** Auth, mail, notifications, search, third-party APIs, etc. each live as a concrete class with a constructor-injected name. Strategy-prefixed (`Session*`, `Token*`, `Smtp*`, `Algolia*`) when variants are anticipated. Interfaces only when ≥2 implementations actually coexist or one is imminent — see [Ports and adapters](ports-and-adapters.md).
- **Resist preemptive abstraction.** An interface, a wrapper, a result type, a value object are all things you pay for in code-reading time. Each of them has to earn its keep. The default is *concrete class, primitive type, direct call*; promotion to abstraction happens when the cost of *not* abstracting becomes visible (multiple implementations, repeated code, missing invariant). The entry point is the polymorphism boundary; capabilities specific to one entry point don't need to be substitutable for capabilities of another.

## What we deliberately do not adopt

### Rich domain aggregates

A textbook DDD aggregate exposes only behaviour methods (`$employee->disableTwoFactor()`); attribute setters are private; the aggregate enforces all invariants in code. We do not do this.

**Why not.** Eloquent is built around the assumption that the model is the row, the API resource, the form-fillable thing, and the query target. Fighting that to make models pure aggregates costs more than it saves. We accept the **anaemic domain model** trade: behaviour lives in actions, models hold state plus read-only state helpers (`hasConfirmedTwoFactor()`).

**The escape hatch.** When the same write pattern shows up in three or more actions on one aggregate, **that is the signal** to promote it to a behaviour method on the model. At that point the model's `disableTwoFactor()` does the attribute work and the action becomes `$employee->disableTwoFactor(); $employee->saveOrFail();`. This is allowed and encouraged when it earns its keep. See [Models § promoting attribute writes to behaviour methods](data/models.md#promoting-attribute-writes-to-behaviour-methods).

### Repositories as a default

A textbook DDD codebase defines a `Repository` interface in Application or Domain and an Eloquent-backed implementation in Infrastructure for every aggregate. We do not.

**Why not.** Eloquent is treated as part of the domain layer; the project's default read mechanism is the **builder** (see [Builders](data/builders.md)), and Eloquent's `Model::query()` is a perfectly good factory. Repositories are introduced only when the read shape is genuinely different from the table shape, or when reads need to return non-Eloquent aggregates. See [Repositories](data/repositories.md).

### Specifications

A textbook DDD specification class composes business-rule predicates (`ActiveEmployeesSpec()->and(VerifiedEmployeesSpec())`). We do not.

**Why not.** Builder methods cover the same use case (`Employee::query()->verified()->newest()`) with less ceremony and the same composability. Specifications would be a parallel mechanism for the same job.

### Event sourcing

Not adopted. Domain events are dispatched and handled in-process; there is no event store, no replay, no projection rebuild. Standard append-only audit logs and database snapshots are sufficient for compliance needs.

If a future regulatory requirement demands replay, the migration path is: add an event store, persist domain events into it on dispatch, build projections from the store. The domain-event API does not change; only its plumbing does.

### CQRS as separate write/read databases

Not adopted in the infrastructure sense (no separate read store). **Adopted in the code sense**: read models for non-trivial queries are separate classes from aggregates, hit the DB directly with tuned queries, and return DTOs. See [Read models](http/read-models.md).

## The pragmatic compromises and why

| Compromise | Cost | Benefit |
|---|---|---|
| Anaemic models with state-mutating actions | Behaviour is not on the aggregate; small risk of duplicated mutation logic | Keeps Eloquent ergonomics; flatter learning curve; easy promotion path when duplication appears |
| `Domains/` folder name (not `Contexts/`) | Mild vocabulary mismatch with strategic DDD | Avoids a project-wide rename; readers learn one word means context |
| Builders instead of repositories by default | Ports-and-adapters discipline does not extend to persistence | Eloquent's read API stays usable; no per-aggregate factory class to maintain |
| Eloquent in actions | Domain layer knows about an ORM | The whole project would otherwise need a persistence-mapping layer; nobody has the budget for that |
| Application actions may use `dispatch(...)` directly | Application layer touches framework job dispatch | Jobs are framework-coupled by their nature; abstracting adds ceremony with no upside |
| Policies remain framework-coupled | Authorization is a framework call, not a port | Wrapping every policy behind an `Authorizer` interface produces ceremony with no upside |
| Concrete service classes by default; interfaces only when justified | Application code knows the *name* of the strategy it uses (`SessionEmployeeAuthenticator`) | Avoids the cost of wrapping a one-implementation capability behind an interface; the entry point IS the polymorphism boundary so cross-entry-point variants get separate concrete classes anyway |

Every compromise has the form *"strict purity costs more than it saves; here is the escape hatch when the cost balance shifts."* The escape hatches are documented in their owning files.

## Where the lines are not negotiable

These are not compromises; they are invariants. Touching them needs a deliberate, documented decision and a co-ordinated update across the docs.

- **Domain code does not import HTTP, sessions, guards, tokens, or Inertia.** Ever.
- **Cross-context references go through events, app actions, or contracts.** No model imports across `Domains/<X>/`.
- **External-boundary code stays out of the Application and Domain layers.** Auth, mail, notifications, search, etc. live in `Infrastructure/`, called from controllers (or from Application actions when the call is framework-agnostic, like `Hasher::check`). When variants exist or are imminent, an interface lives with the caller; otherwise the concrete service is injected directly.
- **State mutation goes through an action.** No `forceFill()->save()` on models, in traits, in controllers, in resources.
- **Tests are written alongside the feature.** Not after.

## How to use this file

When reading a topic doc, if it appears to contradict another, the resolution order is:

1. This file (`philosophy.md`) for the high-level stance.
2. [Architecture](architecture.md) for layer placement and folder layout.
3. The topic doc itself for skeletons and per-layer rules.
4. [Anti-patterns](anti-patterns.md) for grep-friendly violation signals.

When writing a new doc or a new rule, check that it sits inside the philosophy above. If a proposed rule would push the project toward strict DDD purity (rich aggregates, mandatory repositories, etc.) the burden is on the proposer to argue why the cost balance has shifted, not on the reviewer to defend the existing compromise.

## See also

- [Architecture](architecture.md) — the folder layout and layer responsibilities the philosophy materialises into.
- [Ports and adapters](ports-and-adapters.md) — how external boundaries are crossed.
- [Actions](actions.md) — where state-mutating logic lives given the anaemic-model trade.
- [Glossary](glossary.md) — definitions for the DDD terms used above.
- [README](README.md) — reading order across all topic files.
