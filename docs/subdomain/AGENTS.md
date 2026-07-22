# Subdomain

SRV-record subdomains for game servers (Minecraft Java primary). Players connect via `name.domain.com` without a port. DNS records live in Cloudflare; panel manages them via Cloudflare's REST API.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/subdomain`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/` | `SubdomainController::index` | Current subdomain, available domains, suggested name |
| POST | `/` | `SubdomainController::store` | Create/replace. Rate-limited: `api.subdomain` (5/min per user) |
| DELETE | `/` | `SubdomainController::delete` | 204 on success |

**Core service** — `app/Services/Servers/CloudflareSubdomainService.php`

- `isEnabledFor(Server)` — gate: needs ≥1 enabled domain AND server's egg in allowlist
- `store(Server, subdomain, domainId)` — create or replace SRV record
- `sync(Server)` / `syncQuietly(Server)` — re-point SRV at current primary allocation
- `destroy(Server)` / `destroyQuietly(Server)` — delete DNS record + DB row
- `testDomain(CloudflareDomain)` — admin connectivity check (used from Filament table action)
- `fetchZones(apiToken)` (static) — zone listing for importer

**Models**

- `ServerSubdomain` — `server_id` (1:1), `cloudflare_domain_id`, `subdomain`, `domain`, `cf_record_id`. `getFqdn()` returns `subdomain.domain`.
- `CloudflareDomain` — `domain`, `zone_id`, `is_enabled`. Has `subdomains()` hasMany.

**Admin (Filament)** — `app/Filament/Resources/CloudflareDomains/`

- Resource: `CloudflareDomainResource.php` — CRUD + "Test" action per row.
- Lister: `Pages/ListCloudflareDomains.php` — "Import from Cloudflare" pulls zones via `fetchZones()` and bulk-inserts.

**Settings** — `app/Filament/Pages/Settings.php` → Advanced → Cloudflare section

- `panel:cloudflare:api_token` — Encrypter-encrypted token with Zone.DNS edit scope.
- `panel:cloudflare:egg_ids` — JSON string array of egg IDs allowed to use subdomains.

**Frontend** — `resources/scripts/components/server/subdomain/SubdomainContainer.tsx`

- Route gated by `eggFeature: 'subdomain'` in `resources/scripts/routers/routes.ts`.
- `egg_features` array injected by `app/Transformers/Api/Client/ServerTransformer.php` when service enabled.
- API helpers: `resources/scripts/api/server/subdomain/{get,set,delete}ServerSubdomain.ts`.

**Hooks into other flows**

- `ServerDeletionService::handle()` → `destroyQuietly()` inside DB transaction before `$server->delete()`.
- `NetworkAllocationController` → `syncQuietly()` after primary allocation changes.

## Patterns unique to this feature

- **Replace-before-create** in `store()`: old CF record deleted first (may live in different zone). Failure leaves old record intact, preventing phantom duplicates.
- **Two-tier availability check**: `isNameAvailable()` queries local DB (fast) then Cloudflare API (catches out-of-band records). `suggest()` offers `-1`, `-2`, `-3` suffixes.
- **Sanitization split**: service `sanitize()` is permissive (`[a-z0-9-]`, trim dashes, cap at 63); `StoreSubdomainRequest` adds stricter regex (no leading/trailing dash, alphanumeric bookends).
- **Idempotent store**: matching subdomain + domain returns existing row without hitting Cloudflare.
- **`Quietly` variants** (`syncQuietly`, `destroyQuietly`) swallow all `\Throwable` — used in hooks where failure shouldn't block the parent operation (allocation reassignment, server deletion).
- **Permission**: `Permission::ACTION_SUBDOMAIN_MANAGE` (`subdomain.manage`). All three request classes live at `app/Http/Requests/Api/Client/Servers/Subdomain/`.
- **Egg allowlist stored as JSON setting**, not a relation table. Parsed via `json_decode` in `enabledEggIds()`.

## Gotchas

- **Token scope**: must have Zone.DNS edit on target zone. Panel stores encrypted; falls back to plaintext if `Encrypter::decrypt` throws (legacy unencrypted data).
- **Minecraft Java hardcoded**: `createSrvRecord()` uses `_minecraft._tcp`. Extending to other games requires new service/proto constants and probably a port field on the model.
- **Rate limit per-user, not per-server**: batch operations from a single account share the 5/min bucket.
- **Disabling a domain** (`is_enabled = false`) hides from UI but leaves DB rows + DNS records untouched — existing subdomains keep resolving.
- **DB unique on `['subdomain', 'domain']`**: panel check precedes insert, but concurrent requests can race to `SQLSTATE[23000]`. Consider retry or advisory lock if this surfaces.
- **No reconciliation job**: if Cloudflare API fails during server deletion, DNS record is orphaned forever. Manual cleanup only.
- **`server.subdomain` relation stale after mutations**: controllers either refresh server or frontend re-fetches via `getServer(uuid)`.
- **Suggested name can be empty**: `sanitize(server.name)` returns empty string if name starts with invalid chars. Frontend renders empty input with no fallback.
- **`cf_record_id` may be null**: historical rows or failed creation retries. `deleteRecord()` is tolerant (skips if null), but `isNameAvailable()` passes it as `ignoreRecordId` to the CF list query.

## i18n

All user-facing strings in `resources/lang/en/server/subdomain.php`. Frontend loads via namespace `server/subdomain`. Add new locales at `resources/lang/<locale>/server/subdomain.php`.
