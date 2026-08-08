# Datapack Installer

Browse, install, update, toggle, and manage Minecraft datapacks directly from the panel. Datapacks are ZIP archives placed in the server's `/datapacks/` folder; this feature provides a registry-backed install flow, version tracking, and manual ZIP upload support — matching the architecture of the mods and plugins installers.

## Scope and Scope Boundaries

**In scope for initial implementation:**

- Browse Modrinth + CurseForge datapack catalogs, filtered by server game version
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

- No `JarService` equivalent — `DatapackZipService` opens a ZIP, locates `pack.mcmeta`, and reads `pack_format` + `description`.
- No loader detection or dependency resolution.
- `version_id` / `version_number` map to Modrinth version IDs (same shape as mods, just a different project-type filter).
- Cross-provider duplicate detection is simpler: only two providers (Modrinth, CurseForge), so the only conflicts are same-slug from another provider or a manual ZIP that resolves to the same slug.

## Architecture

### Backend (PHP)

**Core Services** (`app/Services/Datapacks/`)

- `DatapackManagerService` — Install, update, delete, toggle, cross-provider duplicate detection, version resolution, game-version filter.
- `DatapackZipService` — Open downloaded ZIP, locate `pack.mcmeta`, read `pack_format` and `description`. Cache metadata keyed by filename+size (same pattern as `ModJarService`).
- `DatapackProviderInterface` — Contract with `search()`, `versions()`, `resolveVersion()`, `latestVersion()`, `projects()`. Identical shape to `ModProviderInterface` / `PluginProviderInterface` but takes a game-version array instead of loaders.
- `ModrinthService` — Modrinth API v2 implementation, `project_type:datapack` facet.
- `CurseForgeService` — CurseForge datapack provider (classId 47), shares the mod installer's API key.

**API** (`app/Http/Controllers/Api/Client/Servers/DatapackController`)

