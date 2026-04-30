# Infrastructure

Local development runs **entirely inside Docker**. There is no expectation of
PHP, Node, Postgres, or Redis being installed on the host.

## Repository layout

The infrastructure is a sibling repository, not part of this project:

```
<workspace-root>/
├── application/           ← this Laravel application
└── dev-infrastructure/    ← Docker Compose stacks (separate git repo)
    ├── main/              ← app services: php, nginx, node, postgres, redis
    │   ├── compose.yml
    │   ├── nginx/
    │   └── php/
    └── caddy/             ← reverse proxy for *.local hostnames
        ├── compose.yml
        └── Caddyfile
```

When an agent or script needs to inspect infra config, the path from this
project is `../dev-infrastructure/`.

## Services (main stack)

Defined in `../dev-infrastructure/main/compose.yml`. All services are attached
to an external Docker network named `app-network` (created once, shared with
the Caddy stack).

| Service    | Container name            | Image                         | Purpose                                            |
| ---------- | ------------------------- | ----------------------------- | -------------------------------------------------- |
| `php`      | `app-main-php`      | `app/main:latest`      | PHP-FPM 8.5 running the Laravel app (`/var/www`).  |
| `nginx`    | `app-main-nginx`    | `nginx:1.27-alpine`    | Upstream for `php-fpm`; fronted by Caddy.          |
| `node`     | `app-main-node`     | `node:24-bookworm-slim` | Vite dev server on port 5173; idle `tail -f` by default — run `make npm-dev` to start it. |
| `postgres` | `app-main-postgres` | `postgres:17-bookworm` | Primary datastore. DB `app`, user `app`, password `secret`. |
| `redis`    | `app-main-redis`    | `redis:7-bookworm`     | Cache, session, queue.                             |

The project source is bind-mounted at `/var/www` inside `php`, `nginx`, and
`node`, so edits on the host reflect immediately.

## Services (edge stack)

Defined in `../dev-infrastructure/caddy/compose.yml`. Caddy listens on 80/443
on the host and proxies to the `nginx` container over the shared
`app-network` network. Local hostname: **`app.local`** (add to
`/etc/hosts` pointing at `127.0.0.1` if not already).

## Interacting with the stack

**Always go through the `Makefile`**, which wraps the compose files with the
correct `-f` flags and service names. Common commands:

```bash
make up                       # start main stack (detached)
make down                     # stop main stack
make status                   # docker compose ps
make logs                     # follow logs

make shell                    # bash into the php container
make node-shell               # bash into the node container
make psql                     # psql into postgres
make redis-cli                # redis-cli into redis

make artisan ARGS="migrate"                # php artisan … inside php container
make composer ARGS="require vendor/pkg"    # composer inside php container
make npm ARGS="install"                    # npm inside node container
make npm-dev                               # Vite dev server (foreground)
```

**Never run** `php artisan …`, `composer …`, or `npm …` directly on the host.
They will either fail (missing binaries) or produce host-owned artifacts that
conflict with container UIDs.

## Environment

- PHP container runs as user `project` (uid 1000) to match the typical host
  developer uid, avoiding root-owned files in bind mounts.
- Node container is pinned to `1000:1000` for the same reason.
- Database credentials for local dev are hard-coded in the main compose file
  (`app` / `secret`). The app reads them from `.env` — keep the two in
  sync when overriding.
- Redis has AOF persistence enabled (`--appendonly yes`).

## One-time setup

If the `app-network` Docker network does not yet exist on the host:

```bash
docker network create app-network
```

Then from this project:

```bash
make up
make composer ARGS="install"
make artisan ARGS="key:generate"
make artisan ARGS="migrate"
make npm ARGS="install"
make npm-dev   # leave running in a second terminal
```

If using the Caddy edge stack:

```bash
docker compose -f ../dev-infrastructure/caddy/compose.yml up -d
```

## For agents

- When executing commands, prefer the **Makefile targets** — they already
  resolve compose file paths, container names, and exec users correctly.
- If a target does not exist for what you need, extend the `Makefile` rather
  than hand-rolling a `docker compose -f ../dev-infrastructure/...` command
  in an ad-hoc shell call.
- The Laravel Boost MCP server (`database-query`, `database-schema`, etc.)
  connects to the Postgres container from inside the `php` container, so
  Boost tools work transparently with the Dockerized database.
- If an agent-facing command (e.g. the Codex CLI's MCP config) needs to run
  Boost, it must `docker exec` into `app-main-php`. See
  `.codex/config.toml` for the working example.
