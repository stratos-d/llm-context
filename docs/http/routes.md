# Routes

> **Owns**
>
> - Route file placement (per entry point) and split-by-feature rule
> - URL nesting rule (`/<resource>/<sub-resource>/...`)
> - The route-naming convention (`<resource>.<verb>`, REST verbs only)
> - Loader wiring in `bootstrap/app.php`
>
> **Forbids**
>
> - Domain verbs in route names (`...enable`, `...regenerate`, `...confirm`) — see § naming
> - One mega route file per entry point — see § file split
> - **Closure handlers, anywhere.** Always use `[<Controller>::class, 'method']`. Closures are reserved for `group()`, `prefix()`, `middleware()` and similar scope-declaring helpers — never for request handling.
>
> **See also**: [Architecture](../architecture.md), [Controllers](controllers.md), [Request data](request-data.md)

Each entry point under `Interfaces/<EntryPoint>/Routes/` owns its own route files. The framework loads them via `bootstrap/app.php`. The rules below apply per entry point — `AdminWeb` and `PartnerApi` follow the same conventions independently.

## Where they live

```text
app/Interfaces/<EntryPoint>/Routes/
├── auth.php          # the auth flow (login + 2FA challenge + logout)
├── home.php          # / and /dashboard
└── profile.php       # /profile/* — account-management endpoints
```

One file per cohesive feature area. A single 200-line `web.php` is a refactor target. A new feature area = a new file.

## Handlers are always controllers

Routes declare endpoints; controllers handle requests. Every `Route::get/post/put/patch/delete(...)` call uses `[<Controller>::class, 'method']`:

```php
Route::get('/', [HomeController::class, 'show'])->name('home');
Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
Route::post('login', [LoginController::class, 'store'])->name('login.store');
```

Closures are reserved for **scope-declaring** helpers — `group()`, `prefix()`, `name()`, `middleware()`. Those are configuration, not handlers:

```php
// fine — group closure declares scope
Route::middleware(Guard::Admin->authMiddleware())->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
});

// forbidden — closure handler
Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
```

This rule applies to render-only Inertia endpoints too. A controller method that returns one `Inertia::render('page')` is one extra file per route; the routes file stays declarative and any future logic (auth-aware redirect, prop preparation) has somewhere obvious to land.

## Loader

`bootstrap/app.php` globs the directory under `withRouting(then: ...)`:

```php
then: function (): void {
    foreach (glob(__DIR__.'/../app/Interfaces/AdminWeb/Routes/*.php') as $routeFile) {
        Route::middleware('web')->group($routeFile);
    }
},
```

Adding a new route file requires no boot-loader edit — drop the file in and it is picked up. Per-file middleware (`auth:admin`, `guest:admin`, `throttle:login`) is declared inside the file's own `Route::middleware(...)->group(...)` block.

## URL nesting

URLs reflect resource ownership. A sub-resource of `two-factor` lives at `/profile/two-factor/<sub>`, never as a flat sibling:

```text
✓ POST   /profile/two-factor                          # enable
✓ DELETE /profile/two-factor                          # disable
✓ POST   /profile/two-factor/confirmation             # confirm a pending enrollment
✓ GET    /profile/two-factor/qr-code                  # show QR
✓ GET    /profile/two-factor/recovery-codes           # list codes
✓ POST   /profile/two-factor/recovery-codes           # regenerate

✗ POST   /confirmed-two-factor-authentication         # two adjectives stacked on a noun
✗ POST   /user/two-factor-recovery-codes-regenerate   # verb encoded in the URL segment
```

Express the verb with the **HTTP method**, not by concatenating words into the path.

## Naming convention

Every route has a name. Names are dotted, with one segment per URL segment, and end in a REST verb:

```text
<resource>[.<sub-resource>...].<verb>

verbs: index | show | create | store | update | destroy
```

Examples:

```text
login.show                                    GET    /login
login.store                                   POST   /login
two-factor-challenge.show                     GET    /two-factor-challenge
two-factor-challenge.store                    POST   /two-factor-challenge
profile.two-factor.store                      POST   /profile/two-factor
profile.two-factor.destroy                    DELETE /profile/two-factor
profile.two-factor.confirmation.store         POST   /profile/two-factor/confirmation
profile.two-factor.qr-code.show               GET    /profile/two-factor/qr-code
profile.two-factor.recovery-codes.index       GET    /profile/two-factor/recovery-codes
profile.two-factor.recovery-codes.store       POST   /profile/two-factor/recovery-codes
```

Forbidden:

- Domain verbs as the trailing segment: `profile.two-factor.enable`, `profile.two-factor.regenerate`, `profile.two-factor.confirm`. Use the REST verb that matches the HTTP method instead (`store`, `destroy`, etc.). The action class name (`EnableEmployeeTwoFactorAction`) carries the domain verb; the route name does not.
- Names without a verb segment: `profile.two-factor` is ambiguous between `index`, `show`, `store`. Always include the trailing verb.

Two well-known exceptions:

- **`logout`** — a single endpoint, no sibling verbs, conventional name. Acceptable.
- **`home`** — the `/` redirect. Acceptable.

A new exception requires a documented reason in the route file.

## `Route::resource` vs explicit routes

`Route::resource(...)` and `Route::singleton(...)` (Laravel 11+) auto-generate the right names for plain CRUD. Reach for them when the controller exposes only `index/show/create/store/update/destroy`:

```php
Route::resource('documents', DocumentController::class)->only(['index', 'show', 'store']);
```

When the controller has methods outside the REST set (`confirm`, `qrCode`, `secretKey`, …), do **not** mix `Route::resource(...)` with manual `Route::get/post(...)` for the custom verbs — write all routes explicitly inside a nested `Route::prefix(...)->name(...)->group(...)`. Reading the route file should reveal every endpoint that controller serves, in one place.

## Skeleton — nested feature file

```php
<?php

declare(strict_types=1);

use App\Interfaces\AdminWeb\Controllers\TwoFactor\TwoFactorAuthenticationController;
use App\Interfaces\AdminWeb\Controllers\TwoFactor\TwoFactorRecoveryCodesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:admin')
    ->prefix('profile')
    ->name('profile.')
    ->group(function (): void {
        Route::prefix('two-factor')
            ->name('two-factor.')
            ->group(function (): void {
                Route::post('/', [TwoFactorAuthenticationController::class, 'store'])->name('store');
                Route::delete('/', [TwoFactorAuthenticationController::class, 'destroy'])->name('destroy');

                Route::post('confirmation', [TwoFactorAuthenticationController::class, 'confirm'])
                    ->name('confirmation.store');

                Route::name('recovery-codes.')->group(function (): void {
                    Route::get('recovery-codes', [TwoFactorRecoveryCodesController::class, 'show'])->name('index');
                    Route::post('recovery-codes', [TwoFactorRecoveryCodesController::class, 'store'])->name('store');
                });
            });
    });
```

Indentation mirrors URL nesting. `prefix(...)->name(...)` always come together so the URL and the name move in lock-step.

## See also

- [Architecture § folder layout](../architecture.md#folder-layout) — where `Interfaces/<EntryPoint>/Routes/` sits.
- [Controllers § style](controllers.md#style) — resource-style controllers with descriptive method names back the route names defined here.
- [Request data](request-data.md) — the input contract for routes that mutate state.
