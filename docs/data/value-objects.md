# Value objects

> **Owns**
>
> - When to introduce a value object (the three-occurrence rule)
> - Value-object skeleton and equality semantics
> - Eloquent cast pattern for persisting value objects
> - Where value objects live (per-context, with co-location escape hatch)
> - Naming
>
> **Forbids**
>
> - Persistence (value objects are immutable values; they do not save themselves)
> - I/O of any kind
> - Identity by reference — two `Email("a@b.c")` are equal
> - Mutators and setters — value objects are `readonly`
>
> **See also**: [Models](models.md), [Conventions](../conventions.md), [Architecture](../architecture.md), [Glossary](../glossary.md)

A value object wraps one or more primitives with **validation** (in the constructor), **identity by value** (two instances with the same data are equal), and a **vocabulary name** that means something in the domain.

> Names like `Email`, `DateRange`, `EmployeeId`, `PendingLogin` are illustrative.

## When to introduce one

The **three-occurrence rule**: when the same primitive (with the same validation rules or the same domain meaning) appears in three or more method signatures across a context, promote it to a value object.

Common signals:

- The same `string $email` parameter shows up in three places, each followed by `mb_strtolower(...)` or a regex check.
- Two date parameters travel together (`$startsAt`, `$endsAt`) and the second clarifies the first.
- A primitive has invariants: an `Email` cannot be empty and must look like an email; a `DateRange` cannot end before it starts.

Do **not** promote on first or second sight. Premature value objects clutter the call site without paying back the indirection.

## When not to introduce one

Don't wrap a single primitive in a class when it carries no invariant the primitive can't.

Failure modes seen in this codebase:

- **One-bit-with-a-name.** A `bool` for "remember the login" became a `RememberPolicy` class with `yes()` / `no()` / `fromBool()` / `shouldRemember()` \u2014 33 lines of code for one bit of state. Use `bool $remember` directly. The parameter name carries the meaning at every call site; PHPStorm catches positional mistakes; tests don't need to construct anything.
- **Wrapper that just renames.** A `final readonly class Username { public string $value; }` with no validation, no derived state, no methods is just `string`. Use `string`.
- **Anticipated invariant.** "It might one day need validation" is the same trap as "we might one day swap implementations" \u2014 see [Ports and adapters \u00a7 the trigger rule](../ports-and-adapters.md#the-trigger-rule). When the validation actually arrives, promote then; the refactor is mechanical.

The rule of thumb: **a value object earns its keep when it carries an invariant a primitive can't, or when it has 3+ valid constructions / forms.** A pure-naming wrapper is overhead.

## Where they live

- **Context-scoped value objects** that describe one bounded context's vocabulary live in `Domains/<ContextName>/ValueObjects/<Name>.php`.
- **Use-case-scoped value objects** introduced by an Application action (e.g. `PendingLogin` for the EmployeeAuth use case) live in `Application/<UseCase>/<Name>.php` next to the action that introduced them. Promote to a context-level VO only when a second use case starts depending on it.
- **Cross-context shared primitives** (`DateRange`, `CarbonImmutable` wrapper, generic `Email` if used everywhere) live in their own bounded context — typically a tiny `Domains/Shared/ValueObjects/` if and only if there is a real shared kernel. Avoid `Domains/Shared/` until at least three contexts genuinely depend on the same VO.

A value object is **never** in `Infrastructure/`. Value objects are part of the domain (or application) layer; infrastructure consumes them, not defines them.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\ValueObjects;

use InvalidArgumentException;

final readonly class Email
{
    public function __construct(public string $value)
    {
        if ($this->value === '' || ! filter_var($this->value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email: '{$this->value}'.");
        }
    }

    public function localPart(): string
    {
        return mb_strstr($this->value, '@', true) ?: '';
    }

    public function domain(): string
    {
        return mb_substr(mb_strstr($this->value, '@') ?: '', 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

Rules:

- `final readonly class`. Immutability is the point.
- Public readonly properties. No private state, no setters.
- Validation in the constructor. A value object that can be in an invalid state defeats its purpose.
- Equality by value. Provide `equals(self $other): bool` when callers need to compare; PHP's `==` works on simple cases but is unsafe for nested VOs.
- Stringification when the underlying primitive has an obvious textual form. Avoid for compound VOs (`DateRange`, `Address`) — explicit accessors are clearer.

### Compound value object

```php
final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {
        if ($this->endsAt->lessThan($this->startsAt)) {
            throw new InvalidArgumentException('DateRange cannot end before it starts.');
        }
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->greaterThanOrEqualTo($this->startsAt)
            && $date->lessThanOrEqualTo($this->endsAt);
    }

    public function equals(self $other): bool
    {
        return $this->startsAt->equalTo($other->startsAt)
            && $this->endsAt->equalTo($other->endsAt);
    }
}
```

Operations return new instances; `$range->withEnd($newEnd)` would return a new `DateRange`, not mutate `$range`.

## Eloquent cast pattern

When a value object is the type of a model attribute, persist it via a custom cast living next to the value object:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Employees\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<Email|null, Email|string|null>
 */
final class EmailCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Email
    {
        return $value === null ? null : new Email((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Email => $value->value,
            default => (new Email((string) $value))->value,
        };
    }
}
```

Wire it on the model:

```php
protected function casts(): array
{
    return [
        'email' => EmailCast::class,
    ];
}
```

The cast file lives in the same folder as the value object. Suffix is `<Name>Cast`. One cast per VO; do not bundle multiple VO casts into a single file.

## Naming

- VO class names are nouns from the domain vocabulary. `Email`, `DateRange`, `EmployeeId`, `Locale`, `PendingLogin`.
- **No** `Object`, `VO`, `Value`, `DTO`, or `Type` suffix. The class lives in `ValueObjects/`; the suffix would be redundant. See [Conventions § value-object naming](../conventions.md#value-object-naming).
- Variable names follow the same rule as classes (camelCase of the class name): `Email $email`, `DateRange $dateRange`, `EmployeeId $employeeId`. See [Conventions § variable names match the class they hold](../conventions.md#variable-names-match-the-class-they-hold).

## What does not belong on a value object

- Persistence calls (`save()`, `update()`).
- I/O of any kind (HTTP, filesystem, database queries, vendor SDK calls).
- Mutating methods. `$dateRange->withEnd($newEnd)` returns a new `DateRange`; it does not modify `$this`.
- Behaviour that depends on a service/port. If a value object would need to call a port to compute a result, it is not a value object — it is a domain service or a use case input.

## Identifier value objects

Aggregate IDs (`EmployeeId`, `DocumentId`, `ActivityEventId`) are common value objects. Wrap them when:

- The codebase has more than one kind of ID flying around and confusing them would be a runtime error caught only by tests.
- The ID has a non-trivial format (ULID, UUID, prefixed string) that should be validated at the boundary.

```php
final readonly class EmployeeId
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $this->value)) {
            throw new InvalidArgumentException("Invalid EmployeeId: '{$this->value}'.");
        }
    }

    public static function fromModel(Employee $employee): self
    {
        return new self($employee->getKey());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

ID value objects are especially useful for **domain event payloads**, where the event must outlive the request scope and the model cannot — see [Domain events § payload rules](../domain-events.md#payload-rules).

## See also

- [Models](models.md) — the aggregates value objects are typed onto via casts.
- [Conventions § naming](../conventions.md#naming) — class and variable naming.
- [Architecture](../architecture.md) — where the `ValueObjects/` folder sits in the context tree.
- [Domain events](../domain-events.md) — the canonical consumer of identifier value objects.
- [Glossary](../glossary.md) — definition of *value object*.
