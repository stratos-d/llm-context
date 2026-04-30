# Services

> **Owns**
>
> - The third-party-SDK-wrapping skeleton
> - Statelessness, no-persistence, one-concern-per-class rules
> - The vocabulary note: in this project, "service" ≈ "concrete class in `Infrastructure/`"
>
> **Forbids**
>
> - Persistence inside services — that belongs in [Actions](actions.md)
> - Stateful service classes — state lives on aggregates
> - Bundling multiple third-party concerns into one class
>
> **See also**: [Ports and adapters](ports-and-adapters.md), [Actions](actions.md), [Architecture](architecture.md), [Models](data/models.md)

A "service" in this project is a **concrete class in `Infrastructure/`** that wraps an external dependency or third-party SDK so the rest of the app talks to *our* class with *our* method names, not the vendor's. An interface is introduced only when there are multiple implementations to choose from (see [Ports and adapters § the trigger rule](ports-and-adapters.md#the-trigger-rule)); most services are concrete classes injected directly.

> Names like `TwoFactorAuthenticator` / `Google2FA` are illustrative.

## Where they live

Services live under `Infrastructure/`, never inside `Domains/`. The full layout rule is owned by [Architecture § folder layout](architecture.md#folder-layout); the short form:

- `Infrastructure/External/<Capability>/<Strategy><ServiceName>.php` — services that do remote I/O (notification providers, search APIs, webhooks).
- `Infrastructure/Auth/<Strategy>/<Strategy><ServiceName>.php` — services for auth and identity (session login, token issuing).
- `Infrastructure/Auth/TwoFactor/<ServiceName>.php` — 2FA verifier / generator.
- `Infrastructure/Mail/<Strategy>Mailer.php` — mail-sending services.
- `Infrastructure/Cache/<Strategy>DomainCache.php` — domain-cache services (when cache is a domain mechanism).
- `Infrastructure/Support/` — framework-agnostic helpers.

Group by **capability**, then by **strategy** when variants are anticipated. If we ever swap Algolia for Meilisearch, `External/Search/` already has the right shape.

## Strategy prefix

Use a strategy prefix on the class name when the capability has plausible siblings:

- `SessionEmployeeAuthenticator` — session-based login
- `TokenEmployeeAuthenticator` — token-based login (when PartnerApi exists)
- `AlgoliaSearchIndexer`, `MeilisearchIndexer` — different vendor strategies

Drop the prefix when the class is the only shape the capability will take:

- `TwoFactorAuthenticator` — there's just one TOTP/recovery-code flow
- `LaravelMailer` — one production mailer; Laravel is the strategy

The prefix earns its keep when the *next* sibling shows up: it slots in obviously parallel without renaming the original.

## Anti-corruption layer

Every service under `Infrastructure/External/` is an **anti-corruption layer** (ACL) by default. The third party's vocabulary, error shapes, and data structures stop at the service boundary. The service translates into our vocabulary on the way in (mapping `ext_status_cd` to a `DirectoryRecordStatus` enum) and translates out on the way back to the vendor (mapping our `DateRange` value object to the vendor's `start_date` / `end_date` fields).

If you find domain code referencing types or constants from the vendor's SDK, the ACL has leaked. Refactor: introduce the project-shaped types in the service, return only those from the public methods.

## When to introduce a service class

You **should** introduce a service class when:

- An action, model, or controller would otherwise call `new SomeVendorClass(...)` directly.
- The same SDK or framework primitive is touched in more than one place.
- A composite operation (multiple framework primitives forming one logical step) deserves a name and a single home.
- The third-party API is awkward to work with and you want a project-shaped surface.

You should **not** introduce a service class for one-liner framework calls used in exactly one place. The wrap is overhead, not abstraction.

## Skeleton

```php
final class TwoFactorAuthenticator
{
    public function __construct(
        private Google2FA $google2fa,
    ) {}

    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, trim($code));
    }

    public function qrCodeSvg(string $issuer, string $account, string $secret): string
    {
        // wrap the Bacon/SVG awkwardness here, expose a clean string
    }
}
```

The class is `final`. It does not implement an interface (no second strategy exists; if one ever did, the interface gets introduced *then*). The vendor SDK is a constructor dependency, not a `new` call inside a method. Method names describe what *we* want, not what the vendor calls them.

## Container binding

Most services need **no binding**. Laravel's container auto-resolves any concrete class with constructor type-hints. Inject the class directly.

Bind explicitly when:

- The vendor SDK needs non-trivial construction (factories, configuration). Bind the SDK as a singleton in `AppServiceProvider`:
  ```php
  $this->app->singleton(Google2FA::class, fn () => new Google2FA(
      new Bacon(new SvgImageBackEnd),
  ));
  ```
- An interface exists with multiple implementations (rare). Then bind in the entry-point service provider via contextual binding.

The full binding rule is owned by [Ports and adapters § container wiring](ports-and-adapters.md#container-wiring).

## Rules

- **Services are stateless.** State (the secret, the codes, the user record) lives on the aggregate; the service just transforms.
- **Services do not persist.** They produce values; actions persist them. A service that calls `->save()` is a hidden action — split it.
- **One service class per third-party concern.** Do not bundle "everything 2FA" into a single 400-line `AuthService`. If a vendor SDK has multiple distinct surfaces, model that as multiple service classes.
- **No HTTP concerns** outside services that explicitly wrap session / cookie / request mechanics (`SessionEmployeeAuthenticator`'s whole point is the framework-coupled login + session-regenerate sequence).
- **Constructor-promoted dependencies, `readonly` where viable.** No facade calls inside method bodies; no service-locator pattern.

## See also

- [Ports and adapters](ports-and-adapters.md) — when an interface earns its keep.
- [Actions](actions.md) — actions consume services; services do not orchestrate use cases.
- [Architecture § layer responsibilities](architecture.md#layer-responsibilities) — where services sit relative to other layers.
- [Anti-patterns § framework-coupling and infrastructure leaks](anti-patterns.md#framework-coupling-and-infrastructure-leaks) — grep-friendly signals of misuse.
