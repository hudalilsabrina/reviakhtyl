# Databases

Per-server MySQL/MariaDB databases on external database hosts. Each database gets its own user with a generated password; clients can view/rotate the password and delete the database. Hosts are managed from the admin panel.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/databases`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/` | `DatabaseController::index` | Lists DBs. `include=password` returns password only with `database.view_password` |
| POST | `/` | `DatabaseController::store` | Create. Throttled: `ResourceLimit::Database` (2/min per server) |
| POST | `/{database}/rotate-password` | `DatabaseController::rotatePassword` | Rotate; returns fresh password |
| DELETE | `/{database}` | `DatabaseController::delete` | 204 on success |

All routes sit behind `ResourceBelongsToServer` middleware (404 if DB not on the server) and permission requests.

**Application API** — `routes/api-application.php`, prefix `/api/application/servers/{server:id}/databases`. Index, view, create, `/{database:id}/reset-password`, delete. Admin API-key auth only; `includePassword` here has no subuser gate (admin scope).

**Services** — `app/Services/Databases/`

- `DatabaseManagementService` — `create(Server, data)`, `delete(Database)`, static `generateUniqueDatabaseName(name, serverId)`. Wraps host work + panel row in one transaction; on failure drops the half-created host DB/user.
- `DatabasePasswordService` — `handle(Database|int)` rotates: updates panel password hash, drops + recreates the host user, re-grants. Returns plaintext password.
- `DeployServerDatabaseService` — `handle(Server, data)` picks a host (same node first, else random if `panel.client_features.databases.allow_random`), names the DB `s{serverId}_{name}`, delegates to `DatabaseManagementService`.
- `Hosts/HostCreationService`, `Hosts/HostUpdateService`, `Hosts/HostDeletionService` — admin host CRUD with connection verification (`SELECT 1 FROM dual` against the host before committing). These are currently only exercised via tests; the Filament DatabaseHost resource manages hosts directly.

**Models**

- `Database` — `server_id`, `database_host_id`, `database`, `username` (`u{serverId}_{rand}`), `remote` (allow-connect-from), encrypted `password`, nullable `max_connections`. `$hidden` excludes password from JSON. `remote` regex: `[\w\-\/.%:]+`. Name regex (service): must be `s{id}_`-prefixed.
- `DatabaseHost` — `name`, `host`, `port`, `username`, encrypted `password`, nullable `max_databases`, nullable `node_id`. `$hidden` excludes password.

**Repositories** — `app/Repositories/Eloquent/DatabaseRepository.php` runs raw statements against the `dynamic` connection (configured per-host by `DynamicDatabaseConnection`): `createDatabase`, `createUser`, `assignUserToDatabase`, `dropDatabase`, `dropUser`, `flush`. Identifiers are backtick-quoted; inputs are pre-validated (alpha_dash names, regex remote, generated username) so no user data can break out.

**Frontend** — `resources/scripts/components/server/databases/`

- `DatabasesContainer.tsx` — list + create button, gated by `database.create`; shows "X of Y allocated" when a limit exists.
- `DatabaseRow.tsx` — per-DB card; connection modal with endpoint/username/password/JDBC string. Password and JDBC-with-password rendered only under `database.view_password`.
- `CreateDatabaseButton.tsx` — create modal (name + connections-from). Name yup regex `[\w-]{3,48}` matches server `alpha_dash` (no periods server-side).
- `RotatePasswordButton.tsx` — rotate, rendered under `database.update`.

API helpers: `resources/scripts/api/server/databases/{getServerDatabases,createServerDatabase,deleteServerDatabase,rotateDatabasePassword}.ts`. State: `resources/scripts/state/server/databases.ts` (set/append/remove).

**Chatbot tools** — `app/Services/Chatbot/Tools/Databases/` (`config/chatbot.php` → `tools`): `ListDatabasesTool`, `CreateDatabaseTool` (returns decrypted password only under `database.view_password`), `DeleteDatabaseTool`.

**Hooks into other flows**

