# Datapack Installer

Browse, install, update, toggle, and manage Minecraft datapacks directly from the panel. Datapacks are ZIP archives placed in the server's `/datapacks/` folder; this feature provides a registry-backed install flow, version tracking, and manual ZIP upload support — matching the architecture of the mods and plugins installers.

## Scope and Scope Boundaries

**In scope for initial implementation:**

- Browse Modrinth datapack catalog, filtered by server game version
- Install a datapack version (downloads ZIP, verifies `pack.mcmeta` exists, places in `/datapacks`)
- Update installed datapacks to newer compatible versions
- Toggle enable/disable by renaming between `.zip` and `.zip.disabled`
- Delete a datapack (removes the ZIP)
- Track manually uploaded ZIPs in `/datapacks/` (untracked discovery + register/link)
- Cross-provider duplicate detection (same slug from another provider)
- Bulk update and bulk delete (up to 50 at a time)
- Admin gating per-egg via Filament settings (`panel:datapacks:egg_ids`)
- Permission: `datapack.manage`
- Activity log entries (`server:datapack.install`, `update`, `delete`, `toggle`, `bulk-update`, `bulk-delete`, `link`)
- Rate limiting (10 req/min on write endpoints)
- Antivirus scan after download (reuse `FileScanService`)
- Translation keys, navigation entry, egg-feature gate

**Out of scope (future):**

- Planet Minecraft datapack section (no public API; scraping is fragile and against ToS)
- Dependency resolution (datapacks do not declare dependencies)
- Pack format validation (different Minecraft versions require different `pack.mcmeta` `pack_format`; surfaced as a warning, not a blocker)
- Resource-pack-sidecar detection
- Modrinth project-type filter is `datapack` (narrower than `mod` / `plugin`); a project can be both a datapack and a mod — surface "datapack" and "mod" labels in the UI

## How Datapacks Differ from Mods and Plugins

| Concern | Mods | Plugins | Datapacks |
|---------|------|---------|-----------|
| Package format | JAR | JAR | ZIP |
| Server directory | `/mods/` | `/plugins/` | `/datapacks/` |
| Metadata source | JAR descriptors (`fabric.mod.json`, `META-INF/mods.toml`, etc.) | JAR descriptors (`plugin.yml`, etc.) | `pack.mcmeta` inside ZIP |
| Enable/disable mechanism | `.jar.disabled` extension | `.jar.disabled` extension | `.zip.disabled` extension |
| Registry type | Modrinth / CurseForge | Modrinth / Hangar / Spigot | Modrinth / CurseForge |
| Dependencies | Yes (required + optional) | Yes (required + optional) | None declared |
| Loader concept | Fabric, Forge, NeoForge, Quilt | Paper, Purpur, Velocity, etc. | No loaders — only Minecraft version |
| Size cap | 64 MB | 64 MB | 64 MB (ZIP) |

Key implementation differences from mods/plugins:

- No `JarService` equivalent — need a `DatapackZipService` that opens a ZIP, locates `pack.mcmeta`, and reads `pack_format` + `description`.
- No loader detection or dependency resolution.
- `version_id` / `version_number` map to Modrinth version IDs (same shape as mods, just a different project-type filter).
- Cross-provider duplicate detection is simpler: only one provider (Modrinth), so the only conflict is same-slug from Modrinth or a manual ZIP that resolves to the same slug.

## Architecture

### Backend (PHP)

**Core Services** (`app/Services/Datapacks/`)

- `DatapackManagerService` — Install, update, delete, toggle, cross-provider duplicate detection, version resolution, game-version filter.
- `DatapackZipService` — Open downloaded ZIP, locate `pack.mcmeta`, read `pack_format` and `description`. Cache metadata keyed by filename+size (same pattern as `ModJarService`).
- `ModrinthService` — Reused from `app/Services/Mods/`. Provider interface already exists (`ModProviderInterface`); datapacks use a new `DatapackProviderInterface` for type safety but the concrete `ModrinthService` implementation is shared — it already supports a `project_type` filter parameter.
- `DatapackProviderInterface` — Contract with `search()`, `versions()`, `projects()`. Identical shape to `ModProviderInterface` / `PluginProviderInterface`.

**Shared from mods/plugins:**

