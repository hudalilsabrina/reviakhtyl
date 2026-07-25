# Mods Installer

Minecraft client-side mod manager with Modrinth integration. Browse, install, update, toggle, and manage mods directly from the panel for Fabric, Forge, NeoForge, and Quilt servers.

## Architecture

### Backend (PHP)

**Core Services** (`app/Services/Mods/`)
- `ModManagerService` — Install, update, delete, toggle operations; loader/version resolution; cross-provider duplicate detection
- `ModJarService` — Parse JAR metadata from fabric.mod.json, META-INF/mods.toml, quilt.mod.json
- `ModProviderInterface` — Contract for registry providers
- `ModrinthService` — Modrinth API v2 implementation

**API** (`app/Http/Controllers/Api/Client/Servers/ModController`)
- `GET /mods` — List installed mods, detected game version, loaders
- `GET /mods/search` — Search registry by provider
- `GET /mods/versions` — List compatible versions with dependencies
- `GET /mods/untracked` — Discover manually uploaded JARs
- `POST /mods` — Install mod (with optional version, cross-provider duplicate check)
- `POST /mods/{id}/link` — Link manual mod to registry for updates
- `POST /mods/register` — Track uploaded JAR as manual mod
- `POST /mods/{id}/update` — Update to latest compatible version
- `POST /mods/{id}/toggle` — Enable/disable via `.disabled` extension
- `DELETE /mods/{id}` — Remove mod and delete JAR
- `POST /mods/bulk/update` — Update multiple mods (max 50), returns success/failed arrays
- `DELETE /mods/bulk` — Delete multiple mods (max 50), returns success/failed arrays

**Model** (`app/Models/ServerMod`)
- Table: `server_mods`
- Unique: `(server_id, provider, project_id)`
- `disabled` accessor checks `str_ends_with($file_name, '.disabled')`
- Relationship: `Server->mods()` HasMany

### Frontend (React)

**Component** (`resources/scripts/components/server/mods/ModsContainer.tsx`)
- Single-file orchestrator (1100+ lines): search, install, update, toggle, delete, bulk operations
- Two-tab layout: Installed / Browse
- Multi-provider search with sort/filters (relevance, downloads, updated)
- Version picker modal with dependency resolution
- 3-step install progress modal (resolve → download → finish)
- Cross-provider duplicate conflict resolution
- Manual JAR tracking and linking
- Enable/disable toggle
- Bulk operations: checkboxes for selection, "Update Selected" / "Delete Selected" buttons

**API Client** (`resources/scripts/api/server/mods/mods.ts`)
- Typed interfaces: `ServerMod`, `ModHit`, `ModVersion`, `ModDependency`, `UntrackedJar`, `BulkOperationResult`
- Functions: `getServerMods`, `searchMods`, `getModVersions`, `installMod`, `updateMod`, `deleteMod`, `toggleMod`, `linkMod`, `getUntrackedJars`, `registerJar`, `bulkUpdateMods`, `bulkDeleteMods`

## Features

### Multi-Registry Support
Two providers with unified interface:
- **Modrinth** — Fast, modern API; rich metadata; comprehensive dependencies
- **CurseForge** — Largest mod catalog; requires API key from console.curseforge.com

Both support multi-loader filtering and version compatibility checks.

### Loader Resolution
Auto-detects mod loaders from server variables (ModManagerService.php:86-117):
1. Check `MOD_LOADER` variable (explicit)
2. Check `LOADER` variable (fallback)
3. Check `BUILD_TYPE` if value is a mod loader
4. Parse egg name for fabric/forge/neoforge/quilt keywords
5. Default to all four loaders if detection fails

**NeoForge dual-loader expansion** (ModManagerService.php:274-280):
- NeoForge servers also search `forge` tag (many mods dual-tag)
- Increases compatibility without manual intervention

### Game Version Detection
Reads `MINECRAFT_VERSION` or `MC_VERSION` variable (ModManagerService.php:119-129).
- Ignores `latest` values (too ambiguous for version filtering)
- Returns `null` if undetectable → searches all versions

### Dependency Resolution
- **Required deps** — Auto-installed before main mod
- **Optional deps** — Shown as clickable chips in version picker
- Prevents duplicate installs if already present
- Bulk install chain: installs missing required deps sequentially, then main mod

