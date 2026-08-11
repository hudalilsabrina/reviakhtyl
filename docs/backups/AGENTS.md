# Backups

Server file backups, created by the Agent daemon and stored either on the daemon's disk (`agent` adapter) or in S3. Users list, create, lock, download, restore and delete backups through the client API; the daemon reports progress back over the remote API.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/backups`

| Method | Path | Handler | Permission |
|--------|------|---------|------------|
| GET | `/` | `BackupController::index` | `backup.read` |
| POST | `/` | `BackupController::store` | `backup.create` |
| GET | `/{backup}` | `BackupController::view` | `backup.read` |
| GET | `/{backup}/download` | `BackupController::download` | `backup.download` |
| POST | `/{backup}/lock` | `BackupController::toggleLock` | `backup.delete` |
| POST | `/{backup}/restore` | `BackupController::restore` | `backup.restore` + `ResourceLimit::Backup` (3 per 15 min per server) |
| DELETE | `/{backup}` | `BackupController::delete` | `backup.delete` |

Every route sits behind `AuthenticateServerAccess` → `Server::validateCurrentState()`, which rejects requests while the server is suspended, under maintenance, not installed, `restoring_backup`, or transferring. `ResourceBelongsToServer` guarantees the `{backup}` route model belongs to the `{server}` (404 otherwise).

**Remote API (Agent → Panel)** — `routes/api-remote.php`, prefix `/api/remote/backups`

- `GET /{backup}` — `BackupRemoteUploadController` (invokable): hands back presigned S3 `UploadPart` URLs for a multipart upload. Node ownership is checked against `model.server.node_id`.
- `POST /{backup}` — `BackupStatusController::index`: Agent reports backup success/failure (`successful`, `checksum_type`, `checksum`, `size`, `parts`). Marks `completed_at`, unlocks on failure, and completes/aborts the S3 multipart upload.
- `POST /{backup}/restore` — `BackupStatusController::restore`: Agent reports restore completion. Clears `server.status`, logs `server:backup.restore-complete` / `server.backup.restore-failed`.

**Core services** — `app/Services/Backups/`

- `InitiateBackupService::handle(Server, ?name, bool $override)` — throttle check (`backups.throttles`), backup-limit check, optional oldest-unlocked purge, then creates the row and tells the daemon to generate it. All inside a transaction.
- `DeleteBackupService::handle(Backup)` — wings backups: daemon delete (a 404 means "already gone", still delete the row). S3 backups: delete object + row in a transaction. Locked successful backups throw `BackupLockedException`; failed backups can always be deleted.
- `DownloadLinkService::handle(Backup, User)` — wings: JWT with `backup-download` scope (`NodeJWTService`). S3: 5-minute presigned `GetObject` URL.

**Repositories**

- `app/Repositories/Eloquent/BackupRepository.php` — `getBackupsGeneratedDuringTimespan()` (throttle, includes trashed), `getNonFailedBackups()` (HasMany builder for completed-null or successful).
- `app/Repositories/Agent/DaemonBackupRepository.php` — `backup()`, `restore()`, `delete()` against `/api/servers/{uuid}/backup[...]`.

**Model** — `app/Models/Backup.php`: `SoftDeletes`, `disk` is `ADAPTER_WINGS = 'agent'` or `ADAPTER_AWS_S3 = 's3'`, `is_locked`, `is_successful`, `completed_at`, `ignored_files` (array), `bytes`, `checksum`, `upload_id`.

**Frontend** — `resources/scripts/components/server/backups/`

- `BackupContainer.tsx` — paginated list, `getServerBackups` SWR (`resources/scripts/api/swr/getServerBackups.ts`), `backupCount` from `meta.backup_count`.
- `CreateBackupButton.tsx` — modal; `is_locked` switch rendered only when the user holds `backup.delete`.
- `BackupRow.tsx` / `BackupContextMenu.tsx` — download/restore/lock/delete actions. Restore sets `server.status = 'restoring_backup'` optimistically; `InstallListener.tsx` clears it on the `backup restore completed` websocket event.

## Patterns unique to this feature