- `ModrinthService` — Reused directly. The Modrinth search API accepts `project_type` as a facet (`datapack`). The version endpoint returns `files[].download_url` and `files[].file_name` — same shape.
- `FileScanService` — Antivirus scan the downloaded ZIP.
- `DaemonFileRepository::pull()` — Downloads to the remote server; the third retry workaround in `pull()` (Wings v2 spurious 500) applies equally to ZIPs.

**API** (`app/Http/Controllers/Api/Client/Servers/DatapackController`)

`/api/client/servers/{server}/datapacks`:

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/datapacks` | `index` | Installed list + game version |
| GET | `/datapacks/search` | `search` | Search Modrinth, filter by game version |
| GET | `/datapacks/versions` | `versions` | Versions for a project, dependencies not applicable |
| GET | `/datapacks/untracked` | `untracked` | ZIPs in `/datapacks` not in DB |
| POST | `/datapacks` | `store` | Install (with optional version, cross-provider duplicate check) |
| POST | `/datapacks/{id}/link` | `link` | Link manual datapack to Modrinth |
| POST | `/datapacks/bulk/update` | `bulkUpdate` | Update up to 50 |
| DELETE | `/datapacks/bulk` | `bulkDestroy` | Delete up to 50 |
| PATCH | `/datapacks/{id}` | `update` | Update to latest compatible version |
| PATCH | `/datapacks/{id}/toggle` | `toggle` | Enable/disable |
| DELETE | `/datapacks/{id}` | `destroy` | Remove |

**Model** (`app/Models/ServerDatapack`)

- Table: `server_datapacks`
- Columns: `id`, `server_id`, `provider`, `project_id`, `slug`, `title`, `version_id`, `version_number`, `file_name`, `icon_url`, `created_at`, `updated_at`
- Unique: `(server_id, provider, project_id)`
- `disabled` accessor: `str_ends_with($this->file_name, '.disabled')`
- Relationship: `Server->datapacks()` HasMany

Migration: `database/migrations/YYYY_MM_DD_HHMMSS_create_server_datapacks_table.php`

**Transformer** (`app/Transformers/Api/Client/ServerDatapackTransformer.php`)

Same shape as `ServerModTransformer` and `ServerPluginTransformer`:

```php
[
    'id' => ...,
    'provider' => ...,
    'project_id' => ...,
    'slug' => ...,
    'title' => ...,
    'version_id' => ...,
    'version_number' => ...,
    'file_name' => ...,
    'icon_url' => ...,
    'disabled' => ...,
]
```

**Form Requests** (`app/Http/Requests/Api/Client/Servers/Datapacks/`)

- `SearchDatapacksRequest` — optional `provider`, `query`, `limit`, `offset`, `sort`
- `InstallDatapackRequest` — `provider`, `project_id`, `title?`, `icon_url?`, `version_id?`, `slug?`, `replace?`
- `UpdateDatapackRequest` — empty body (or validated `version_id?`)
- `ToggleDatapackRequest` — empty body
- `TrackDatapackRequest` — `file_name`, `slug?`, `title?`, `version?`
- `DeleteDatapackRequest` — empty body
- `BulkUpdateDatapacksRequest` — `datapack_ids: array, min 1, max 50`
- `BulkDeleteDatapacksRequest` — `datapack_ids: array, min 1, max 50`

### Frontend (React)

**Components** (`resources/scripts/components/server/datapacks/`)

- `DatapacksContainer.tsx` — orchestrator (mirrors `ModsContainer.tsx` / `PluginsContainer.tsx`)
  - Two-tab layout: Installed / Browse
  - Installed tab: list of datapack cards with update/toggle/delete, checkboxes for bulk ops
  - Browse tab: search bar, sort select, datapack cards with install button
  - State: installed list, search results, loading states, error states

- `BrowseTab.tsx` — search UI, provider selector (Modrinth only), sort (relevance, downloads, updated), install cards
- `InstalledTab.tsx` — installed/untracked datapack cards, update/toggle/remove actions
- `VersionPickerModal.tsx` — version list (no dependency chips — datapacks have no deps)
- `InstallProgressModal.tsx` — 3-step progress (resolve → download → finish), same UI as mods/plugins
- `DatapackIcon.tsx` — small square image (reuse `PluginIcon` shape)
- `Badge.tsx` — installed / update available / disabled / untracked badge
- `ProgressBar.tsx` — animated progress bar (reuse from plugins)
- `useProgress.ts` — fake easing progress hook (reuse)
- `types.ts` — `ServerDatapack`, `DatapackHit`, `DatapackVersion`, `UntrackedZip`, `BulkOperationResult`

**API Client** (`resources/scripts/api/server/datapacks/datapacks.ts`)

Typed interfaces and functions mirroring `mods.ts` / `plugins.ts`:

```typescript
export interface ServerDatapack { ... }
export interface DatapackHit { ... }
export interface DatapackVersion { ... }
export interface UntrackedZip { ... }
export interface BulkOperationResult { ... }

