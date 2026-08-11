# Nodes & Allocations

Game servers run on **nodes** (daemon hosts). A node owns a pool of **allocations** (`ip:port` pairs) that servers bind to. This document covers node CRUD, allocation assignment/deletion, auto-deployment of servers onto nodes, and the daemon interaction.

## Entry points

**Application API** — `routes/api-application.php`, prefix `/api/application`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/nodes` | `NodeController::index` | Filter/sort via QueryBuilder (`uuid`, `name`, `fqdn`, `daemon_token_id`) |
| GET | `/nodes/deployable` | `NodeDeploymentController` | Viable-node search for auto-deploy |
| GET | `/nodes/{node}/configuration` | `NodeConfigurationController` | Full daemon config incl. decrypted token — WRITE ACL |
| POST | `/nodes` | `NodeController::store` | Generates UUID + daemon token |
| PATCH | `/nodes/{node}` | `NodeController::update` | Pushes config to daemon |
| DELETE | `/nodes/{node}` | `NodeController::delete` | Blocked while servers attached |
| GET | `/nodes/{node}/allocations` | `AllocationController::index` | Filter by `ip`, `port`, `ip_alias`, `server_id` |
| POST | `/nodes/{node}/allocations` | `AllocationController::store` | Assign ports/IPs/CIDR |
| DELETE | `/nodes/{node}/allocations/{allocation}` | `AllocationController::delete` | Blocked while a server uses it |
| *(all)* | `/locations` | `LocationController` | Location CRUD; delete blocked while nodes attached |

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/network`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/allocations` | `NetworkAllocationController::index` | Requires `allocation.read` |
| POST | `/allocations` | `NetworkAllocationController::store` | Auto-allocation. ResourceLimit: `Allocation` (2/min per server). Requires `allocation.create` |
| POST | `/allocations/{allocation}` | `NetworkAllocationController::update` | Edit notes. Requires `allocation.update` |
| POST | `/allocations/{allocation}/primary` | `NetworkAllocationController::setPrimary` | Requires `allocation.update` |
| DELETE | `/allocations/{allocation}` | `NetworkAllocationController::delete` | Requires `allocation.delete`; blocked unless `allocation_limit` set and not primary |

All `/network` routes sit behind `ResourceBelongsToServer` middleware — a client can never touch an allocation belonging to a different server (404).

**Admin (Filament)** — `app/Filament/Resources/Nodes/` and `app/Filament/Resources/Locations/`

- `NodeResource` with `CreateNode` (wizard) / `EditNode` (tabs + live daemon health/monitoring) / `ListNodes`.
- `AllocationRelationManager` — add allocations (IP/CIDR + port list/ranges), inline-edit `ip_alias`/`notes`, delete.
- `ServersRelationManager`, `LocationResource` + `NodesRelationManager`.
- Settings page (`app/Filament/Pages/Settings.php`) → Advanced: `panel:client_features:allocations:enabled`, `range_start`, `range_end`.

## Services

- `app/Services/Nodes/NodeCreationService` — assigns `uuid`, encrypts a random `daemon_token` (64 chars), `daemon_token_id` (16 chars), then `NodeRepository::create`.
- `app/Services/Nodes/NodeUpdateService` — updates the DB, then pushes the new config to the daemon via `DaemonConfigurationRepository::update`. A daemon connection failure is **swallowed** (logged) and the update is still persisted; the caller gets `ConfigurationNotPersistedException` and the UI tells the admin to push config manually. Never retries or rolls back.
- `app/Services/Nodes/NodeDeletionService` — refuses if any server has `node_id`; otherwise `NodeRepository::delete`.
- `app/Services/Nodes/NodeJWTService` — builds per-node, per-subject HMAC JWTs (`scope` = space-joined `JwtScope` values; `user_uuid`, `sub`, `jti` = sha256 of `identifiedBy`). Requires ≥1 scope via `Assert::notEmpty`; an unset scope array now defaults to `[]` so a missing scope surfaces as `InvalidArgumentException`, not a raw `Error`.
- `app/Services/Allocations/AssignmentService` — bulk-inserts allocations for a node. Expands CIDRs (`/25`–`/32` IPv4, `/113`–`/128` IPv6), single ports, and `a-b` ranges (max 1000 ports). `insertIgnore` skips already-existing `(node_id, ip, port)` rows silently. Port bounds: 1025–65535.
- `app/Services/Allocations/FindAssignableAllocationService` — client auto-allocation. Finds a free allocation on the server's IP; if none, computes a random port in the configured range and creates it. Both paths run inside a DB transaction (see `NetworkAllocationController::store`) and use `lockForUpdate` so two concurrent requests cannot claim the same allocation.
- `app/Services/Allocations/AllocationDeletionService` — refuses when `server_id` is set.
- `app/Services/Deployment/FindViableNodesService` — selects public nodes in the given locations with enough free memory/disk (respecting overallocate %) for the requested server. Excludes non-public nodes.
- `app/Services/Deployment/AllocationSelectionService` — picks one free allocation from the viable nodes, with optional dedicated-IP filtering and port restrictions.

## Allocation uniqueness & claiming

- DB unique index `(node_id, ip, port)` — the panel's hard guarantee against duplicate allocations. `insertIgnore` depends on it.
- `servers.allocation_id` has a **unique** index + FK. This is the hard guarantee that no two servers share a primary allocation; two concurrent auto-allocations *without* row locks would surface as an unhandled `SQLSTATE[23000]` instead of a clean error, which is why the services lock rows first.
- Allocation `server_id` FK is `ON DELETE SET NULL`: deleting a server frees its allocations automatically. `ServerDeletionService` also clears allocation `notes` before deleting.
- `allocations.node_id` FK is `ON DELETE CASCADE`; `servers.node_id` is `ON DELETE RESTRICT` (node deletion is blocked while servers exist); `database_hosts.node_id` is `ON DELETE SET NULL`; `mount_node.node_id` is `ON DELETE CASCADE`.

## Server creation & deployment

`app/Services/Servers/ServerCreationService`:

1. With a `DeploymentObject`: `FindViableNodesService` narrows to viable nodes, then `AllocationSelectionService` picks a free allocation (dedicated-IP aware, port-restricted).
2. The selected allocation's `node_id` **always wins** over any caller-supplied node — a server can only live on the node that owns its primary allocation (this is the server-transfer constraint).
3. Inside the DB transaction the primary allocation row is `lockForUpdate`'d before the server insert, so two concurrent creations cannot grab the same allocation (the unique index is the backstop).

## Concurrency / race conditions

- **Client auto-allocation** (`FindAssignableAllocationService`): `lockForUpdate` on the free-allocation candidate (or on the primary allocation + free ports in the create path). Both calls require the enclosing `NetworkAllocationController::store` transaction; the controller also `lockForUpdate`s the server row first and enforces `allocation_limit`.
- **Server creation** (`ServerCreationService`): primary allocation locked inside the create transaction.
- **Dedicated IPs** (`AllocationRepository::getRandomAllocation` with `$dedicated`): discards any `(node_id, ip)` already used by another server before picking.
- Known residual gap: `createNewAllocation` computes the free-port set, then calls `AssignmentService` (which `insertIgnore`s). Two concurrent requests for the *same server/IP* are serialized by the row lock, so the port cannot be double-assigned. The unique index still guards against any path that skips the lock.

## Validation

- `Allocation::$validationRules` — `ip` must be a real IP, `port` 1024–65535 (note: `AssignmentService` enforces the stricter 1025+; the model rule allows 1024, a documented legacy mismatch).
- `Node::$validationRules` — FQDN is free-form `required|string`; `memory`/`disk` min 1; overallocate min -1; `daemonListen`/`daemonSFTP` 1–65535; `daemonBase` must start with `/`.
- `StoreAllocationRequest` (API) maps `ip`/`ports`/`alias` → `allocation_ip`/`allocation_ports`/`allocation_alias`. Filament's `AllocationRelationManager` uses the latter keys directly.
- `StoreNodeRequest` maps `daemon_listen`/`daemon_sftp`/`daemon_base` snake_case → `daemonListen`/`daemonSFTP`/`daemonBase`.

## Deletion cascades

- **Node**: blocked if servers exist (service + Filament `DeleteAction` + `ServersRelationManager` bulk delete). Also blocked if any `server_transfers` row references it (`old_node`/`new_node` have **no FK** — the check is manual; see `EditNode::getHeaderActions`).
- **Allocation**: blocked if `server_id` set. Node deletion cascades to its allocations.
- **Location**: blocked if nodes exist.
- **Server**: allocations `server_id` set null (FK), notes cleared; `server_transfers` cascade with the server row (FK on `server_id` only).

## Permissions / ACL

- Application API: `AdminAcl::RESOURCE_NODES` / `RESOURCE_ALLOCATIONS` / `RESOURCE_LOCATIONS`; config/configuration endpoints require `WRITE` (the config contains the daemon token and connection details).
- Client API: `allocation.read/create/update/delete` subuser permissions via `Permission::ACTION_ALLOCATION_*`. Server owner / root admin bypass via `ServerPolicy::before`.
- `ResourceBelongsToServer` middleware 404s any allocation not owned by the server in the route.

## Gotchas

- **`s1lentium/iptools` emits a PHP 8.5 deprecation** on every `Network::parse` call (`(integer)` cast). Harmless; pinned in `AssignmentService`.
- **`NodeUpdateService` does not roll back on daemon failure**: DB update persists, config push is best-effort. Admin must push config manually (`generateToken`/config tab) or via CLI.
- **`getConfiguration()` exposes the decrypted token** — only reachable via the WRITE-ACL configuration endpoint, the Filament config tab, and `p:node:configuration`. Do not add it to public or READ-ACL surfaces.
- **`isViable()` requires `sum_memory`/`sum_disk` select aliases**: calling it on a plain `Node::find()` returns `true` (null + request <= limit) instead of real usage. Always hydrate via `NodeRepository::getNodeWithResourceUsage` or the join used by `FindViableNodesService`.
- **`AssignmentService` CIDR validation is version-aware**: IPv4 `/25`–`/32`, IPv6 `/113`–`/128`. An IPv4 mask on an IPv6 CIDR (or vice-versa) throws `CidrOutOfRangeException`.
- **Reversed port ranges** (`30000-20000`) fail the `PORT_RANGE_LIMIT` check first (appear as `TooManyPortsInRangeException`), not `InvalidPortMappingException`.
- **`server_transfers` store node ids denormalized without FKs** — always guard node deletion against both `old_node` and `new_node` references.
- **Client auto-allocation requires an enclosing transaction**: `lockForUpdate` outside a transaction is a no-op in some drivers. `NetworkAllocationController::store` wraps `FindAssignableAllocationService::handle` in `Activity::event(...)->transaction(...)`.
- **Auto-allocation settings come from the DB** (`SettingsServiceProvider` overrides `config()` at boot), so `config('panel.client_features.allocations.enabled')` in tests must be set *after* boot (or `APP_ENVIRONMENT_ONLY=true`).
- **Unique index vs `insertIgnore` on SQLite**: `insertOrIgnore` is portable across MySQL/MariaDB/SQLite (see `EloquentRepository::insertIgnore`).
- **Node health/monitoring calls hit the daemon live** (`DaemonConfigurationRepository`/`DaemonMonitoringRepository`) on the Filament edit page; each TextEntry makes its own HTTP request. Offline daemons render "unavailable".

## i18n

Admin strings in `resources/lang/en/admin/node.php` and `resources/lang/en/admin/locations.php`. Exception messages in `resources/lang/en/exceptions.php` (`exceptions.node.*`, `exceptions.allocations.*`, `exceptions.deployment.*`, `exceptions.locations.*`). Client-visible auto-allocation errors are raised as `DisplayException` subclasses with hardcoded English messages (`NoAutoAllocationSpaceAvailableException`, `AutoAllocationNotEnabledException`) — no translation keys exist for these yet.
