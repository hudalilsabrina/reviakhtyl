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
- No `tests/` directory exists. PHP test setup (Pest) is declared in composer but no suite is present.
- No CI workflows in repo.

## Commands

- Frontend: `pnpm install`, `pnpm run build` (prod assets → `public/build`), `pnpm run watch`, `pnpm dev` (HMR).
- Verify TS: `pnpm tsc` (alias `tsc --noEmit`), `pnpm lint`, `pnpm test` (vitest).
- Backend: `composer install`, `php artisan serve`.
- Lint PHP: `composer pint:fix` (fix) / `composer pint:check` (check). Pint preset `laravel`, custom rule `new_with_parentheses.anonymous_class: false`.
- Static analysis: `./vendor/bin/phpstan analyse` (level 5, `app/` only; `app/Livewire`, `app/Repositories` excluded).
- Missing `public/build/manifest.json` → 500 error; run `pnpm run build` once.

## Gotchas

- `composer install` triggers `filament:upgrade` via `post-autoload-dump`.
- composer platform pinned to PHP 8.3.
- JS tests: vitest (not jest, despite jest deps in package.json — legacy). Config in `vite.config.ts`; when `VITEST` env is set, laravel plugin is skipped.
- i18n: translations in `resources/lang/<locale>/` (PHP) and loaded client-side via i18next multiload backend.