### Cross-Provider Duplicate Detection
Prevents installing same mod from multiple providers (ModManagerService.php:134-160):
1. Compare slug and title (case-insensitive)
2. For manual mods, parse JAR metadata and compare mod ID
3. On install: 409 response → user confirms replacement
4. Replacement deletes old provider version, installs new

### Manual Mod Support
Upload JAR via file manager → auto-discovered and tracked:
1. `getUntrackedJars()` finds JARs not in `server_mods`
2. Parse metadata (mod ID, name, version) from JAR descriptors
3. Return untracked list to frontend
4. User clicks "Track" → creates `manual` provider entry
5. User clicks "Link" → convert to registry mod for updates

**Supported JAR formats** (ModJarService.php:112-137):
- `fabric.mod.json` — Fabric Loader
- `quilt.mod.json` — Quilt Loader
- `META-INF/mods.toml` — Forge/NeoForge (TOML parser via regex)

### Bulk Operations

**Backend** (ModController.php):
- `POST /mods/bulk/update` — Update up to 50 mods at once
- `DELETE /mods/bulk` — Delete up to 50 mods at once
- Both return `{ success: [], failed: [] }` arrays with partial failure support
- Activity log entries: `server:mod.bulk-update`, `server:mod.bulk-delete`

**Frontend** (ModsContainer.tsx):
- Checkboxes for non-manual mods in Installed tab
- "Select All" / "Clear" buttons
- "Update Selected (N)" / "Delete Selected (N)" buttons
- Spinner during batch operation
- Success/failure flash messages with counts

**Validation** (BulkUpdateModsRequest.php, BulkDeleteModsRequest.php):
- `mod_ids`: array, min 1, max 50 items
- Each ID: integer, min 1
- Enforces Permission::ACTION_MOD_MANAGE

### CurseForge Integration