`/api/client/servers/{server}/datapacks`:

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/datapacks` | `index` | Installed list + game version |
| GET | `/datapacks/search` | `search` | Search registry, filter by game version |
| GET | `/datapacks/versions` | `versions` | Versions for a project (no dependencies) |
| GET | `/datapacks/untracked` | `untracked` | ZIPs in `/datapacks` not in DB |
| POST | `/datapacks` | `store` | Install (with optional version, cross-provider duplicate check) |
| POST | `/datapacks/{id}/link` | `link` | Link manual datapack to a registry |
| POST | `/datapacks/{id}/update` | `update` | Update to latest compatible version |
| POST | `/datapacks/{id}/toggle` | `toggle` | Enable/disable |
| DELETE | `/datapacks/{id}` | `destroy` | Remove |
| POST | `/datapacks/bulk/update` | `bulkUpdate` | Update up to 50 |
| DELETE | `/datapacks/bulk` | `bulkDestroy` | Delete up to 50 |
| POST | `/datapacks/register` | `register` | Track uploaded ZIP as manual datapack |

**Model** (`app/Models/ServerDatapack`)

- Table: `server_datapacks`
- Columns: `id`, `server_id`, `provider`, `project_id`, `slug`, `title`, `version_id`, `version_number`, `file_name`, `icon_url`, `created_at`, `updated_at`
- Unique: `(server_id, provider, project_id)`
- `disabled` accessor checks `str_ends_with($this->file_name, '.disabled')`
- Relationship: `Server->datapacks()` HasMany

Migration: `database/migrations/2026_08_08_000001_create_server_datapacks_table.php`

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
- `UpdateDatapackRequest` — optional `version_id?`
- `ToggleDatapackRequest` — empty body
- `TrackDatapackRequest` — `file_name`, `slug?`, `title?`, `version?`
- `DeleteDatapackRequest` — empty body
- `BulkUpdateDatapacksRequest` — `datapack_ids: array, min 1, max 50`
- `BulkDeleteDatapacksRequest` — `datapack_ids: array, min 1, max 50`

### Frontend (React)

**Component** (`resources/scripts/components/server/datapacks/DatapacksContainer.tsx`)

- Single-file orchestrator mirroring `ModsContainer.tsx` (simpler — no dependency resolution or loaders)
- Two-tab layout: Installed / Browse
- Multi-provider search with sort/filters (relevance, downloads, updated)
- Version picker modal (no dependency chips — datapacks have no deps)
- 3-step install progress modal (resolve → download → finish)
- Cross-provider duplicate conflict resolution
- Manual ZIP tracking and linking
- Enable/disable toggle
- Bulk operations: checkboxes for selection, "Update Selected" / "Delete Selected" buttons

**API Client** (`resources/scripts/api/server/datapacks/datapacks.ts`)

- Typed interfaces: `ServerDatapack`, `DatapackHit`, `DatapackVersion`, `UntrackedZip`, `BulkOperationResult`
- Functions: `getServerDatapacks`, `searchDatapacks`, `getDatapackVersions`, `installDatapack`, `updateDatapack`, `deleteDatapack`, `toggleDatapack`, `linkDatapack`, `getUntrackedZips`, `registerZip`, `bulkUpdateDatapacks`, `bulkDeleteDatapacks`

**Navigation**

`resources/scripts/routers/routes.ts` — entry with `eggFeature: 'datapacks'`:

```typescript
{
    route: 'datapacks/*',
    permission: 'datapack.*',
    eggFeature: 'datapacks',
    name: 'server.datapacks',
    component: DatapacksContainer,
    icon: FaBoxOpen,
}
```

Gate on `eggFeature: 'datapacks'` (same pattern as `mods`, `plugins`, `properties`). `ServerTransformer` pushes a synthetic `datapacks` egg feature when `isEnabledFor()`.

## Modrinth Integration

`app/Services/Datapacks/ModrinthService.php` — standalone implementation, does NOT extend the mods service (the reverted version reached into a `private const API` cross-class, which was a bug).

- Base URL: `https://api.modrinth.com/v2`
- Search facets: `project_type:datapack` + `versions:{gameVersion}` per detected version
- Sort indices: `relevance`, `downloads`, `updated`
- Versions endpoint filters by `game_versions` (JSON-encoded)
- Primary file selection, fallback to first file
- `projects()` bulk metadata lookup for titles/icons

## CurseForge Integration

`app/Services/Datapacks/CurseForgeService.php` — datapack classId 47, shares `panel:mods:curseforge_api_key` (same key as the mod installer).

- Base URL: `https://api.curseforge.com/v1`
- Authentication: `x-api-key` header
- Minecraft game ID: 432, Datapack class ID: 47
- Sort field mapping: Featured=1, LastUpdated=2, TotalDownloads=6
- Missing API key → empty results (graceful degradation)

## DatapackManagerService — Key Methods

Mirror `ModManagerService` with datapack-specific differences:

| Method | Notes |
|--------|-------|
| `isEnabledFor(Server $server)` | Same pattern, reads `panel:datapacks:egg_ids` |
| `enabledEggIds()` | Cached, reads settings key |
| `provider(string $name)` | `modrinth` / `curseforge`; throws on unknown |
| `gameVersion(Server $server)` | Same as mods — `MINECRAFT_VERSION` / `MC_VERSION` |
| `install(...)` | Pull ZIP → assertCleanScan → `pack.mcmeta` verification → DB record |
| `update(Server, ServerDatapack)` | Pull new version → scan → delete old ZIP → DB update |
| `delete(Server, ServerDatapack)` | Delete ZIP(s) from `/datapacks/` → delete DB record |
| `toggle(Server, ServerDatapack)` | Rename `.zip` ↔ `.zip.disabled` |
| `crossProviderDuplicate(...)` | Check same slug from other provider, incl. manual ZIP metadata |
| `pull(...)` | Wings pull → verify presence → virus scan → `pack.mcmeta` check (deletes bad ZIP) |

