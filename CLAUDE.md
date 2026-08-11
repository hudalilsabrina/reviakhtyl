# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Reviactyl Panel — open-source game server management panel (fork of Pterodactyl). Runs game servers in isolated Docker containers.

## Commands

```bash
# Frontend (pnpm, Node 22+, pnpm 11)
pnpm install          # install JS deps
pnpm dev              # Vite HMR
pnpm run build        # prod assets → public/build/
pnpm run watch        # rebuild on change
pnpm tsc              # TypeScript type-check (no emit)
pnpm lint             # ESLint on resources/scripts/**/*.{ts,tsx}
pnpm test             # vitest (JS tests)
pnpm test:ui          # vitest with UI
pnpm coverage         # vitest with coverage

# Backend (Composer, PHP 8.3+)
composer install      # install PHP deps (triggers filament:upgrade via post-autoload-dump)
php artisan serve     # local dev server
php artisan migrate --force  # run migrations (--force skips prod confirmation)

# PHP linting
composer pint:fix     # auto-fix style
composer pint:check   # check style

# Static analysis
./vendor/bin/phpstan analyse   # level 5, app/ only (excludes app/Livewire, app/Repositories)

# Run a single test
pnpm vitest run path/to/test.spec.ts
vendor/bin/pest --filter="TestName"
```

## Tech Stack

- **Backend**: Laravel 13, Filament v5 (admin), Sanctum v4 (API auth), Socialite v5 (Discord OAuth)
- **Frontend**: React 19, TypeScript ~5.6, Vite 7, Tailwind CSS 3, styled-components 6 (via twin.macro), Redux (easy-peasy), React Router 6, i18next
- **Database**: MariaDB 11
- **Infrastructure**: Docker Compose (panel + MariaDB + Redis), Redis (cache/sessions/queue)

## Architecture

### Backend (Laravel)

Layered architecture with a service/repository pattern:

- **`app/Http/Controllers/`** — API controllers, split by domain: `Api/Application`, `Api/Client`, `Api/Public`, `Api/Remote` (see `routes/api-*.php`)
- **`app/Models/`** — Eloquent models (50+). Uses Attributes, Filters, Objects subdirectories for value objects
- **`app/Services/`** — Business logic (25 subdirectories: Servers, Backups, Schedules, Chatbot, Mods, Plugins, Properties, Subusers, etc.)
- **`app/Repositories/`** — Data access layer (excluded from PHPStan)
- **`app/Transformers/`** — Fractal transformers for API response formatting
- **`app/Contracts/`** — Interfaces by domain (Core, Criteria, Extensions, Http, Models, Repository)
- **`app/Events/` / `app/Listeners/`** — Event-driven (Auth, Server, Subuser, User)
- **`app/Jobs/`** — Queued jobs and scheduled tasks
- **`app/Filament/`** — Filament v5 admin panel (Resources, Pages, Widgets, Components), NOT in `routes/`
- **`app/Livewire/`** — Livewire components (excluded from PHPStan)
- **`app/Extensions/`** — Extension wrappers for third-party packages

### Frontend (React + Vite)

- **Entry**: `resources/scripts/index.tsx` (via `laravel-vite-plugin`)
- **Path aliases**: `@` → `resources/scripts/`, `@definitions` → API type definitions, `@feature` → server feature components
- **State**: easy-peasy (lightweight Redux) with Redux DevTools
- **Routing**: React Router v6
- **Styling**: Tailwind CSS + twin.macro (babel plugin mapping to styled-components)
- **Reviactyl UI layer**: `resources/scripts/reviactyl/` — own elements, UI components, layouts (separate from upstream Pterodactyl `components/`)
- **Extension runtime**: Extensions in `extensions/` directory are compiled by `resources/scripts/extensions/compile-extension.mjs` and loaded at runtime via `window.__REVIACTYL_MODULES`

### API Structure

Four API route files split by audience:
- `routes/api-application.php` — authenticated panel operations
- `routes/api-client.php` — game server owner endpoints
- `routes/api-public.php` — unauthenticated endpoints
- `routes/api-remote.php` — Wings/agent communication

## Tests

- **PHP**: Pest (`phpunit.xml` defines `Unit` and `Feature` suites). Tests use in-memory SQLite (`:memory:`) — no real DB queries allowed. External services use `array` driver.
- **JS**: Vitest (config in `vite.config.ts`), happy-dom environment. Test files match `**/*.{spec,test}.{ts,tsx}`.

## Parallel AI Worktrees

When working on multiple independent tasks in parallel (multi-agent or successive sessions), use git worktrees — one branch + one working directory per agent, so agents never collide on files and the main tree stays clean.

```bash
git worktree add ../reviactyl-wt1 -b ai/agent-1
git worktree add ../reviactyl-wt2 -b ai/agent-2
git worktree list          # show all
git worktree remove ../reviactyl-wt1   # cleanup
```

Existing worktrees (as of 2026-08-11):
- `/var/www/reviactyl-wt1` → branch `ai/agent-1`
- `/var/www/reviactyl-wt2` → branch `ai/agent-2`
- `/var/www/reviactyl-agent` → branch `work/agent`

Rules:
- **Always create a fresh worktree per agent** when asked to do parallel work — never let two agents edit the same working directory.
- Worktrees share one `.git` object store (not clones): a commit in one worktree appears in the others' history immediately, but files/`node_modules`/`vendor`/`public/build` are NOT shared.
- Install deps in each worktree before building: `pnpm install` + `pnpm run build` (or `composer install` for PHP work).
- `.env` and `storage/framework/{cache,views,sessions}` are git-ignored, so they do NOT come along with a new worktree. Before `composer install` in a fresh worktree:
  ```bash
  cp /var/www/reviactyl/.env <worktree>/.env
  mkdir -p <worktree>/storage/framework/{cache,views,sessions}
  ```
  Without this, `composer install` fails at the `filament:upgrade` post-autoload hook ("Please provide a valid cache path" — the View Compiler needs `storage/framework/views`).
- After finishing an agent's task, merge its branch back to `master` from the worktree: `cd <wt> && git checkout master && git merge <branch>`, then `git worktree remove <wt>`.

## Key Gotchas

- Missing `public/build/manifest.json` → 500 error; run `pnpm run build` once after install
- `composer install` triggers `filament:upgrade` via `post-autoload-dump` hook
- Composer platform pinned to PHP 8.3 in `composer.json`
- JS tests use vitest (not jest, despite jest deps remaining in package.json — legacy)
- i18n: PHP translations in `resources/lang/<locale>/`, client-side via i18next multiload backend
- PHP helpers: `is_digit()` and `object_get_strict()` in `app/helpers.php` (autoloaded via composer `files`)

## Feature Documentation

Cross-cutting features have dedicated docs in `docs/<feature>/AGENTS.md`:
- `docs/subdomain/` — Cloudflare SRV-record subdomains
- `docs/mods/` — Minecraft mod installer (Modrinth)
- `docs/plugins/` — Minecraft plugin installer (Modrinth/Hangar/SpigotMC)
- `docs/properties/` — Minecraft server.properties editor
- `docs/chatbot/` — AI assistant with tool calling
- `docs/modular-startup/` — Egg-defined toggleable startup fragments

Read the relevant `AGENTS.md` before working on a feature — they document architecture, API, models, frontend, and known limitations.
