<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

**How to activate**: when a skill's trigger applies, **read its `SKILL.md` body before editing code**. Skill bodies live at `llm-context/.agents/skills/<skill>/SKILL.md` (the `.claude/skills/` and `.windsurf/skills/` paths are symlinks to the same files). Some skills bundle additional `rules/` or asset directories alongside `SKILL.md` — load those as referenced from the skill body. The bullet below each skill summarises *when* to activate; the body explains *how* to apply.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
  Body: [`.agents/skills/laravel-best-practices/SKILL.md`](.agents/skills/laravel-best-practices/SKILL.md).
- `wayfinder-development` — Use this skill for Laravel Wayfinder which auto-generates typed functions for Laravel controllers and routes. ALWAYS use this skill when frontend code needs to call backend routes or controller actions. Trigger when: connecting any React/Vue/Svelte/Inertia frontend to Laravel controllers, routes, building end-to-end features with both frontend and backend, wiring up forms or links to backend endpoints, fixing route-related TypeScript errors, importing from @/actions or @/routes, or running wayfinder:generate. Use Wayfinder route functions instead of hardcoded URLs. Covers: wayfinder() vite plugin, .url()/.get()/.post()/.form(), query params, route model binding, tree-shaking. Do not use for backend-only task
  Body: [`.agents/skills/wayfinder-development/SKILL.md`](.agents/skills/wayfinder-development/SKILL.md).
- `inertia-react-development` — Develops Inertia.js v3 React client-side applications. Activates when creating React pages, forms, or navigation; using <Link>, <Form>, useForm, useHttp, setLayoutProps, or router; working with deferred props, prefetching, optimistic updates, instant visits, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation.
  Body: [`.agents/skills/inertia-react-development/SKILL.md`](.agents/skills/inertia-react-development/SKILL.md).
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.
  Body: [`.agents/skills/tailwindcss-development/SKILL.md`](.agents/skills/tailwindcss-development/SKILL.md).

Other skills available under [`.agents/skills/`](.agents/skills/) — activate when their `description:` frontmatter matches the task: `fortify-development`, `vercel-composition-patterns`, `vercel-react-best-practices`, `web-design-guidelines`.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

<!--
  The section below is NOT managed by Laravel Boost.
  Keep project-specific agent context here so `php artisan boost:install`
  (which rewrites everything inside the <laravel-boost-guidelines> tags)
  does not overwrite it.
-->

# Project Context (not managed by Boost)

## Work pacing

When the user asks for one step in a larger feature, do only that requested
step. Do not proactively add tests, run broad analysis, or continue into
adjacent implementation work unless explicitly approved. If verification is
premature because the feature is incomplete, state that and suggest the next
steps instead.

## Infrastructure — this app runs in Docker

Local development runs entirely inside Docker. **Do not** run `php`,
`composer`, `artisan`, `npm`, `psql`, or `redis-cli` directly on the host —
they either won't exist or will produce UID-mismatched files in bind mounts.

- **Docker Compose lives in a sibling repo**, not this one: `../dev-infrastructure/`.
  - `../dev-infrastructure/main/compose.yml` — app stack: `php`, `nginx`, `node`, `postgres`, `redis`.
  - `../dev-infrastructure/caddy/compose.yml` — edge reverse proxy (`app.local`).
- **The PHP container is `app-main-php`.** Anything that needs to exec PHP (including MCP servers) must `docker exec` into it.
- **Always use `make` targets.** The `Makefile` already resolves compose file paths, service names, and exec users. Examples: `make up`, `make shell`, `make artisan ARGS="migrate"`, `make composer ARGS="require vendor/pkg"`, `make npm ARGS="install"`, `make npm-dev`.
- `make help` lists all targets. If a target is missing, extend the Makefile rather than hand-rolling `docker compose -f ../dev-infrastructure/...` commands.

Full reference: [`docs/infrastructure.md`](docs/infrastructure.md).

## Other project docs

Start here: [`docs/README.md`](docs/README.md) — index of all topic files with a recommended reading order.

High-traffic files for cold starts (read in this order):

- [`docs/philosophy.md`](docs/philosophy.md) — the project's architectural stance: *layered architecture with DDD-flavoured naming, pragmatically scoped abstractions, and a deliberately anaemic domain model*. Read first.
- [`docs/architecture.md`](docs/architecture.md) — folder layout, layer responsibilities, bounded contexts, aggregates, request flow.
- [`docs/adding-a-context.md`](docs/adding-a-context.md) — recipe + worked `Notes` example for adding a new bounded context end-to-end.
- [`docs/actions.md`](docs/actions.md) — Domain action vs Application action, the placement test, result objects, when to return `bool` vs a `*Result`.
- [`docs/transactions.md`](docs/transactions.md) — the sole-root rule: only Application actions open `DB::transaction()`. When to wrap, when not to.
- [`docs/cross-context.md`](docs/cross-context.md) — how bounded contexts collaborate: domain events, published actions, published read models.
- [`docs/authorization.md`](docs/authorization.md) — Policies live per-aggregate (Laravel auto-discovered); `Gate::authorize` runs at the delivery boundary, never inside actions.
- [`docs/exceptions.md`](docs/exceptions.md) — `DomainException` hierarchy, throw / don't-catch policy, central `bootstrap/app.php` mapping.
- [`docs/jobs.md`](docs/jobs.md) — queued use cases as thin wrappers in `Application/<UseCase>/Jobs/`; `afterCommit` discipline; listener-vs-job split.
- [`docs/ports-and-adapters.md`](docs/ports-and-adapters.md) — when to introduce an interface and when not to; strategy-prefix naming for variant-anticipated services.
- [`docs/anti-patterns.md`](docs/anti-patterns.md) — grep-friendly red flags + worked refactor example.
- [`docs/frontend/structure.md`](docs/frontend/structure.md) — frontend entry-point boundaries and frontend UI structure.
- [`docs/data/models.md`](docs/data/models.md) — base classes, model wiring, anaemic-model framing, behaviour-promotion rule.
- [`docs/glossary.md`](docs/glossary.md) — A–Z definitions for every DDD-flavoured term used across the docs.

Frontend boundary note: the canonical rule lives in [`docs/frontend/structure.md`](docs/frontend/structure.md).
Treat each frontend application surface as its own entry point under `resources/js/<entry-point>/`.
Future frontend surfaces must be planned as separate entry points, not added into an unrelated tree.

In-flight feature plans: [`agent-plans/`](agent-plans/).
