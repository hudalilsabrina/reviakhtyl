# Plugins Installer

Minecraft server plugin manager with multi-registry support (Modrinth, Hangar, SpigotMC). Browse, install, update, toggle, and manage plugins directly from the panel.

## Architecture

### Backend (PHP)

**Core Services** (`app/Services/Plugins/`)
- `PluginManagerService` — Install, update, delete, toggle operations; loader/version resolution; cross-provider duplicate detection
- `PluginJarService` — Parse JAR metadata from plugin.yml, paper-plugin.yml, velocity-plugin.json, bungee.yml
- `PluginProviderInterface` — Contract for registry providers
- `ModrinthService`, `HangarService`, `SpigetService` — Registry API implementations

**API** (`app/Http/Controllers/Api/Client/Servers/PluginController`)
- `GET /plugins` — List installed plugins, game version, loaders
- `GET /plugins/search` — Search registry
- `GET /plugins/versions` — List versions with dependencies
- `GET /plugins/untracked` — Discover manually uploaded JARs
- `POST /plugins` — Install plugin (with optional version, cross-provider duplicate check)
- `POST /plugins/{id}/link` — Link manual plugin to registry for updates
- `POST /plugins/register` — Track uploaded JAR as manual plugin
- `PATCH /plugins/{id}` — Update to latest compatible version
- `PATCH /plugins/{id}/toggle` — Enable/disable via `.disabled` extension
- `DELETE /plugins/{id}` — Remove plugin and delete JAR

**Model** (`app/Models/ServerPlugin`)
- Table: `server_plugins`
- Unique: `(server_id, provider, project_id)`
- `disabled` accessor checks `str_ends_with($file_name, '.disabled')`
- Relationship: `Server->plugins()` HasMany

### Frontend (React)

**Components** (`resources/scripts/components/server/plugins/`)
- `PluginsContainer` — Main orchestrator, state management, API calls
- `BrowseTab` — Search UI, provider/sort selectors, install cards
- `InstalledTab` — Installed/untracked plugin cards, update/toggle/remove actions
- `VersionPickerModal` — Version list with dependency resolution
- `InstallProgressModal` — 3-step progress (resolve → download → finish)
- `Badge`, `PluginIcon`, `ProgressBar`, `useProgress` — Shared UI elements

**API Client** (`resources/scripts/api/server/plugins/plugins.ts`)
- Typed interfaces: `ServerPlugin`, `PluginHit`, `PluginVersion`, `PluginDependency`, `UntrackedJar`
- Functions: `getPlugins`, `searchPlugins`, `installPlugin`, `updatePlugin`, `deletePlugin`, `togglePlugin`, `linkPlugin`, `getUntrackedJars`, `registerJar`

## Features

### Multi-Registry Support
Three providers with unified interface:
- **Modrinth** — Modern API, fast, rich metadata
- **Hangar** — PaperMC's official registry
- **SpigotMC** (via Spiget) — Largest plugin catalog

### Loader Resolution
Auto-detects server type from `BUILD_TYPE` egg variable (PluginManagerService.php:14-23):
```php
'paper' => ['paper', 'spigot', 'bukkit'],
'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
'folia' => ['folia', 'paper', 'spigot', 'bukkit'],
'velocity' => ['velocity'],
'bungeecord' => ['bungeecord'],
```

Game version from `MINECRAFT_VERSION` variable (PluginManagerService.php:100-109).

### Dependency Resolution
- **Required deps** — Auto-installed before main plugin
- **Optional deps** — Shown as clickable chips in version picker
- Hangar dependency ID normalization (`owner/slug` format)
- Prevents duplicate installs if already present

### Cross-Provider Duplicate Detection
Prevents installing same plugin from multiple registries (PluginManagerService.php:115-144):
1. Compare slug and title (case-insensitive)
2. For manual plugins, parse JAR metadata and compare plugin.yml name
3. On install: 409 response → user confirms replacement
4. On update/link: DisplayException blocks operation

### Manual Plugin Support
Upload JAR via file manager → auto-discovered and tracked:
1. `getUntrackedJars()` finds JARs not in `server_plugins`
2. Parse metadata (name, version) from JAR descriptors
3. User clicks "Track" → creates `manual` provider entry
4. User clicks "Link" → convert to registry plugin for updates

### Enable/Disable Toggle
No DB column — renames file extension (PluginManagerService.php:222-237):
- Enabled: `plugin.jar`
- Disabled: `plugin.jar.disabled`

Server naturally ignores `.disabled` files. Persists across updates (old JAR cleaned up, new JAR inherits state).

### Caching
- **Directory listings** — 30s cache (PluginJarService.php:28)
- **JAR metadata** — 1hr cache keyed by `name+size` (PluginJarService.php:68)
- Cleared on install/update/delete/toggle operations

## Configuration

### Admin Setup (Filament)
Settings → Plugins → Select eggs allowed to use installer (`panel:plugins:egg_ids`)

### Permission
`plugin.manage` — User needs this subuser permission to access feature

### Rate Limiting
Dedicated limiter in `RouteConfigServiceProvider.php:96`

## Edge Cases

### JAR Parsing
- Supports: `plugin.yml`, `paper-plugin.yml`, `bungee.yml`, `velocity-plugin.json`
- Streams to temp file vs loading into memory (PluginJarService.php:101-137)
- ponytail: Wings files API has no Range header → still transfers full JAR over wire
- Max size: 64 MB (PluginJarService.php:12)
- Fallback: filename-based slug/title if parse fails

### Version Resolution
Install without version ID → picks first compatible version matching loaders + game version (PluginManagerService.php:157-164).

Update checks for newer version than current `version_id` (PluginManagerService.php:186-196).

### File Verification
After download, lists `/plugins` directory and verifies file exists to prevent ghost track (PluginManagerService.php:254-259).

### Cross-Provider Conflict
User installs "Vault" from Modrinth → tries to install from SpigotMC → 409 response with conflict details → user confirms replacement → deletes Modrinth version, installs SpigotMC version.

## Known Limitations

1. **No real download progress** — Wings pull endpoint is fire-and-forget; fake progress bar eases to 90% then jumps to 100%
2. **No automatic updates** — User must manually click Update button
3. **No bulk operations** — Must update/delete one plugin at a time
4. **No version pinning** — Update always pulls latest compatible version
5. **JAR parsing overhead** — Full JAR downloaded even when only reading metadata

## Testing

No `tests/` directory exists. Manual test coverage:
- Install plugin with/without dependencies
- Update plugin (up-to-date case, new version case)
- Toggle enable/disable (verify `.disabled` extension)
- Delete plugin (verify JAR removed from disk)
- Cross-provider duplicate detection (install, update, link)
- Manual plugin tracking and linking
- Search across providers with sort options
- Version picker with dependency chips

## Related Code

- Similar feature: Mods installer (`app/Services/Mods/`) — same architecture for modpacks
- Permissions: `app/Models/Permission.php:100,253-256`
- Routes: `routes/api-client.php:153-168`
- Translations: `resources/lang/en/server/plugins.php`
- Navigation: `resources/scripts/routers/routes.ts:200-204` (requires `plugins` egg feature)
