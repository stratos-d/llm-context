07# Frontend structure

> **Owns**
>
> - The frontend entry-point boundaries under `resources/js/`
> - The canonical folder layout for dedicated Inertia frontend applications
> - Rules for `shared/` frontend code
> - The requirement to leave room for additional frontend entry points
>
> **Forbids**
>
> - Backend folder layout and layer responsibilities — see [Architecture](../architecture.md)
> - Inertia page-prop shaping — see [View data](../http/view-data.md)
>
> **See also**: [Architecture](../architecture.md), [View data](../http/view-data.md), [README](../README.md)

This file is the single source of truth for **where frontend code lives** and
**how frontend application surfaces are separated**.

## Entry-point rule

Frontend code follows the same entry-point separation as
`Interfaces/<EntryPoint>/` on the backend. A frontend for one application
surface does **not** live in a shared catch-all `resources/js/pages` tree once
that surface has its own entry point.

Each dedicated frontend entry point gets its own sibling directory under
`resources/js/`, named after the application surface it serves. Do not extend
one entry point's tree with pages and components that belong to a different
application surface.

## Canonical layout

```text
resources/
├── css/
│   └── <entry-point>.css
└── js/
    ├── <entry-point>/
    │   ├── app.tsx
    │   ├── ssr.tsx
    │   ├── pages/
    │   ├── layouts/
    │   ├── components/
    │   ├── features/
    │   ├── hooks/
    │   ├── lib/
    │   └── types/
    ├── shared/
    │   ├── components/
    │   ├── hooks/
    │   ├── lib/
    │   └── types/
    ├── actions/
    ├── routes/
    └── wayfinder/
```

Additional frontend entry points should be added as sibling directories under
`resources/js/`, not as branches inside an existing entry point.

## Rules

- `resources/js/<entry-point>/` is the root for one frontend application surface.
- Pages, layouts, and feature-specific components stay inside their owning entry-point tree.
- Cross-entry-point reuse goes in `resources/js/shared/`, not in another entry point's tree.
- Do not import from one `resources/js/<entry-point>/**` tree into another.
- `resources/js/actions/`, `resources/js/routes/`, and `resources/js/wayfinder/` remain shared integration surfaces unless there is an explicit reason to split them.
- `shared/` is for code reused across entry points; if code is only used by one entry point, keep it in that entry point's tree.

## Folder responsibilities

- `pages/` — Inertia route-level pages only.
- `layouts/` — page shells and persistent layout composition.
- `components/` — reusable entry-point-specific presentation components.
- `features/` — entry-point-specific components reused across multiple pages.
- `hooks/` — reusable React hooks scoped to the entry point.
- `lib/` — pure helpers and framework-light utilities.
- `types/` — TypeScript types scoped to the entry point.

## Planning rule

When introducing a frontend abstraction, ask one question first:

Would this still make sense if a second frontend entry point existed as a
sibling directory?

If the answer is no, the abstraction belongs in its current entry point only or
should be redesigned before it spreads.
