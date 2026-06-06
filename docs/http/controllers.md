# Controllers

> **Owns**
>
> - Controller skeleton (single-action invokable vs resource-style)
> - The four things a controller does
> - What does not belong in a controller
>
> **Forbids**
>
> - Validation rules — see [Request data](request-data.md)
> - Page-prop shaping — see [View data](view-data.md)
> - Writes / business logic — see [Actions](../actions.md)
> - List / table / dashboard / report queries — see [Read models](../application/read-models.md)
> - Third-party SDK construction — see [Services](../services.md)
>
> **See also**: [Request data](request-data.md), [View data](view-data.md), [Actions](../actions.md), [Architecture](../architecture.md)

A controller is a request adapter. It translates HTTP into an Application action or Application query call, then returns an HTTP response. Nothing else.

> Names like `LoginController` / `<Verb><Noun>Action` are illustrative.

For writes, controllers preferably pass IDs, scalar/value-object arguments, or Application input DTOs into Application actions. Route-bound Eloquent models are acceptable in controllers for authorization and response rendering, but the action should usually load the aggregate it mutates.

Request data validates and shapes HTTP input. Controllers translate request data into Application inputs. Application actions never depend on entry-point-specific request data classes.

## Where they live

Controllers live at `Interfaces/<EntryPoint>/Controllers/<Group>/<Name>Controller.php`. Each entry point (`AdminWeb`, `PartnerApi`, `PublicWeb`, …) has its own `Controllers/`, `Requests/`, `Resources/`, `Middleware/`, and `Routes/`. **Controllers never live inside `Domains/`** — a domain that knows about HTTP is a leaky domain. See [Architecture § folder layout](../architecture.md#folder-layout) for the canonical placement.

## What a controller does

Four things, and **only** four:

1. Inject the request data object (and `Request` too when raw HTTP concerns like session or files are needed).
2. Resolve the actor from the guard (when the endpoint is authenticated).
3. Call **one** Application action for writes, or **one** Application query/read model for reads.
4. Return a redirect, an Inertia view, or an API resource.

## Style

- **Single-action invokable** controllers (`__invoke`) for endpoints that do one thing.
- **Resource-style** controllers with multiple methods are acceptable — and preferred — when the same noun has multiple HTTP verbs at related paths. Use `show` / `store` / `update` / `destroy` for plain CRUD; use **descriptive method names** (`confirm`, `qrCode`, `secretKey`, …) when the noun has more states than CRUD covers. The grouping rule is **one noun per controller**, not "exactly four REST verbs".
- **Pick one style per group.** Don't mix `__invoke` + multi-method siblings inside the same `Interfaces/<EntryPoint>/Controllers/<Group>/` folder.
- Controllers are `final`. No base controller is needed.
- Constructor-promote dependencies. No service-locator (`app(...)`) calls in the body.
- Aim for ≤ ~30 lines per method (not the whole file). A resource-style controller with five methods is fine; a single method with five branches is not.

## Skeleton — write action

```php
final class DisableEmployeeController
{
    public function __construct(
        private DisableEmployeeAction $disableEmployeeAction,
    ) {}

    public function __invoke(Employee $employee, DisableEmployeeData $data): RedirectResponse
    {
        Gate::authorize('disable', $employee);

        $this->disableEmployeeAction->execute(
            employeeId: EmployeeId::fromString((string) $employee->getKey()),
            reason: $data->reason,
        );

        return back();
    }
}
```

The controller is glue. The action does the write work and loads the aggregate by ID. The request data object supplies typed inputs (see [Request data § rules](request-data.md#rules)).

## Skeleton — Inertia page render (no business call)

```php
final class <Noun>Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('<group>/<name>', [
            // page props — see View data for shaping rules
        ]);
    }
}
```

For the rules on what the page-props array can contain, see [View data](view-data.md). If the page needs a list, dashboard, report, or other tuned read shape, call one Application query/read model and pass its result into the view-data class.

## Skeleton — resource-style

When the same noun has multiple verbs and the path makes more sense unified:

```php
final class NounController
{
    public function __construct(
        private UpdateNounAction $updateNounAction,
        private DeleteNounAction $deleteNounAction,
    ) {}

    public function show(Noun $noun): Response { /* render */ }
    public function update(UpdateNounData $data, Noun $noun): RedirectResponse
    {
        Gate::authorize('update', $noun);

        $this->updateNounAction->execute(
            nounId: NounId::fromString((string) $noun->getKey()),
            input: $data->toInput(),
        );

        return back();
    }
    public function destroy(Noun $noun): RedirectResponse
    {
        Gate::authorize('delete', $noun);

        $this->deleteNounAction->execute(
            nounId: NounId::fromString((string) $noun->getKey()),
        );

        return redirect()->route('<noun>.index');
    }
}
```

Each write method still calls **one** action. Each read method calls **one** query/read model when a tuned read shape is needed. Multi-action methods are a refactor target.

`UpdateNounInput` is an Application DTO, not a request data object. The request object maps itself to it via `toInput()`; the controller does not hand-assemble it. It is co-located with the action:

```text
app/Application/<ContextOrUseCase>/UpdateNounInput.php
```

For trivial one- or two-scalar use cases, skip the input DTO and pass scalar/value-object parameters directly. See [Request data § from request to action](request-data.md#from-request-to-action) for the full chain.

## What does *not* belong in a controller

The following are guidelines violations when they appear inside a controller method body:

- Repeated `Model::query()->where(...)` chains — push reusable filters into a builder method ([Builders](../data/builders.md)) or a read-side query object ([Read models](../application/read-models.md)).
- `Hash::check(...)`, `Crypt::encryptString(...)`, password verification, signing, encrypting — push into an action or service.
- `forceFill([...])->save()`, `$model->update([...])`, `Model::create([...])`, any direct write — push into an action ([Actions](../actions.md)).
- `new ThirdPartySdk()` — push into a service ([Services](../services.md)).
- `$request->validate([...])` — push into a request data object ([Request data](request-data.md)).
- Passing `Interfaces/<EntryPoint>/Requests/*Data` into an Application action — translate to scalars, value objects, or an Application input DTO first.
- `DB::transaction(...)` — push into the action that owns the unit of work.
- Multi-step branching like "if 2FA enabled stash session, else log in" — push into an action that returns a typed result and let the controller branch on the result, not on raw model state.
- Inline page-prop shaping like `'user' => ['id' => $u->id, 'name' => $u->name, ...]` — push into a resource or view model ([View data](view-data.md)).

## See also

- [Routes](routes.md) — the route-naming convention that backs the controller method names defined here.
- [Request data](request-data.md) — the layer that delivers typed input to the controller.
- [View data](view-data.md) — how to shape Inertia page props.
- [Actions](../actions.md) — where write calls go.
- [Read models](../application/read-models.md) — where list / table / dashboard / report read calls go.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — what every layer including controllers is allowed to do.
- [Anti-patterns](../anti-patterns.md) — grep-friendly signals for controller-rule violations.