- `ServerDeletionService::handle()` → `DatabaseManagementService::delete()` per database inside its transaction; non-forced deletes throw on host failure, forced deletes remove the panel row anyway (leaves dangling host user — see gotchas).

## Permissions

`app/Models/Permission.php`:

- `database.read` — index
- `database.create` — store
- `database.update` — rotate-password
- `database.delete` — delete
- `database.view_password` — password visibility in the transformer include (`DatabaseTransformer::includePassword`), the connection modal, and the chatbot `CreateDatabaseTool`

`view_password` is a **separate** gate: a subuser with `database.create` but not `database.view_password` can create a DB but never sees its initial password. Frontend route `server.databases` requires `database.*`.

## Patterns unique to this feature

- **Two-layer storage for credentials**: host and database passwords are encrypted at rest (`Encrypter`), decrypted only (a) by `DynamicDatabaseConnection` when opening the host connection, or (b) in a response include/tool that has passed the permission check. `$hidden` keeps them out of plain model JSON.
- **Host-side rollback on create failure**: `DatabaseManagementService::create` runs the panel insert and host DDL in one transaction; if any host statement fails, the transaction rolls back the panel row and the catch block best-effort drops the host DB/user. A failed host connection leaves no panel row.
- **Deploy picks node-local host first**: `DeployServerDatabaseService` prefers a host linked to the server's node, falls back to any host when `allow_random` is enabled.
- **Name sanitization**: user names are capped so `s{serverId}_` + name ≤ 48 chars; uniqueness is enforced per `server_id` + full name in the request `Rule::unique` and re-checked in `createModel`.
- **Activity events** (single entry per operation): `server:database.create`, `server:database.rotate-password`, `server:database.delete` logged from the service/controllers (client + application API + Filament relation manager + chatbot all funnel through the service). Translations in `resources/lang/en/activity.php` → `server.database.*`.
- **Resource limit**: database creation shares a per-server `ResourceLimit::Database` throttle (2/min) keyed by `api.client:server-resource:database` — not per-user.

## Gotchas

- **Row lock must be re-queried**: `$database->lockForUpdate()` on a route-bound model is a no-op. `rotatePassword` re-fetches inside the transaction (`newQuery()->whereKey(...)->lockForUpdate()`) to serialize concurrent rotations; otherwise drop/recreate on the host can interleave and one rotation fails.
- **`remote` characters**: allowed set `[\w\-\/.%:]`. `%` = anywhere. A bare IP like `1.2.3.4` is fine; hostnames with dashes fine; no wildcards beyond `%`.
- **`database` name characters**: server-side `alpha_dash` (letters, digits, `_`, `-`). No periods, despite upstream frontends sometimes suggesting them — keep frontend yup in sync.
- **No connection cleanup on `delete` host failure**: if the host is unreachable during server deletion, a forced delete removes the panel row and leaves the MySQL user/database on the host. Reconcile manually or delete the host DB first.
- **`max_connections`**: only applied to `CREATE USER ... WITH MAX_USER_CONNECTIONS` when non-empty; `0`/null means unlimited. Admin UI clamps at 0.
- **DB host password re-encryption**: hosts created via the Filament resource encrypt in `mutateFormDataBeforeCreate`; edits keep the existing hash when the password field is left blank (`dehydrated(fn ($state) => filled($state))`).
- **N+1 avoided**: both index controllers eager-load `databases()->with('host')`; the client transformer also `loadMissing('host')` as a safety net.
- **Nested transactions**: `ActivityLogService::transaction` + `DatabaseManagementService::create`'s inner `connection->transaction` nest as savepoints on MySQL — fine, but the activity log and DDL commit together at the outer boundary.

## i18n

User-facing strings in `resources/lang/en/server/databases.php` (plus 14 locale copies). Frontend loads via namespace `server/databases`. Activity strings in `resources/lang/en/activity.php`. Admin host UI strings in `resources/lang/en/admin/databases.php`; admin server relation manager in `resources/lang/en/admin/server.php` (`database_limit`, `server.databases.*`).
