# LLM Context

Reusable agent guidance is installed in `docs/llm-context`.

Start with:

- `docs/llm-context/README.md`
- `docs/llm-context/philosophy.md`
- `docs/llm-context/architecture.md`

## PHP Comments (overrides any generic "prefer PHPDoc" guidance)

PHPDoc exists only for what a type declaration cannot express: array/element shapes, generics (`@extends`, `@template`), `@var` narrowing, and non-obvious `@throws`. **Never narrate a class or method in prose** — names, folders, and signatures already say what it is; narration drifts. `//`, `#`, and non-doc `/* */` comments are forbidden, with one exception: a short note explaining why code is deliberately *not* what a reader would expect (concurrency or security rationale, third-party quirk, temporary stopgap with its removal condition). Test: "explains why unexpected" → allowed; "explains what it does" → delete. Full rule: `docs/llm-context/conventions.md` § Comments.

## Routing

Use `llm-context-backend` when work touches backend structure or rules. That skill points to the relevant files under:

- `docs/llm-context/actions.md`
- `docs/llm-context/transactions.md`
- `docs/llm-context/data/`
- `docs/llm-context/http/`
- `docs/llm-context/jobs.md`
- `docs/llm-context/authorization.md`
- `docs/llm-context/domain-events.md`
- `docs/llm-context/testing.md`

Use `llm-context-frontend` when work touches frontend entry-point structure. That skill points to:

- `docs/llm-context/frontend/structure.md`