export function getDatapacks(...)
export function searchDatapacks(...)
export function getDatapackVersions(...)
export function installDatapack(...)
export function updateDatapack(...)
export function deleteDatapack(...)
export function toggleDatapack(...)
export function linkDatapack(...)
export function getUntrackedZips(...)
export function registerZip(...)
export function bulkUpdateDatapacks(...)
export function bulkDeleteDatapacks(...)
```

**Navigation**

`resources/scripts/routers/routes.ts` — add entry:

```typescript
{
    route: 'datapacks/*',
    permission: 'datapack.*',
    eggFeature: 'datapacks',
    name: 'server.datapacks',
}
```

Gate on `eggFeature: 'datapacks'` (same pattern as `mods`, `plugins`, `properties`). `ServerTransformer` pushes a synthetic `datapacks` egg feature when `isEnabledFor()`.

### DatapackZipService — Implementation Detail

`app/Services/Datapacks/DatapackZipService.php`:

```php
private function parseZipMetadata(string $zipPath, string $fileName): array
```

1. Open ZIP with `ZipArchive`.
2. Locate `pack.mcmeta` (root-level; ZIP paths use `/`).
3. If missing → fallback to filename-based slug/title, `pack_format: null`.
4. Parse JSON body: `{ "pack": { "pack_format": N, "description": "..." } }`.
5. Cache key: `datapack:zip-meta:<hash(fileName + filesize)>`, TTL 1 hour (same as `ModJarService`).
6. Return `['slug' => ..., 'title' => ..., 'pack_format' => ..., 'description' => ...]`.

**Why ZipArchive:** PHP has built-in `ZipArchive`. ModJarService uses `ZipArchive` for JARs. The same streaming-to-tempfile pattern applies:

```php
$tempFile = tempnam(sys_get_temp_dir(), 'datapack');
move_uploaded_file($uploadedPath, $tempFile); // or download via DaemonFileRepository::pull() then read from server
$zip = new ZipArchive();
$zip->open($tempFile);
$mcmeta = $zip->getFromName('pack.mcmeta');
```

**Reading the ZIP after Wings download:** After `DaemonFileRepository::pull()` writes the ZIP to `/datapacks/`, read it via `$zip->getFromName('pack.mcmeta')` by first downloading it to a temp file through the same file repository (the file is already on the Wings host; `DaemonFileRepository::download()` can pull it back to the panel to inspect, or inspect it via a stream). Alternatively, open the ZIP via `DaemonFileRepository::getFileStream()` if the Wings API supports reading archives. The simpler path: `pull` already writes to `/datapacks/`; then call a Wings endpoint to read the file content as bytes and inspect locally.

## Modrinth Integration

The existing `ModrinthService` supports a `project_type` facet. Add a new method (or parameter):

```php
public function searchDatapacks(string $query, array $gameVersions, int $limit, int $offset, string $sort): array
{
    return $this->search(
        query: $query,
        categories: [],
        gameVersions: $gameVersions,
        projectTypes: ['datapack'],
        limit: $limit,
        offset: $offset,
        sort: $sort,
    );
}
```

Internally, `ModrinthService::search()` already encodes facets; just pass `project_type:datapack` in the facets array. The existing `versions()` and `projects()` methods work without change.

**Game version detection:** Same as mods/plugins — `MINECRAFT_VERSION` or `MC_VERSION` egg variable. No loader concept. The Modrinth `versions` endpoint returns versions filtered by `game_versions`; pass the server's game version array.

**Note on dual-typed projects:** A Modrinth project can be both `mod` and `datapack` (e.g., "CraftTweaker" datapack). Search `project_type:datapack` returns only the datapack side. Mods installer searching `project_type:mod` returns only the mod side. No overlap in the UI.

## DatapackManagerService — Key Methods

Mirror `ModManagerService` with datapack-specific differences:

| Method | Notes |
|--------|-------|
| `isEnabledFor(Server $server)` | Same pattern, reads `panel:datapacks:egg_ids` |
| `enabledEggIds()` | Cached, reads settings key |
| `provider(string $name)` | Only `modrinth` for now; throws on unknown |
| `gameVersion(Server $server)` | Same as mods — `MINECRAFT_VERSION` / `MC_VERSION` |
| `loaders(Server $server)` | Returns empty array (no loaders); kept for interface compat if shared trait extracted |
| `install(...)` | Pull ZIP → assertCleanScan → `pack.mcmeta` verification → DB record |
| `update(Server, ServerDatapack)` | Pull new version → scan → delete old ZIP → DB update |
| `delete(Server, ServerDatapack)` | Delete ZIP(s) from `/datapacks/` → delete DB record |
| `toggle(Server, ServerDatapack)` | Rename `.zip` ↔ `.zip.disabled` |
| `crossProviderDuplicate(...)` | Check same slug from `manual` or Modrinth |
| `untracked(Server)` | ZIPs in `/datapacks` not in `server_datapacks` |

### Install flow detail

```php
public function install(Server $server, string $provider, string $projectId, ...): ServerDatapack
{
    // 1. Resolve version
    $version = $this->resolveVersion($server, $provider, $projectId, $requestedVersionId);

    // 2. Check for duplicate
    $duplicate = $this->crossProviderDuplicate($server, $provider, $slug);
    if ($duplicate) { throw DuplicateException; }

    // 3. Pull ZIP to /datapacks
    $this->pull($server, $version, $existing = null);

    // 4. Verify pack.mcmeta exists
    $meta = $this->zipService->parsePackMcmeta($server, $version['file_name']);
    if ($meta === null) {
        // Delete the bad ZIP
        throw new DisplayException('Downloaded file is not a valid datapack (no pack.mcmeta).');
    }

    // 5. DB record
    $datapack = $server->datapacks()->create([
        'provider' => $provider,
        'project_id' => $projectId,
        ...
        'file_name' => $version['file_name'],
    ]);

    Cache::forget("server:{$server->id}:datapacks-dir");

    return $datapack;
}
```

### Toggle mechanism

Same pattern as mods/plugins — no DB column, rename file extension:

- Enabled: `pack.zip`
- Disabled: `pack.zip.disabled`

Minecraft ignores ZIPs ending in `.disabled`.

### Caching

Same pattern:

- Directory listing cache: 30s, key `server:{id}:datapacks-dir`
- ZIP metadata cache: 1h, keyed by `fileName + size`
- Cleared on install/update/delete/toggle

## Routes

`routes/api-client.php` — add a new group:

```php
Route::group(['prefix' => '/datapacks'], function () {
    Route::middleware('throttle:api.datapacks')->get('/', [DatapackController::class, 'index']);
    Route::middleware('throttle:api.datapacks')->get('/search', [DatapackController::class, 'search']);
    Route::get('/versions', [DatapackController::class, 'versions']);
    Route::middleware('throttle:api.datapacks')->get('/untracked', [DatapackController::class, 'untracked']);
    Route::middleware('throttle:api.datapacks')->post('/register', [DatapackController::class, 'register']);
    Route::middleware('throttle:api.datapacks')->post('/', [DatapackController::class, 'store']);
    Route::middleware('throttle:api.datapacks')->post('/bulk/update', [DatapackController::class, 'bulkUpdate']);
    Route::middleware('throttle:api.datapacks')->delete('/bulk', [DatapackController::class, 'bulkDestroy']);
    Route::middleware('throttle:api.datapacks')->patch('/{datapack}/update', [DatapackController::class, 'update']);
    Route::middleware('throttle:api.datapacks')->post('/{datapack}/link', [DatapackController::class, 'link']);
    Route::middleware('throttle:api.datapacks')->patch('/{datapack}/toggle', [DatapackController::class, 'toggle']);
    Route::delete('/{datapack}', [DatapackController::class, 'destroy']);
});
```

Thinner throttling on read-only endpoints (`/versions`, `/search`) than write endpoints, matching mods/plugins pattern. Note: ModController/PluginController do their `assertEnabled()` per-method; put it in a middleware or keep per-method for consistency.

`RouteConfigServiceProvider.php` — add:

```php
RateLimiter::for('api.datapacks', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

## Rate Limiting

Same as mods/plugins: 10 requests/minute per user on write endpoints (install, update, register, untracked, bulk). Read endpoints (search, versions) are not rate-limited.

## Permission

Add to `app/Models/Permission.php`:

```php
'datapack' => [
    'description' => 'Permissions that control a user\'s access to the datapack installer for this server.',
    'keys' => [
        'manage' => 'Allows a user to search, install, update, and remove Minecraft datapacks on this server.',
    ],
],
```

Add constant:

```php
public const ACTION_DATAPACK_MANAGE = 'datapack.manage';
```

## Filament Settings

`app/Filament/Pages/Settings.php` — add to the existing Server Settings section:

- `Select::make('panel:datapacks:egg_ids')` — eggs allowed to use the installer
- Append `panel:datapacks:egg_ids` to the existing `in_array` guard at line 172 and 988

Admin note: Enable for Fabric/Forge/NeoForge/Quilt/Paper/Purpur/Folia eggs that use datapacks. Datapacks work on vanilla, Paper, Forge, Fabric — basically every Minecraft server type.

## Translations

**`resources/lang/en/server/datapacks.php`**

Follow the shape of `resources/lang/en/server/mods.php` and `resources/lang/en/server/plugins.php`. Sections:

- `browse.*` — tab label, search placeholder, sort options, provider label
- `installed.*` — tab label, empty state, untracked section, bulk actions
- `install.*` — install button, confirm/replace prompt, progress steps
- `update.*` — update button, up-to-date text
- `toggle.*` — enable/disable labels
- `delete.*` — confirm delete
- `errors.*` — no pack.mcmeta, download failed, scan failed, duplicate
- `bulk.*` — update/delete button text, success/failure counts
- `activity.*` — human-readable strings for activity log entries (optional; Activity::property() passes the raw string)

**`resources/lang/en/routes.php`**

Add `server.datapacks` entry.

**`resources/lang/en/activity.php`**

Add entries under `server.datapack.*` for install, update, delete, toggle, bulk-update, bulk-delete, link.

**Pluralisation note (from properties AGENTS.md):**

Use i18next `_one` / `_other` suffixes for pluralised keys, never Laravel's `|` pipe syntax.

## i18n

Same as mods/plugins: `LocaleController::i18n()` ships PHP translations to the client. Add `datapacks` to the translation loader.

## Navigation

`resources/scripts/routers/routes.ts` — add datapacks entry, gated by `eggFeature: 'datapacks'` and `permission: 'datapack.*'`. Route path: `'datapacks/*'`.

`ServerTransformer` must push a synthetic `datapacks` egg feature when `DatapackManagerService::isEnabledFor($server)` returns true, exactly as it does for `mods` / `plugins` / `properties`.

## Validation and Edge Cases

### ZIP without pack.mcmeta

After download, `DatapackZipService::parsePackMcmeta()` returns `null`. The install endpoint deletes the downloaded ZIP and throws a `DisplayException('Downloaded file is not a valid datapack (no pack.mcmeta).')`.

### pack_format mismatch

Surfaced as a warning in the frontend (e.g., "This datapack requires Minecraft 1.20+ (pack_format 15)"). Not a blocker — Minecraft warns on its own when loading an incompatible datapack. Show the `pack_format` value and the inferred Minecraft version range in the version picker.

### Large ZIPs

64 MB cap, matching mods/plugins. Reject early if Modrinth `file.size` exceeds the cap.

### Wings v2 spurious 500 after pull

`DaemonFileRepository::pull()` already has the retry workaround (lines 336-354). Reuse it — pass `'foreground' => true`.

### Wings files API no Range header

Same as mods/plugins — full ZIP transferred over wire. Modrinth datapack ZIPs are typically small (10-500 KB).

### Cross-provider conflict

Only one provider (Modrinth) + manual. If a datapack is installed from Modrinth and the user tries to upload the same ZIP manually with matching slug, return 409 with conflict details.

### Disabled folder detection

After a toggle, list `/datapacks` and verify the file exists (same ghost-state prevention as mods/plugins).

### Untracked ZIPs

`getUntrackedZips()` scans `/datapacks/` for `.zip` and `.zip.disabled` files not in `server_datapacks`. Parse `pack.mcmeta` for each to offer title/slug on the untracked card. Cache for 30s, same key pattern.

### Manual tracking

`register()` creates a `manual` provider entry. File must exist in `/datapacks/`. Scanned for malware. Same verification pattern as mods/plugins.

### Manual linking

`link()` converts a `manual` datapack to a Modrinth-linked entry (same mods/plugins flow).

### No dependencies

Version picker has no dependency chips. Simpler UI — just a version list with download count and release date.

### Server restart after datapack install

Datapacks need `/reload` to take effect. The frontend should surface a restart banner (same pattern as `PropertiesContainer` — don't auto-restart, show a banner the user can click to send `SocketRequest.SET_STATE` with `restart`).

## Egg Feature

Egg features are a JSON array stored on the `eggs.features` column. The frontend checks for the presence of a feature name in this array.

`ServerTransformer` (or a new `ServerTransformer::getEggFeatures()` call site) must include `'datapacks'` in the synthetic features array when `DatapackManagerService::isEnabledFor($server)` is true, alongside the existing `mods`, `plugins`, `properties`, `subdomain`, `eula`, etc.

## i18n Extraction

Datapack routes are under `server.datapacks.*` in translations. Add to `php artisan translation:load` or whatever the project's i18n extraction command is.

Frontend translations in `resources/scripts/locales/en/` — add a `datapacks.json` or extend the existing `server.json` following the mods/plugins namespace pattern.

## Testing

No `tests/` directory exists in the mods or plugins features. Manual test coverage:

- Install datapack (with/without explicit version)
- Update datapack (up-to-date, new version available)
- Toggle enable/disable (verify `.disabled` extension, verify Minecraft ignores it)
- Delete datapack (verify ZIP removed)
- Upload ZIP manually → appear in untracked list
- Track manual ZIP → appears in installed list
- Link manual to Modrinth → provider changes, version_id populated
- Cross-provider conflict (Modrinth vs manual upload with same slug)
- Search Modrinth with sort options (relevance, downloads, updated)
- Version picker (no dependency chips, just version list)
- Game version filter
- ZIP without `pack.mcmeta` → install rejected, ZIP cleaned up
- Large ZIP (>64 MB) → install rejected
- Bulk update 3 datapacks (success + partial failure)
- Bulk delete 3 datapacks (success + partial failure)
- Subuser without `datapack.manage` → no nav item, 403 from API
- Datapack install → restart banner shown → clicking banner sends restart socket request

## Files to Create

### Backend (PHP)

```
app/Models/ServerDatapack.php
app/Services/Datapacks/DatapackManagerService.php
app/Services/Datapacks/DatapackZipService.php
app/Services/Datapacks/DatapackProviderInterface.php
app/Http/Controllers/Api/Client/Servers/DatapackController.php
app/Http/Requests/Api/Client/Servers/Datapacks/SearchDatapacksRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/InstallDatapackRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/UpdateDatapackRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/ToggleDatapackRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/TrackDatapackRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/DeleteDatapackRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/BulkUpdateDatapacksRequest.php
app/Http/Requests/Api/Client/Servers/Datapacks/BulkDeleteDatapacksRequest.php
app/Transformers/Api/Client/ServerDatapackTransformer.php
database/migrations/YYYY_MM_DD_HHMMSS_create_server_datapacks_table.php
```

### Backend (modifications)

```
app/Models/Permission.php  — add datapack permission block + ACTION_DATAPACK_MANAGE constant
app/Providers/RouteConfigServiceProvider.php — add api.datapacks rate limiter
app/Filament/Pages/Settings.php — add panel:datapacks:egg_ids setting
routes/api-client.php — add /datapacks route group
```

### Frontend (TypeScript / TSX)

```
resources/scripts/components/server/datapacks/DatapacksContainer.tsx
resources/scripts/components/server/datapacks/BrowseTab.tsx
resources/scripts/components/server/datapacks/InstalledTab.tsx
resources/scripts/components/server/datapacks/VersionPickerModal.tsx
resources/scripts/components/server/datapacks/InstallProgressModal.tsx
resources/scripts/components/server/datapacks/DatapackIcon.tsx
resources/scripts/components/server/datapacks/Badge.tsx
resources/scripts/components/server/datapacks/ProgressBar.tsx
resources/scripts/components/server/datapacks/useProgress.ts
resources/scripts/components/server/datapacks/types.ts
resources/scripts/api/server/datapacks/datapacks.ts
```

### Frontend (modifications)

```
resources/scripts/routers/routes.ts — add datapacks route entry
resources/lang/en/server/datapacks.php — new translation file
resources/lang/en/routes.php — add server.datapacks entry
resources/lang/en/activity.php — add server.datapack.* entries
```

## Implementation Order

1. **Backend data layer** — Migration + Model + Transformer + permission constant
2. **Backend services** — `DatapackZipService` (standalone, testable), then `DatapackManagerService`
3. **Backend API** — Form requests, Controller, routes, rate limiter
4. **Filament settings** — egg selector
5. **Frontend API client** — typed interfaces + functions
6. **Frontend components** — `DatapacksContainer`, tabs, modals
7. **Frontend wiring** — routes, navigation, translations
8. **ServerTransformer egg feature** — synthetic `datapacks` feature
9. **Manual testing** — smoke test all CRUD + bulk + toggle + untracked + restart banner

## Reused Code

- **`ModrinthService`** (`app/Services/Mods/ModrinthService.php`) — direct reuse, add `project_type:datapack` filter parameter
- **`FileScanService`** — virus scan on downloaded ZIP
- **`DaemonFileRepository::pull()`** — download ZIP to `/datapacks` on Wings
- **`ModsContainer.tsx`** — structural template for `DatapacksContainer.tsx`
- **`PluginsContainer.tsx`** — also a valid template; merge patterns from both
- **`ProgressBar.tsx`**, **`useProgress.ts`** — drop-in reuse
- **`PluginIcon.tsx`** — adapt to `DatapackIcon.tsx` (same image shape)
- **`Badge.tsx`** — reuse with different label set
- **`InstallProgressModal.tsx`** — reuse with datapack-specific labels
- **`ScansRemoteJars` trait** — rename concept to "ScansRemoteFiles" or reuse directly (method is `assertCleanJarScan`, rename to `assertCleanZipScan` or just reuse — the trait works for any file type)

## Known Limitations

1. **Modrinth + CurseForge registry** — Planet Minecraft has no public API and is out of scope. CurseForge is supported using the existing CurseForge API (classId 47, datapacks category), matching the mod installer's provider pattern.
2. **No dependency resolution** — Datapacks do not declare dependencies in `pack.mcmeta` (the field is `description` only). If needed in future, extend `pack.mcmeta` schema.
3. **pack_format validation is advisory** — Only surfaced as UI metadata; Minecraft itself warns on mismatch.
4. **No real download progress** — Wings pull is fire-and-forget; same fake progress bar as mods/plugins.
5. **No automatic update checks** — Manual update only.
6. **Bulk operations no rollback** — Partial failure leaves some datapacks updated/deleted.
7. **ZIP inspection after pull requires two Wings round-trips** — One to pull, one to read the ZIP bytes for `pack.mcmeta`. ModJarService has the same problem.

## Decisions and Rationale

**Why Modrinth + CurseForge, not Planet Minecraft?**

Planet Minecraft has no public API. Scraping is fragile and against ToS. CurseForge includes a datapacks category (classId 47) accessible through its public API, the same endpoint already used by the mod installer. Modrinth provides a `datapack` project type filter.

**Why ZIP folder-based toggle instead of DB column?**

Same rationale as mods/plugins: no DB column means no migration complexity, no sync risk between DB state and filesystem, and Minecraft natively ignores `.disabled`-suffixed files.

**Why reuse ModrinthService instead of a new DatapackService?**

The Modrinth API is identical for mods, plugins, and datapacks — the only difference is the `project_type` facet. Adding a parameter to `search()` is a one-line change. A new service class would duplicate all HTTP client code.

**Why not merge ServerDatapack into ServerResource (mod/plugin/datapack supertype)?**

Keeps each feature independently disableable. The mods/plugins split already follows this pattern. A datapack-specific model keeps queries simple (`$server->datapacks`), migration rollback safe, and permission scoping clean.

**Why 64 MB cap on ZIPs?**

Matches mods/plugins `MAX_SIZE`. Large datapack ZIPs (>64 MB) are unusual; a world-generation datapack with hundreds of structures could approach this, but the cap prevents abuse.
