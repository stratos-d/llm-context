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
> - Writes / queries / business logic — see [Actions](../actions.md)
> - Third-party SDK construction — see [Services](../services.md)
>
> **See also**: [Request data](request-data.md), [View data](view-data.md), [Actions](../actions.md), [Architecture](../architecture.md)

A controller is a request adapter. Translates HTTP into an action call, returns an HTTP response. Nothing else.

> Names like `LoginController` / `<Verb><Noun>Action` are illustrative.

## Where they live

Controllers live at `Interfaces/<EntryPoint>/Controllers/<Group>/<Name>Controller.php`. Each entry point (`AdminWeb`, `PartnerApi`, `PublicWeb`, …) has its own `Controllers/`, `Requests/`, `Resources/`, `Middleware/`, and `Routes/`. **Controllers never live inside `Domains/`** — a domain that knows about HTTP is a leaky domain. See [Architecture § folder layout](../architecture.md#folder-layout) for the canonical placement.

## What a controller does

Four things, and **only** four:

1. Inject the request data object (and `Request` too when raw HTTP concerns like session or files are needed).
2. Resolve the actor from the guard (when the endpoint is authenticated).
3. Call **one** action.
4. Return a redirect, an Inertia view, or an API resource.

## Style

- **Single-action invokable** controllers (`__invoke`) for endpoints that do one thing.
- **Resource-style** controllers with multiple methods are acceptable — and preferred — when the same noun has multiple HTTP verbs at related paths. Use `show` / `store` / `update` / `destroy` for plain CRUD; use **descriptive method names** (`confirm`, `qrCode`, `secretKey`, …) when the noun has more states than CRUD covers. The grouping rule is **one noun per controller**, not "exactly four REST verbs".
- **Pick one style per group.** Don't mix `__invoke` + multi-method siblings inside the same `Interfaces/<EntryPoint>/Controllers/<Group>/` folder.
- Controllers are `final`. No base controller is needed.
- Constructor-promote dependencies. No service-locator (`app(...)`) calls in the body.
- Aim for ≤ ~30 lines per method (not the whole file). A resource-style controller with five methods is fine; a single method with five branches is not.

## Skeleton — single action

```php
final class <Verb><Noun>Controller
{
    public function __construct(
        private <Verb><Noun>Action $<verb><Noun>Action,
    ) {}

    public function __invoke(Request $request, <Verb><Noun>Data $data): RedirectResponse
    {
        $this-><verb><Noun>Action->execute(
            actor: $request->user(),
            input: $data,
        );

        return back();
    }
}
```

The controller is glue. The action does the work. The request data object supplies typed inputs (see [Request data § rules](request-data.md#rules)).

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

For the rules on what the page-props array can contain, see [View data](view-data.md).

## Skeleton — resource-style

When the same noun has multiple verbs and the path makes more sense unified:

```php
final class <Noun>Controller
{
    public function __construct(
        private Update<Noun>Action $update<Noun>Action,
        private Delete<Noun>Action $delete<Noun>Action,
    ) {}

    public function show(<Noun> $noun): Response { /* render */ }
    public function update(Request $request, Update<Noun>Data $data, <Noun> $noun): RedirectResponse
    {
        $this->update<Noun>Action->execute($noun, $data);
        return back();
    }
    public function destroy(<Noun> $noun): RedirectResponse
    {
        $this->delete<Noun>Action->execute($noun);
        return redirect()->route('<noun>.index');
    }
}
```

Each method still calls **one** action. Multi-action methods are a refactor target.

## What does *not* belong in a controller

The following are guidelines violations when they appear inside a controller method body:

- `Model::query()->where(...)` — push into a builder method ([Builders](../data/builders.md)).
- `Hash::check(...)`, `Crypt::encryptString(...)`, password verification, signing, encrypting — push into an action or service.
- `forceFill([...])->save()`, `$model->update([...])`, `Model::create([...])`, any direct write — push into an action ([Actions](../actions.md)).
- `new ThirdPartySdk()` — push into a service ([Services](../services.md)).
- `$request->validate([...])` — push into a request data object ([Request data](request-data.md)).
- `DB::transaction(...)` — push into the action that owns the unit of work.
- Multi-step branching like "if 2FA enabled stash session, else log in" — push into an action that returns a typed result and let the controller branch on the result, not on raw model state.
- Inline page-prop shaping like `'user' => ['id' => $u->id, 'name' => $u->name, ...]` — push into a resource or view model ([View data](view-data.md)).

## See also

- [Routes](routes.md) — the route-naming convention that backs the controller method names defined here.
- [Request data](request-data.md) — the layer that delivers typed input to the controller.
- [View data](view-data.md) — how to shape Inertia page props.
- [Actions](../actions.md) — the only place a controller's *one call* goes.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — what every layer including controllers is allowed to do.
- [Anti-patterns](../anti-patterns.md) — grep-friendly signals for controller-rule violations.
