# Anti-Patterns

> **Owns**
>
> - Grep-friendly review signals
> - Where each violation should move
>
> **Forbids**
>
> - Re-stating full rules — each row points to the owning topic
>
> **See also**: [Architecture](architecture.md), [Actions](actions.md), [Models](data/models.md), [Read models](application/read-models.md), [Transactions](transactions.md), [Cross-context communication](cross-context.md)

If a code review surfaces any signal below, the change needs a boundary fix before merging.

## Layer leaks

| Signal | Problem | Right shape |
|---|---|---|
| `forceFill(...)->save()` in controller/resource/request | Delivery is mutating state | Application action calls aggregate behavior or performs simple field update |
| `$model->update([...])` in controller | Controller owns write | Application action |
| Route-bound model passed into write action by default | Delivery-loaded model becomes the action's mutation target | Pass an identity value object/input DTO; action loads the aggregate |
| Raw status/lifecycle assignments in action with guard `if` chains | Action owns aggregate invariant | Aggregate method such as `$order->cancel()` |
| `save()` / `update()` inside aggregate method | Aggregate persists itself | Aggregate mutates memory; Application/repository persists |
| `event(...)` inside aggregate method | Domain coupled to Laravel dispatcher | Aggregate records event; Application dispatches after persistence |
| `dispatch(...)` inside aggregate/model/controller | Wrong delivery boundary | Application action or listener dispatches job |
| `Request` / `FormRequest` accepted by Application query/filter/action | HTTP leaked inward | Request data builds pure DTO/filter |
| `Interfaces/<EntryPoint>/Requests/*Data` accepted by Application action | Delivery DTO leaked inward | Controller maps to scalars, value objects, or Application input DTO |
| `fromRequest(Request $request)` in Application filter/read model | HTTP leaked into Application DTO | Factory lives in request data/controller |
| Application action returning `RedirectResponse`, `JsonResponse`, resource, or Inertia response | Application returns delivery type | Return `void`, `bool`, value object, aggregate, or result object |

## Transaction misuse

| Signal | Problem | Right shape |
|---|---|---|
| `DB::transaction(...)` in controller/model/listener/job/service | Wrong transaction root | Application action |
| Repository wraps whole business use case in transaction | Repository owns use-case boundary | Application action opens transaction; repository persists aggregate internals |
| Nested Application actions both opening transactions | Two transaction roots | Extract shared behavior that does not wrap |
| Transaction around one plain `saveOrFail()` | Noise | Single row write is already atomic |

## Cross-context leaks

| Signal | Problem | Right shape |
|---|---|---|
| `use App\Domains\<Other>\Models\<X>` in Domain/write model | Foreign aggregate dependency | Store ID/value object; query through published read model/query when needed |
| Cross-context DB foreign key | Schema ownership leak | Store ID + index by default |
| Raw pivot writes for another context's access/membership | Bypasses owning aggregate rules | Owning aggregate method or published Application action |
| Join across contexts in a builder/write model | Query leak in wrong layer | Application read model/query |
| Domain event payload contains Eloquent model | Event outlives request/model scope | IDs and value objects only |

## Read-side misuse

| Signal | Problem | Right shape |
|---|---|---|
| Query executed outside a query/repository (action, controller, resource, service) | Data access scattered; unauditable | Move the read to a `{Context}Query`, the write to a `Repository` |
| Paginated/list query in a repository | Repository used as a read model | `{Context}Query` |
| Query returns Eloquent collection/model | Projection leaks persistence model | DTO row/page/result |
| Query object writes state or emits events | Read side mutates | Application action / domain event listener |
| Controller builds large inline array from many models | Response shaping and query mixed | `{Context}Query` + resource/view model |
| One query class per screen | Reads fragmented across classes | Group the context's reads in its `{Context}Query` |

## Abstraction misuse

| Signal | Problem | Right shape |
|---|---|---|
| Interface with one implementation and no real second | Preemptive abstraction | Inject concrete service |
| Interface created only for tests | Test-driven architecture noise | Inline test double or framework fake |
| Repository for every model | Ceremony | Use Eloquent/builder unless repository trigger is real |
| Value object wrapping one primitive with no invariant or repeated meaning | Ceremony | Use primitive with descriptive parameter name |
| Result object wrapping one boolean | Ceremony | Return `bool` unless named outcomes matter |

## Authorization and exceptions

| Signal | Problem | Right shape |
|---|---|---|
| `Gate::authorize()` inside aggregate method | Domain sees framework auth | Policy/request/controller boundary or Application precondition service |
| `auth()`/`session()` inside Application action | Delivery/framework leak | Controller or concrete Infrastructure service |
| `throw new RuntimeException()` for business failure | Untyped domain failure | Domain exception |
| Controller catches domain exception only to make response | Duplicates central mapping | Let central handler map it |

## Comments and TODOs

| Signal | Problem | Right shape |
|---|---|---|
| `// TODO`, `// FIXME`, `// HACK` | Work hidden in code comments | Issue tracker or explicit planning document |
| Commented-out code | Dead code | Delete it |
| PHPDoc restating signature only | Noise | Delete or add useful shape/generic info |

## See also

- [Actions](actions.md) — Application use cases.
- [Models](data/models.md) — aggregate behavior.
- [Read models](application/read-models.md) — query side.
- [Cross-context communication](cross-context.md) — boundary rules.