**Configuration** (Filament Settings → Mods):
- API key required: Get from [CurseForge for Studios Console](https://console.curseforge.com/)
- Key stored in `panel:mods:curseforge_api_key` setting
- Missing key: CurseForge searches return empty (graceful degradation)

**API Details** (CurseForgeService.php):
- Base URL: `https://api.curseforge.com/v1`
- Authentication: `x-api-key` header
- Minecraft game ID: 432, Mods class ID: 6
- Loader type mapping: Fabric=4, Forge=1, NeoForge=6, Quilt=5
- Sort field mapping: Featured=1, LastUpdated=2, TotalDownloads=6

### Enable/Disable Toggle
No DB column — renames file extension (ModManagerService.php:234-249):
- Enabled: `mod.jar`
- Disabled: `mod.jar.disabled`

Minecraft loaders naturally ignore `.disabled` files. State persists across updates (old JAR cleaned up, new JAR inherits state).

### Caching
- **Directory listings** — 30s cache (ModJarService.php:28)
- **JAR metadata** — 1hr cache keyed by `filename+size` (ModJarService.php:64-88)
- Cleared on install/update/delete/toggle operations

## Configuration

### Admin Setup (Filament)
Settings → Mods → Select eggs allowed to use installer (`panel:mods:egg_ids`)

Typically enabled for:
- Fabric
- Forge
- NeoForge
- Quilt
- Hybrid (e.g., Fabric on Paper via Cardboard)

### Permission
`mod.manage` — User needs this subuser permission to access feature

Defined in `app/Models/Permission.php:260-265`:
```php
'mod' => [
    'description' => 'Permissions that control a user\'s access to the mod installer for this server.',
    'keys' => [
        'manage' => 'Allows a user to search, install, update, and remove Minecraft mods on this server.',
    ],
],
```

### Rate Limiting
Dedicated limiter in `RouteConfigServiceProvider.php:103-107`:
- 10 requests per minute per user
- Applied to: install, update, register, untracked endpoints
- Search/versions are unrestricted (cheap read operations)

## Edge Cases

### JAR Parsing
- Supports: `fabric.mod.json`, `quilt.mod.json`, `META-INF/mods.toml`
- Streams to temp file vs loading into memory (ModJarService.php:95-145)
- ponytail: Wings files API has no Range header → still transfers full JAR over wire (ModJarService.php:93)
- Max size: 64 MB (ModJarService.php:12)
- Fallback: filename-based slug/title if parse fails

### TOML Parsing
Forge/NeoForge use TOML descriptors. Minimal regex parser (ModJarService.php:148-163):
```php
modId\s*=\s*["']([^"']+)["']
displayName\s*=\s*["']([^"']+)["']
version\s*=\s*["']([^"']+)["']
```
Handles standard cases; complex TOML (multiline, sections) may fail → filename fallback.

### Version Resolution
Install without version ID → picks first compatible version matching loaders + game version (ModManagerService.php:170-177).

Update checks for newer version than current `version_id` (ModManagerService.php:200-210).

### File Verification
After download, lists `/mods` directory and verifies file exists to prevent ghost tracking (ModManagerService.php:264-269).

### Cross-Provider Conflict
User installs "Sodium" from Modrinth → tries manual upload of same mod → slug match detected → 409 response with conflict details → user confirms replacement → deletes Modrinth version, tracks manual version.

## Modrinth API Integration

**Base URL** — `https://api.modrinth.com/v2`

**Search** (ModrinthService.php:13-53):
- Facets: `project_type:mod`, `versions:{gameVersion}`, `categories:{loader}`
- Sort indices: `relevance`, `downloads`, `updated`
- Returns: hits array + total count

**Versions** (ModrinthService.php:60-83):
- Filters: `loaders`, `game_versions` (JSON-encoded query params)
- Primary file selection (or first file if no primary flag)
- Returns: version array with download URLs, dependencies

**Bulk Projects** (ModrinthService.php:123-143):
- Fetches metadata for multiple project IDs in one call
- Used for dependency info (title, icon)

**Dependency Format** (ModrinthService.php:112-119):
```php
[
    'project_id' => string,
    'required' => bool, // 'required' or 'optional'
]
```

## Known Limitations

1. **No real download progress** — Wings pull endpoint is fire-and-forget; fake progress bar eases to 90% then jumps to 100%
2. **No automatic updates** — User must manually click Update button
3. **No version pinning** — Update always pulls latest compatible version
4. **JAR parsing overhead** — Full JAR downloaded even when only reading metadata
5. **CurseForge API key required** — Admin must obtain and configure key for CurseForge access
6. **Complex TOML unsupported** — Forge mods with non-standard TOML may fail to parse
7. **Bulk operations no rollback** — Partial failures leave some mods updated/deleted; no atomic transaction

## Testing

No `tests/` directory exists. Manual test coverage:
- Install mod with/without dependencies
- Update mod (up-to-date case, new version case)
- Toggle enable/disable (verify `.disabled` extension)
- Delete mod (verify JAR removed from disk)
- Cross-provider duplicate detection (manual vs Modrinth)
- Manual mod tracking and linking
- Search with sort options (relevance, downloads, updated)
- Version picker with dependency chips (required auto-install, optional manual)
- Loader detection (Fabric/Forge/NeoForge/Quilt)
- Game version filtering
- Bulk update selected mods (success/partial failure)
- Bulk delete selected mods (success/partial failure)
- CurseForge provider search and install

## Related Code

- Similar feature: Plugins installer (`app/Services/Plugins/`, `docs/plugins/AGENTS.md`) — same architecture for server plugins
- Permissions: `app/Models/Permission.php:260-265`
- Routes: `routes/api-client.php:171-187`
- Translations: `resources/lang/en/server/mods.php`
- Navigation: `resources/scripts/routers/routes.ts:208-214` (requires `mods` egg feature)
- Migration: `database/migrations/2026_07_25_000001_create_server_mods_table.php`

## Future Enhancements

- **CurseForge support** — Second provider (API key required)
- **Automatic update checks** — Background job scans for outdated mods
- **Bulk operations** — Update/delete multiple mods at once
- **Modpack import** — Install entire modpack from CurseForge/Modrinth manifest
- **Version history** — Rollback to previous versions
- **Conflict detection** — Warn about incompatible mods before install
- **Resource pack/shader pack support** — Extend to other Minecraft content types