## DatapackZipService — Implementation Detail

- `untracked(Server)` — ZIPs in `/datapacks` not tracked, filtered by `.zip` / `.zip.disabled` suffix, 30s cache keyed `server:{id}:datapacks-dir`.
- `hasPackMcmeta(Server, fileName)` — stream ZIP to temp, open with `ZipArchive`, check `pack.mcmeta` parses to an array with a `pack` key.
- `parsePackMcmeta(Server, fileName, size)` — extract `pack_format` + `description`; derive slug/title from description or filename; 1hr cache keyed by `filename+size`.

## Enable/Disable Toggle

No DB column — renames file extension:

- Enabled: `pack.zip`
- Disabled: `pack.zip.disabled`

Minecraft ignores ZIPs ending in `.disabled`. State persists across updates (old ZIP cleaned up, new ZIP inherits state).

## Configuration

### Admin Setup (Filament)

Settings → Advanced → Datapacks → Select eggs allowed to use the installer (`panel:datapacks:egg_ids`)

Typically enabled for any Minecraft server that supports datapacks (vanilla, Paper, Fabric, Forge, NeoForge, Quilt).

### Permission

`datapack.manage` — User needs this subuser permission to access feature.

Defined in `app/Models/Permission.php` (constant + permissions block).

### Rate Limiting

Dedicated limiter `api.datapacks` in `RouteConfigServiceProvider.php` — 10 requests/minute per user on write endpoints (install, update, register, untracked, bulk). Search/versions are unrestricted.

## Known Limitations

1. **No real download progress** — Wings pull endpoint is fire-and-forget; fake progress bar eases to 90% then jumps to 100%
2. **No automatic updates** — User must manually click Update button
3. **No version pinning** — Update always pulls latest compatible version
4. **ZIP parsing overhead** — Full ZIP downloaded even when only reading metadata
5. **CurseForge API key required** — Admin must obtain and configure key for CurseForge access
6. **Bulk operations no rollback** — Partial failures leave some datapacks updated/deleted; no atomic transaction
7. **No pack_format validation** — a datapack for a different Minecraft version is surfaced as a warning, not blocked

## Testing

No `tests/` directory exists for this feature. Manual test coverage:

- Install datapack (with/without explicit version)
- Update datapack (up-to-date case, new version case)
- Toggle enable/disable (verify `.zip.disabled` extension)
- Delete datapack (verify ZIP removed from disk)
- Cross-provider duplicate detection (Modrinth vs CurseForge vs manual)
- Manual ZIP tracking and linking
- Search with sort options (relevance, downloads, updated)
- Game version filtering
- Invalid ZIP install (missing `pack.mcmeta` → deleted + error)
- Bulk update/delete selected datapacks (success/partial failure)

## Related Code

- Similar feature: Mods installer (`app/Services/Mods/`, `docs/mods/AGENTS.md`) — same architecture
- Similar feature: Plugins installer (`app/Services/Plugins/`, `docs/plugins/AGENTS.md`) — same architecture
- Permissions: `app/Models/Permission.php`
- Routes: `routes/api-client.php`
- Translations: `resources/lang/en/server/datapacks.php`, `resources/lang/en/activity.php`, `resources/lang/en/routes.php`
- Navigation: `resources/scripts/routers/routes.ts`
- Migration: `database/migrations/2026_08_08_000001_create_server_datapacks_table.php`
- Egg feature push: `app/Transformers/Api/Client/ServerTransformer.php`
- Filament settings: `app/Filament/Pages/Settings.php`

## Future Enhancements

- **Automatic update checks** — Background job scans for outdated datapacks
- **Version history** — Rollback to previous versions
- **Pack format validation** — Block or warn on incompatible `pack_format`
- **Resource pack support** — Extend to client resource packs
