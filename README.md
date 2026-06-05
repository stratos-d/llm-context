# LLM Context

A reusable content tree for coding agents.

The repository contains structured guidance, conventions, and review criteria that can be attached to projects as agent context. The source material lives in `docs/`; generated bundles live in `build/`.

## Start Here

Read [docs/README.md](docs/README.md) for the full documentation index and recommended reading order.

## Bundles

Generate bundled context files with:

```bash
composer docs:bundle
```

The command writes:

- `build/docs-global-rules.md`
- `build/docs-backend-data-http.md`
- `build/docs-frontend.md`

`build/` is ignored because bundles are generated artifacts.

## Install Into A Project

From the target project root, run:

```bash
vendor/bin/llm-context
```

The command installs:

- `docs/llm-context/`
- `.ai/guidelines/llm-context/00-entrypoint.md`
- `.ai/skills/llm-context-backend/SKILL.md`
- `.ai/skills/llm-context-frontend/SKILL.md`

If the target project uses Laravel Boost, run Boost after installing this context so the custom guideline and skills are folded into the agent-specific files:

```bash
php artisan boost:install
php artisan boost:update
```

## Repository Boundary

Keep source content and install templates in this repo; keep generated tool/runtime files out of the repo root.

Source content belongs here:

- `docs/`
- `bin/bundle-docs.php`
- `bin/llm-context`
- `stubs/`
- `composer.json`
- `README.md`
- `LICENSE`

Tool-specific runtime files do not belong at the repo root:

- `AGENTS.md`
- `CLAUDE.md`
- `.ai/`
- `.agents/`
- `.claude/`
- `.windsurf/`
- `.mcp.json`