- **Protocol string is `agent`, not `wings`**: `Backup::ADAPTER_WINGS = 'agent'`. The Reviactyl Agent daemon accepts `agent`/`s3` in both `postServerBackup` and the restore binding (`oneof=agent s3`); stock Pterodactyl Wings accepts `wings`/`s3`. Do not "fix" this back to `wings` — it is a deliberate fork divergence. `config/backups.php` uses `APP_BACKUP_DRIVER` (default `agent`).
- **Adapter value flows three ways**: create sends `adapter` from the configured default; restore sends `backup.disk`; the remote upload/status controllers derive from `BackupManager::adapter()` (the current default, not the row's disk).
- **Lock semantics**: `toggleLock`/`store` gate lock changes behind `backup.delete`, so a create-only user cannot fill a server with undeletable backups. `InitiateBackupService::handle` with `$override = true` purges the oldest *unlocked* backup when at limit. The chatbot `CreateBackupTool` applies the same delete-permission rule via `$context->can(...)`.
- **Restore state machine**: `restore` rejects when `server.status !== null` or the backup is failed/incomplete, sets `server.status = 'restoring_backup'` inside a transaction (rolled back if the daemon call throws), then asks the daemon. The daemon always reports back via `POST /backups/{uuid}/restore`, clearing the status on success *and* failure. The chatbot `RestoreBackupTool` mirrors all of this (including a try/catch that reverts the status on a rejected daemon call) and shares the `ResourceLimit::Backup` bucket via `ResourceLimit::Backup->hit($server)`.
- **Failure cleanup**: failed S3 uploads are aborted (`AbortMultipartUpload`) in `BackupStatusController::completeMultipartUpload`; a failed backup is force-unlocked so it can be deleted. `p:maintenance:prune-backups` (scheduled every 30 min when `backups.prune_age` is set) marks stale uncompleted backups failed — note it does NOT unlock them, but locked-failed backups are still deletable.
- **S3 part count is integer floor-division → ceil**: `for ($i = 0; $i < ($size / $maxPartSize); $i++)` yields exactly `ceil(size/maxPartSize)` parts, including 1 part for a backup smaller than `max_part_size`. This matches the daemon, which uploads `len(parts)` parts and gives the last one the remaining bytes. Do not "fix" this to a `+1` — you'd hand the daemon a zero-byte extra part and fail the `CompleteMultipartUpload`.

## Gotchas

- **Race on backup limit**: `BackupController::store` locks the server row (`Server::query()->whereKey(...)->lockForUpdate()->first()`) before calling `InitiateBackupService`, because the limit check reads a fresh count but the old `$server->backups()->lockForUpdate()` locked the *backups* relation (no rows on a fresh server) and serialized nothing. Keep the server-row lock if you touch this path.
- **S3 prefix is not applied to raw client keys**: `DownloadLinkService`, `DeleteBackupService`, `BackupRemoteUploadController` and `BackupStatusController` build `Key`s as `{server.uuid}/{backup.uuid}.tar.gz` directly against the S3 client, bypassing the Flysystem `prefix`. A non-empty `backups.disks.s3.prefix` config value is therefore only honored by Flysystem-based calls, not the backup lifecycle. Upstream behaves the same — document it before "fixing" it.
- **`restore-started` is never logged**: `ServerDetailsController::resetState` (Agent reboot recovery) queries for `activity_logs.event = 'server:backup.restore-started'`, but nothing in the panel ever writes that event, so the "mark restore as failed in audit log" branch never fires. Servers still get unstuck by the unconditional `status = null` update; only the audit entry is missing. Adding a `server:backup.restore-started` activity log when the client restore begins would complete the recovery trail (and needs a lang key).
- **`server.backup.restore-failed` (dot) is a quirk, not a breakage**: the failure event written by `BackupStatusController::restore` uses a dot between `server` and `backup` while every sibling uses a colon. It still translates (`activity.server.backup.restore-failed`), because the frontend's `replace(':', '.')` is a no-op on it. Keep it consistent only if you also update the lang key lookup.
- **No `is_restoring` guard on `view`/`index`/`download`/`delete` beyond the middleware**: `validateCurrentState` blocks these while a restore is in flight, which is intentional (no deleting the backup mid-restore).
- **Chatbot bypass routes**: the assistant tools (`CreateBackupTool`, `RestoreBackupTool`, `DeleteBackupTool`, `ListBackupsTool`) are the only other way to touch backups. They share the same services and now the same permission rules and resource-limit bucket as the HTTP endpoints — keep them in parity when adding guards.
- **Tests are DB-free**: `tests/Feature/Services/Backups/*` mock `ConnectionInterface` (`transaction` runs the closure), the repositories, and the daemon repository; `Http`/Guzzle are never fired. `tests/TestCase` fails any test that issues a SQL query, so never persist models — use `forceFill` on plain `new Backup()` instances (the constructor `new Backup([...])` triggers a schema introspection query and is forbidden).
- **Wings download link**: the JWT download URL for the wings adapter includes a one-time `unique_id` claim that the daemon marks used; a second download of the same URL 404s. That is per-URL, not per-backup.
