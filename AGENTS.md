# AGENTS.md

Reviactyl Panel — fork of Pterodactyl. Laravel 13 + Filament v5 admin, React 19 + Vite client dashboard, PHP 8.3+, Node 22+, pnpm 11.

## Workflow

- **Commit after every code change.** User requirement: every change must be committed (no batching).

## Layout (non-obvious)

- `app/Filament/` — admin panel (Filament resources/pages/widgets), NOT in `routes/`.
- `resources/scripts/` — React client app (TS). Entry `resources/scripts/index.tsx` via `laravel-vite-plugin`.
- `resources/scripts/reviactyl/` — Reviactyl's own UI layer (elements/ui/layouts), distinct from upstream Pterodactyl `components/`.
- `extensions/` — Extensions API code; compiled via `resources/scripts/extensions/compile-extension.mjs`.
- `routes/api-*.php` — API split: application, client, public, remote.
- `app/helpers.php` — auto-loaded helper functions: `is_digit()`, `object_get_strict()`.
- No CI workflows in repo.

### Frontend path aliases (vite.config.ts + tsconfig.json)

- `@` → `resources/scripts/`
- `@definitions` → `resources/scripts/api/definitions/`
- `@feature` → `resources/scripts/components/server/features/`

## Commands

- Frontend: `pnpm install`, `pnpm run build` (prod assets → `public/build`), `pnpm run watch`, `pnpm dev` (HMR).
- Verify TS: `pnpm tsc` (alias `tsc --noEmit`), `pnpm lint`, `pnpm test` (vitest).
- Backend: `composer install`, `php artisan serve`, `php artisan migrate --force`.
- Lint PHP: `composer pint:fix` (fix) / `composer pint:check` (check). Pint preset `laravel`, custom rule `new_with_parentheses.anonymous_class: false`.
- Static analysis: `./vendor/bin/phpstan analyse` (level 5, `app/` only; `app/Livewire`, `app/Repositories` excluded).
- Missing `public/build/manifest.json` → 500 error; run `pnpm run build` once.

### Run a single test

```bash
pnpm vitest run path/to/test.spec.ts   # JS
vendor/bin/pest --filter="TestName"    # PHP
```

## Tests

- PHP: Pest (Unit + Feature suites in `phpunit.xml`). Uses in-memory SQLite (`:memory:`) with no migrations. **`tests/TestCase::setUp()` fails on any DB query** — tests must use mocks/fakes, never hit a real database.
- JS: vitest with happy-dom environment. Test files match `**/*.{spec,test}.{ts,tsx}`.

## Feature docs

Cross-cutting features have dedicated `AGENTS.md` files under `docs/<feature>/`:

- [`docs/chatbot/AGENTS.md`](docs/chatbot/AGENTS.md)
- [`docs/datapacks/AGENTS.md`](docs/datapacks/AGENTS.md)
- [`docs/mods/AGENTS.md`](docs/mods/AGENTS.md)
- [`docs/modular-startup/AGENTS.md`](docs/modular-startup/AGENTS.md)
- [`docs/plugins/AGENTS.md`](docs/plugins/AGENTS.md)
- [`docs/properties/AGENTS.md`](docs/properties/AGENTS.md)
- [`docs/subdomain/AGENTS.md`](docs/subdomain/AGENTS.md)

## Gotchas

- `composer install` triggers `filament:upgrade` via `post-autoload-dump`.
- composer platform pinned to PHP 8.3.
- JS tests: vitest (not jest, despite jest deps in package.json — legacy). Config in `vite.config.ts`; when `VITEST` env is set, laravel plugin is skipped.
- i18n: translations in `resources/lang/<locale>/` (PHP) and loaded client-side via i18next multiload backend.
- `servers.id` is `int(10) unsigned` — foreign keys referencing it must use `->unsignedInteger()`.
