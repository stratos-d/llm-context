# Builders

> **Owns**
>
> - `BaseBuilder` definition
> - When to introduce a custom builder class
> - Custom builder skeleton and filter naming convention
> - What belongs / does not belong in a builder
>
> **Forbids**
>
> - Writes of any kind — see [Actions](../actions.md)
> - Builder selection on a model — see [Models § wiring](models.md#wiring-a-model-to-a-custom-builder-class)
> - Project-authored scope traits — prefer a builder class
>
> **See also**: [Models](models.md), [Factories](factories.md), [Architecture](../architecture.md), [Actions](../actions.md)

A builder is the read-side surface for a model. Reusable filters, ordering, and eager-load presets live here so that controllers and actions never accumulate `->where(...)` chains.

> Names like `Employee` / `EmployeeBuilder` in code samples are illustrative — apply the pattern, not the literal naming.

## BaseBuilder

`BaseBuilder` is the Eloquent builder every model uses by default. Domain-specific builders extend it.

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Eloquent\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class BaseBuilder extends Builder {}
```

- `BaseBuilder` is **not** `abstract`: it is instantiated directly for any model that does not need a custom builder class.
- Add helpers here only when they apply to **every** model in the project. Prefer placing helpers on a domain-specific builder.
- `whereKey()` is already provided by Eloquent's `Builder` and covers the "find by primary key" case — no custom `whereId()` helper is needed.

## When to create a custom builder class

Create one when a model has any of:

- reusable read filters (e.g. active/disabled, verified/unverified)
- reusable ordering rules
- eager-load presets
- common search conditions
- scoping helpers shared across many call sites

A custom builder class is usually unnecessary for:

- simple pivot models
- short-lived token or import rows
- small lookup tables
- framework / support models with no business filtering

If a model does not need a custom builder class, it inherits `BaseBuilder` through its base class automatically — see [Models § wiring](models.md#wiring-a-model-to-a-custom-builder-class).

## Custom builder skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\Builders;

use App\Domains\Employees\Models\Employee;
use App\Infrastructure\Eloquent\Builders\BaseBuilder;

/**
 * @extends BaseBuilder<Employee>
 */
final class EmployeeBuilder extends BaseBuilder
{
    public function active(): static
    {
        return $this->whereNull('disabled_at');
    }

    public function disabled(): static
    {
        return $this->whereNotNull('disabled_at');
    }

    public function verified(): static
    {
        return $this->whereNotNull('email_verified_at');
    }

    public function whereEmail(string $email): static
    {
        return $this->where('email', mb_strtolower($email));
    }
}
```

The class-level `@extends BaseBuilder<Employee>` annotation lets PHPStan and the IDE narrow `query()` / `newQuery()` return types on the model side. See [Models § static analysis](models.md#static-analysis-and-ide-notes) for the model-side wiring this enables.

## What belongs in a builder

Reusable read-side logic. Methods describe **what to read**, never **what to change**.

Prefer names shaped like:

```text
active()
verified()
newest()
where<Attribute>(...)
for<Relation>(...)
with<Relation>(...)
```

Avoid methods that imply writes or side effects:

```text
disable<...>()
approve<...>()
publish<...>()
reserve<...>()
```

Those belong in actions (see [Actions](../actions.md)), not in a builder.

## What does not belong in a builder

- Writes of any form (`update`, `delete`, `forceFill`, `save`).
- Business rules that should be enforced on the write path.
- Side effects: events, notifications, third-party API calls.
- Project-authored scope traits. Prefer a single builder class over fragmenting filters across multiple traits — one mechanism is easier to reason about than several. Third-party traits that add scopes (Scout, Fortify, Sanctum) are fine; the rule is not to *author* your own.

## Example usage

Filters compose at the call site:

```php
$records = Employee::query()
    ->verified()
    ->newest()
    ->get();

$record = Employee::query()
    ->whereEmail($email)
    ->first();
```

If a controller or action ever needs `->where('column', $value)` directly, that is a missing builder method. Add it.

## See also

- [Models](models.md) — how a model wires itself to its builder via `#[UseEloquentBuilder]` and `@extends`.
- [Factories](factories.md) — the other half of the data layer.
- [Architecture § layer responsibilities](../architecture.md#layer-responsibilities) — where builders sit in the request flow.
- [Anti-patterns](../anti-patterns.md) — grep-friendly signals for builder-rule violations.
