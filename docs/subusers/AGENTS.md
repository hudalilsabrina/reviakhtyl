# Subusers

Per-server user accounts with scoped permissions. A subuser is a (server, user) pair; the
same user can be a subuser on many servers, with a different permission set per server.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/users`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/` | `SubuserController::index` | All subusers on this server |
| POST | `/` | `SubuserController::store` | Create (or reuse existing panel user). `ResourceLimit::Subuser` middleware |
| GET | `/{user}` | `SubuserController::view` | Single subuser |
| POST | `/{user}` | `SubuserController::update` | Replace permission set |
| DELETE | `/{user}` | `SubuserController::delete` | Remove subuser + revoke daemon JWT |

**Service** — `app/Services/Subusers/SubuserCreationService.php`
- `handle(Server, email, permissions)` — find-or-create panel user, guard owner/duplicate, insert
  subuser. Wrapped in a DB transaction; races against the `(user_id, server_id)` unique index
  are caught and reported as `ServerSubuserExistsException`.

**Security middleware**
- `AuthenticateServerAccess` — user must be owner, root admin, or a subuser of the server.
- `ResourceBelongsToServer` — `/{user}` route param: the user must be a subuser of *this*
  server (404 otherwise); the resolved `Subuser` is attached to the request as `subuser`.

**Authorization (`SubuserRequest::authorize`)**
- Owner/root admin: unconstrained.
- Subuser: can only assign permissions they already hold (`GetUserPermissionsService`),
  enforced via `validatePermissionsCanBeAssigned()` on POST. Never allowed to edit themselves.
- Request classes: `GetSubuserRequest` (`user.read`), `StoreSubuserRequest` (`user.create`),
  `UpdateSubuserRequest` (`user.update`), `DeleteSubuserRequest` (`user.delete`).

**Models**
- `Subuser` — `user_id`, `server_id`, `permissions` (JSON array). Unique `(user_id, server_id)`.
- `Permission` — static registry of every available permission + human descriptions
  (`Permission::permissions()`, `Permission::ACTION_*` constants). The model itself is legacy
  (the `permissions` table is empty after the 2020 merge); permission data lives on `Subuser`.

**Observer** — `app/Observers/SubuserObserver.php`
- `created`/`deleted` fire events + email notifications (`AddedToServer` / `RemovedFromServer`).

**Frontend** — `resources/scripts/components/server/users/`
- `UsersContainer.tsx` (route gated by `user.read`), `AddSubuserButton`, `EditSubuserModal`,
  `RemoveSubuserButton`, `PermissionRow`, `PermissionTitleBox` (group toggle with dependency hints).
- API helpers: `resources/scripts/api/server/users/{getServerSubusers,createOrUpdateSubuser,deleteSubuser}.ts`.

**Chatbot integration** — `app/Services/Chatbot/Agents/SubusersAgent.php` + tools
`{Create,UpdatePermissions,Delete,ListAccounts}SubuserTool.php`, scoped to the same permission
model (`user.create`/`user.update`/`user.delete`/`user.read`).

## Patterns unique to this feature

- **Permission escalation guard**: a subuser may only grant permissions they themselves hold.
  The check runs at `authorize()` (before validation) so it applies uniformly to create/update;
  owner and root admin bypass it. Combined with the self-edit block, no subuser can ever grant
  themselves or others more than they possess.
- **`getDefaultPermissions()`** intersects the requested permissions with the authoritative
  `Permission::permissions()` registry, then always merges in `websocket.connect`. Unknown or
  removed permission keys are silently dropped rather than 422'd.
- **Daemon JWT revocation** on update/delete: `DaemonRevocationRepository::deauthorize()` is
  called after the DB write; if the daemon is unreachable the change is logged and `revoked`
  is flagged `false` in the activity event (best-effort — the token dies when the daemon reboots).
- **Find-or-create user**: an email that already exists on the panel is reused; a fresh email
  provisions a new panel user (`root_admin: false`, name "Server Subuser", username derived from
  the email local-part + 3 random chars).

## Gotchas

- **Never remove the self-edit block**: `SubuserRequest::authorize()` returns `false` when the
  target user is the request user. Without it, a subuser with `user.update` could grant itself
  arbitrary permissions.
- **Permission check order matters**: `validatePermissionsCanBeAssigned` runs in `authorize()`,
  *before* `rules()` validation. It guards `is_array` so a malformed (non-array) `permissions`
  payload fails safely at validation instead of producing `array_diff` warnings.
- **`permissions` stored as JSON** — read via the `array` cast. `array_unique` keeps original
  keys (0, 2), so compare with `array_values` in tests/consumers if order-independent.
- **Race**: two concurrent `store()` calls for the same (user, server) are guarded by the
  unique index; the second gets `ServerSubuserExistsException`. The service check is a fast path,
  not the source of truth.
- **`{user}` route param is a User, not a Subuser** — `ResourceBelongsToServer` resolves the
  actual `Subuser` row and stashes it on the request, so controllers read
  `$request->attributes->get('subuser')`, never `$server->subusers` again.

## i18n

Exception messages in `resources/lang/en/exceptions.php` under `subusers.*`
(`user_is_owner`, `subuser_exists`). Notification copy in the `AddedToServer` /
`RemovedFromServer` notification classes.
