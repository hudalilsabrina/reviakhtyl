# Server Properties Editor

Form UI over a Minecraft server's `server.properties`, plus an EULA banner and a raw-file tab. File-backed only — there is **no database table and no migration**.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/properties`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/properties` | `PropertiesController::index` | Parsed values + labelled schema + `eula_accepted` |
| PUT | `/properties` | `PropertiesController::update` | Body `{properties: {key: value}}`, only changed keys |
| PUT | `/properties/raw` | `PropertiesController::updateRaw` | Body `{content}`, overwrites the whole file |
| POST | `/properties/eula` | `PropertiesController::acceptEula` | Writes `eula=true` to `/eula.txt` |

All three writes are behind `throttle:api.properties` (30/min, `RouteConfigServiceProvider`).

**Schema** — `config/server_properties.php`

- `groups` — render order (`general`, `gameplay`, `world`, `players`, `performance`, `network`, `security`, `other`).
- `properties` — per key: `group`, `type` (`bool|int|string|enum`), `default`, and optionally `options`, `min`, `max`, `locked`, `sensitive`, `warn`.
- Data only. **Labels and descriptions live in `resources/lang/en/server/properties.php` under `fields.<key>`**, keyed by the literal property name.

**Service** — `app/Services/Properties/ServerPropertiesService.php`

- `isEnabledFor()` / `enabledEggIds()` — reads `settings::panel:properties:egg_ids`, same shape as `PluginManagerService`.
- `read()` → `['exists' => bool, 'raw' => string, 'values' => [key => value]]`.
- `parse()` → ordered node list; each node is `raw` (comment/blank) or `entry` (`key`, `raw_key`, `value`, `source`).
- `render($nodes, $changes)` — rewrites only changed entries, appends genuinely new keys at the end.
- `normalize($changes)` — validates and stringifies; **must be called before `apply()`**, which takes already-normalized input.
- `eulaAccepted()` / `acceptEula()`.

**Gating**

- Admin picks eggs in Filament: Settings → Server Properties (`panel:properties:egg_ids`). Empty = disabled everywhere.
- `ServerTransformer` pushes a synthetic `properties` egg feature when `isEnabledFor()`, exactly like `plugins`/`mods`.
- Nav entry gated by `eggFeature: 'properties'` + `permission: 'properties.*'` in `resources/scripts/routers/routes.ts`.
- Permission: `Permission::ACTION_PROPERTIES_MANAGE` (`properties.manage`), one permission for both read and write.

**Frontend** — `resources/scripts/components/server/properties/`

- `PropertiesContainer.tsx` — orchestrator: tabs, search, dirty tracking, banners.
- `PropertyField.tsx` — control per `type`, reset-to-default button, locked/warn markers.
- `RawEditorTab.tsx` — reuses `CodemirrorEditor`/`MonacoEditor` per the user's `fileEditor` preference, mode `text/x-properties`.
- `Banner.tsx` — shared EULA / restart banner.
- API module: `resources/scripts/api/server/properties/properties.ts`.

## Patterns unique to this feature

- **Round-trip fidelity over re-serialisation.** `parse()` keeps every original line in `source`; `render()` emits those bytes verbatim for anything the user did not change. An unchanged save is byte-identical to the file on disk, comments and key order included.
- **Labels resolved server-side.** Property names contain dots (`query.port`, `rcon.password`) which collide with i18next dot notation, so the controller reads `trans('server/properties.fields')` as an array and ships resolved `label`/`description` strings in the API response. The client only translates its own chrome.
- **Baseline includes defaults.** A key absent from the file is presented with the Minecraft default. `baselineFor()` in the container mirrors this, so resetting a field to its default is correctly *not* a change and is not written.
- **Locked keys.** `server-port`, `query.port`, `rcon.port` and `server-ip` are marked `locked`. `EggConfigurationService` rewrites them from the allocation on every boot, so they render read-only and `normalize()` drops them silently rather than erroring.
- **Unknown keys are first-class.** Anything in the file that is not in the schema is appended to the `other` group as a string field, and always survives a write.
- **Saving never restarts.** The container shows a restart banner and sends `SocketRequest.SET_STATE` only when the user clicks it (`start` if offline, otherwise `restart`).

## Gotchas

- **`apply()` does not validate.** It takes the output of `normalize()`. Calling it with raw user input writes unvalidated values straight to the file.
- **Value escaping is asymmetric by design.** Values are read back with `\uXXXX` decoded so the form shows real characters, but written with non-ASCII re-encoded to `\uXXXX` (plus leading space / `#` / `!` escaped). Other backslash sequences are left alone, because MOTDs are routinely pasted around with literal `§` colour codes in them. Only changed keys are rewritten, so untouched values never churn.
- **Two daemon round trips per page load** — `server.properties` and `eula.txt` are fetched separately. No caching.
- **A missing file is inferred from a 404** on the daemon response (`DaemonConnectionException::getStatusCode()`); any other status still bubbles up.
- **Line continuations** (a line ending in an odd number of backslashes) are merged for parsing, but a changed entry is rewritten as a single line.
- **512 KB cap** on both read and raw write (`ServerPropertiesService::MAX_BYTES`). The `max:` rule in `UpdateRawPropertiesRequest` counts characters; the service re-checks bytes.
- **The console EULA popup still exists** (`resources/scripts/components/server/features/eula/EulaModalFeature.tsx`, egg feature `eula`). Both write the same file; the banner is additive.
- **Schema drift is silent.** Minecraft adding or removing a property does not break anything — new keys land in `other`, removed keys just stop appearing — but the schema in `config/server_properties.php` has to be updated by hand to get a typed control and a label.

## i18n

`resources/lang/en/server/properties.php` — UI chrome, `groups.*`, and `fields.<key>.{label,description}` for all 63 known properties. `resources/lang/en/routes.php` has `server.properties`. Activity strings live under `server.properties.*` in `resources/lang/en/activity.php`.

## Testing

No `tests/` directory exists. Manual coverage:

- Save with no changes → file unchanged byte-for-byte.
- Change a value → only that line differs; comments, blank lines and key order preserved.
- Reset a field to default → change count returns to zero.
- Locked field → read-only in UI, ignored if forced into the request body.
- Unknown key added via the raw tab → appears under "Other" on the form tab.
- Missing `server.properties` → form shows defaults, first save creates the file.
- Missing `eula.txt` → banner appears, Accept writes `eula=true`.
- Subuser without `properties.manage` → no nav item, 403 from the API.
