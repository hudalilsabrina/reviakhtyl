# Project Context

## Environment
- Language: PHP 8.3+ (Laravel 13) + TypeScript (React 19)
- Build: `pnpm run build` (frontend), `composer install` (backend)
- Verify TS: `pnpm tsc`, `pnpm lint`, `pnpm test` (vitest)
- Lint PHP: `composer pint:check` / `composer pint:fix`
- Static: `./vendor/bin/phpstan analyse` (level 5, app/ only, excludes app/Livewire app/Repositories)
- No tests/ dir, no CI.

## Structure
- Admin: `app/Filament/` (Filament v5)
- Client API: `routes/api-client.php` → `app/Http/Controllers/Api/Client/Servers/`
- Client frontend: `resources/scripts/` (Reviactyl layer at `resources/scripts/reviactyl/`)
- Server services: `app/Services/Servers/`
- Models: `app/Models/`

## Conventions
- Pint preset laravel; custom rule new_with_parentheses.anonymous_class=false
- Controllers thin; logic in Services
- Client API requests in `app/Http/Requests/Api/Client/Servers/`
- Frontend server routes in `resources/scripts/routers/routes.ts`, containers under `components/server/`

## Current Mission
Server Splitter: split cpu/memory/disk (NOT allocations) from a server into a new child server on same node. Merge returns resources + deletes child.
- servers: add `parent_id` (nullable self-FK, cascade null), `split_limit` int default 0 (admin-set max children).
- Child: same node, same user, same egg/nest; own allocation (auto-assigned from same node); no nested splits.
- Guards: parent not suspended, not installing, both stopped for split/merge; child resources ≥0; parent remainder ≥0; split_limit enforced.
- Endpoints (client API, prefix /servers/{server}):
  - GET /splits — list children + remaining resources
  - POST /splits — create split {name, cpu, memory, disk}
  - POST /splits/{child}/merge — merge child back
- Admin: ServerResource form fields `split_limit`; show parent/children.
- Frontend: new tab "Splitter" in server router; form + children list + merge button.
