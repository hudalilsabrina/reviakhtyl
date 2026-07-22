# Modular Startup

Egg-defined startup command fragments users can toggle on/off per server. Enabled parts are appended where `{{STARTUP_PARTS}}` appears in the startup command, space-joined. Lets a single egg expose optional flags (e.g., `--no-gui`, `--max-players 20`) without baking them into variables.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/startup`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/startup` | `StartupController::index` | Returns variables + `meta.startup_parts` + `meta.has_modular_startup` |
| PUT | `/startup/parts` | `StartupController::updateParts` | Persists `{part_id, enabled}[]`, returns recomputed startup command |
| PUT | `/startup/variable` | `StartupController::update` | Existing variable edit; no parts interaction |

**Model** — `app/Models/EggStartupPart.php`

- Belongs to `Egg` via `egg.startupParts()` (ordered by `sort_order`)
- Fields: `name`, `value`, `description`, `default_enabled`, `required`, `sort_order`, `group_name`

**Build step** — `app/Services/Servers/EnvironmentService.php::buildStartupParts()`

- Reads `egg.startupParts`, filters to enabled (user choice > default), implodes `value` with space.
- Injected as `STARTUP_PARTS` environment variable consumed by daemon startup.

**Eager load** — `Server::$with` includes `egg.startupParts`, so it's always available.

**Server-side merge** — `StartupController::index()`

```php
$choices = collect($server->startup_parts ?? [])->keyBy('part_id');
// mutates transformer output: sets user_enabled = choice.enabled ?? part.default_enabled
```

**Admin (Filament)** — `app/Filament/Resources/Nests/EggResource.php`

- Tab "Startup Parts" contains a `Repeater` bound to the `startupParts` relation.
- Drag-reorder writes `sort_order` via `orderColumn('sort_order')`.

**Egg import/export**

- `EggExporterService` — serializes `startup_parts` array (id stripped).
- `EggImporterService` — `forceCreate` each part with new `egg_id`.
- `EggUpdateImporterService` — `updateOrCreate` keyed by `name`; parts not in import are deleted. Last occurrence wins on duplicate names.

**Frontend** — `resources/scripts/components/server/startup/`

- `StartupContainer.tsx` — renders "Customize Startup Parts" button when `data.hasModularStartup`.
- `StartupPartsModal.tsx` — grouped by `group_name`; switches per part, required ones read-only.
- `resources/scripts/api/server/updateStartupParts.ts` — `PUT /api/client/servers/{uuid}/startup/parts`.
- `resources/scripts/api/swr/getServerStartup.ts` — SWR hook returning `startupParts` + `hasModularStartup`.
- Type: `resources/scripts/api/server/types.ts::StartupPart` (includes computed `userEnabled`).
- Transformer: `resources/scripts/api/transformers.ts::rawDataToStartupPart`.

**Hooks into other flows**

- `StartupModificationService::updateAdministrativeSettings()` — sets `startup_parts = null` when `egg_id` changes (part IDs no longer valid).

## Patterns unique to this feature

- **Placeholder-driven, not concatenation**: egg startup command author places `{{STARTUP_PARTS}}`; EnvironmentService substitutes. If placeholder missing, parts are silently ineffective — no error raised.
- **Computed `user_enabled`**: the transformer returns raw `default_enabled` / `required`; controller mutates `user_enabled` afterward by merging per-server stored choices. Choice absent = fallback to `default_enabled`.
- **Sparse persistence**: `updateParts()` only stores parts the user explicitly sent. Server reload reads defaults for any unstored parts.
- **Required parts are immutable**: backend throws if a required part is disabled; frontend marks the switch `readOnly`.
- **Order is first-class**: `sort_order` is both a DB column and the repeater reorder column. Frontend respects this order via transformer output order.
- **Permission**: shares `Permission::ACTION_STARTUP_UPDATE` with the variable update endpoint — no dedicated sub-permission.
- **`has_modular_startup` flag**: set only when `egg.startupParts->isNotEmpty()`. Frontend uses this to conditionally render the modal button.

## Gotchas

- **Transformer mutation in controller**: `user_enabled` is written into `$parts['data']` after fractal transforms (loop with `&$part`). Easy to miss when reading the controller — don't refactor to transformer without adjusting the merge.
- **Stale `part_id`s after egg edit**: admin reorder/delete of parts doesn't invalidate existing servers' `startup_parts` JSON. A server's stored `part_id` can reference a removed or moved part. `buildStartupParts()` tolerates this silently (choice maps to no part, skipped); the controller's `updateParts()` rejects unknown IDs with 400.
- **`value` capped at 191 chars** — a single part can't hold a long JVM flag string. Split into multiple parts or use a variable instead.
- **No i18n for modal strings**: "Customize Startup Parts", "Startup Parts", "Required", "Save" are hardcoded English in `StartupPartsModal.tsx` and `StartupContainer.tsx`.
- **Import last-wins on duplicate `name`**: if an egg JSON declares two parts with the same name, `EggUpdateImporterService::keyBy('name')` keeps the last. No validation error.
- **Part deletion orphans server choices**: deleting a part via Filament leaves rows in `servers.startup_parts` JSON pointing to dead IDs. Handled gracefully in `buildStartupParts()` but the JSON grows stale over time.
- **`startup_parts` stored as positional array, keyed at read-time**: the column is `json` cast to `array`; controller does `keyBy('part_id')` each read. Don't assume stored order equals insertion order.
- **`{{STARTUP_PARTS}}` is not validated**: an egg with parts defined but no placeholder in the startup command will show parts in UI but they'll have no runtime effect. No warning surfaced to admin or user.
- **No rate limit on `PUT /startup/parts`** — unlike subdomain writes, shares the general client rate limiter. Rapid toggle is fine but consider if abuse becomes an issue.

## Migrations

- `2026_07_21_000001_create_egg_startup_parts_table.php` — `egg_startup_parts` table.
- `2026_07_21_000002_add_startup_parts_to_servers_table.php` — nullable `json` column `startup_parts` on `servers`.

## i18n

None for this feature. Modal labels and button text are hardcoded strings in components. Server-side error messages ("This server does not have configurable startup parts.", "Invalid startup part ID") are English-only exceptions thrown via `BadRequestHttpException`.
