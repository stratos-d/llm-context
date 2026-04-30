# Factories

> **Owns**
>
> - Factory location (`Domains/<X>/Database/Factories/`)
> - The project's `HasFactory` trait and its auto-resolution rule
> - Factory class skeleton
> - Named-state convention
> - What belongs / does not belong in a factory
>
> **Forbids**
>
> - Business rules and invariants — see [Actions](../actions.md)
> - Cross-aggregate orchestration — use sequences / `has()` / `for()` at the call site
> - Database seeding — that is a separate concern (seeders *use* factories)
>
> **See also**: [Models](models.md), [Builders](builders.md), [Architecture](../architecture.md)

Factories produce valid model rows for tests and seeders. Each model has exactly one factory; the project resolves the factory class automatically from the model namespace, so concrete models never override `newFactory()`.

> Names like `Employee` / `EmployeeFactory` in code samples are illustrative.

## Location

Factories live next to the model that owns them, under the domain's `Database/Factories/` folder. There is **no central `database/factories/` directory** in this project.

```text
app/Domains/<DomainName>/
├── Models/<ModelName>.php
└── Database/Factories/<ModelName>Factory.php
```

See [Architecture § folder layout](../architecture.md#folder-layout) for the full domain tree.

## Auto-resolution

The project-wide `HasFactory` trait lives at `App\Infrastructure\Eloquent\Concerns\HasFactory`. It is pulled in by `BaseModel` and `BaseAuthenticatable` (see [Models § base classes](models.md#basemodel)), so every concrete model inherits it. Its `newFactory()` override resolves the factory class from the model's namespace by simple string substitution:

```text
App\Domains\<X>\Models\<Name>
  → App\Domains\<X>\Database\Factories\<Name>Factory
```

Consequences:

- **No `newFactory()` override** is ever needed on a concrete model.
- **No `protected $model` lookup magic** is relied on — the trait computes the factory class name directly from `static::class`.
- If the matching factory class does not exist, PHP throws `Class "…Factory" not found` at the call site, pointing at the exact path the factory should occupy. Silent fallback is deliberately avoided.
- Laravel's own `HasFactory` is composed in by the project trait, so `Model::factory(3)->state([...])` and all count / state sugar keep working.

This is the rule that breaks if a model's namespace stops mirroring its folder path — see [Conventions § namespaces mirror the folder path](../conventions.md#namespaces-mirror-the-folder-path).

## Factory class skeleton

Factories extend Laravel's `Factory` and declare the model they build. Use named states for **variations**, not inline conditionals.

```php
<?php

declare(strict_types=1);

namespace App\Domains\<DomainName>\Database\Factories;

use App\Domains\<DomainName>\Models\<ModelName>;
use Illuminate\Database\Eloquent\Factories\Factory;

class <ModelName>Factory extends Factory
{
    protected $model = <ModelName>::class;

    public function definition(): array
    {
        return [
            // base attributes — the "happy path" row
        ];
    }

    public function <state>(): static
    {
        return $this->state(fn (array $attributes) => [
            // variation
        ]);
    }
}
```

## Named-state convention

State methods read as **adjectives or short phrases**, never as verbs implying side effects:

```text
unverified()
disabled()
withTwoFactor()
withoutEmailConfirmation()
```

Not:

```text
disable()
verifyEmail()
enableTwoFactor()
```

The verb forms describe writes — those are actions, not factory states. See [Actions § naming](../actions.md#naming).

## What belongs in a factory

- **Definition of a valid row** — the minimum attributes required to persist the model.
- **Named states** for common variations.
- **Calls to `fake()`** for synthetic data. Do not hard-code realistic values in `definition()`.

## What does not belong in a factory

- Business rules or invariants — those live in actions ([Actions](../actions.md)).
- Relationships that imply orchestration across aggregates — use Eloquent's call-site composition (`Sequence`, `has()`, `for()`) instead of building a "create everything" factory state.
- Database seeding of fixtures — that belongs in seeders, which *use* factories.

## See also

- [Models](models.md) — the model that the factory builds.
- [Builders](builders.md) — used by tests to query factory-produced rows.
- [Conventions](../conventions.md) — the namespace rule auto-resolution depends on.
- [Architecture § folder layout](../architecture.md#folder-layout) — the domain tree factories live in.
