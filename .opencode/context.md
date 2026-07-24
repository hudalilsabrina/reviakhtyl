# Project Context

## Environment
- Language: PHP 8.3 (Laravel 13), React 19 + TS frontend
- Build: `pnpm run build` | Test: none (no tests/ dir, no CI)
- Verify: `composer pint:check`, `./vendor/bin/phpstan analyse --no-progress`, `pnpm tsc`, `pnpm lint`, `pnpm run build`
- Production: artisan migrate needs `--force`
- DB: `servers.id` is `int(10) unsigned` — FKs must be unsignedInteger
- Log file must be owned by www-data (was root-owned, errors silent — fixed)

## Feature: Server Splitter
Split parent server's CPU/RAM/disk into child servers (same node/egg/owner), merge returns resources + deletes child.

### Design decisions
- No nested splits; min 1% CPU / 8MB RAM / 8MB disk
- Parent must be stopped/installed/unsuspended; owner-only (`can('*', $server)`)
- `split_limit` admin-set via EditServer form, default 0
- Client API: `GET/POST /servers/{server}/splits`, `POST /servers/{server}/splits/{child}/merge`; `{child}` custom-bound in SubstituteClientBindings

### Key bug fixed (root cause of 500)
Wings fetches new server's config from panel during `create()`; inside outer tx the row was uncommitted → panel 404 → wings aborted "resource does not exist on this instance". Fix: create child OUTSIDE the resource-transfer transaction, then transfer parent resources in tx, deleting child on failure. (ServerSplitService::split)

### Create modal mirrors admin creation
- Fields: name (prefilled `<parent> (split)`), cpu/memory/disk, startup (editable, default parent), docker image (Select from egg docker_images), egg service variables (parent values; user_editable=false disabled)
- Allocation auto-assigned (claimAllocation, lockForUpdate)
- Backend filters unknown env keys; image constrained to egg list
- API index returns `defaults` object: name/startup/image/docker_images/variables[] (env_variable,name,description,value,editable)

## Current Status
ALL COMPLETE. Commits on master:
- a1a5dcd..9874120: base feature, env copy fix
- 0b677ff: declutter stats (single row: remaining CPU/Mem/Disk + Children `used / limit`)
- a2bff11: fix split 500 (child created outside tx) — verified end-to-end via tinker (split+merge)
- 409bfb9: create form → modal (Modal.tsx, size md, Cancel/Create)
- 50fb686: mirror admin creation fields (startup/image/variables) — verified (custom.jar override applied, HACKED key ignored, merge clean)
- 7171172: untracked .opencode/ from git, added to .gitignore

All checks green: pint, phpstan, tsc, eslint, build.

## Key Files
- `app/Services/Servers/ServerSplitService.php` — split()/merge()/claimAllocation(), env overrides + image/startup validation
- `app/Http/Controllers/Api/Client/Servers/SplitController.php` — index (split_limit/can_split/reason/remaining/defaults/children), store
- `app/Http/Requests/Api/Client/Servers/SplitServerRequest.php` — validates name/cpu/memory/disk/startup/image/environment
- `resources/scripts/components/server/splitter/SplitterContainer.tsx` — UI: stats card, Create button, children list, create Modal, merge ConfirmationModal
- `resources/scripts/api/server/splits/getSplits.ts` — SplitsState w/ SplitDefaults/SplitVariable
- `resources/scripts/api/server/splits/createSplit.ts` — payload + startup/image/environment
- `resources/lang/en/server/splitter.php` — keys incl. startup-label, image-label, cancel, unavailable-child/limit/max
- `app/Models/Server.php` — parent(), children(), isSplitChild(), canSplit(); variables() relation joins server_value
- `app/Filament/Resources/Servers/Pages/EditServer.php` — handleRecordUpdate saves split_limit directly

## Test server
uuid e077c1e4-5be0-4179-9577-04966f21204f, split_limit=2, node singapore.arinata.my.id:8443 (wings reachable, 93 free allocations)

## Pending Tasks
(none — awaiting user retest in UI)

## Notes
- Merge blocked while child status != offline/crashed (isRunning); after split child is 'offline' once installed — brief window during install blocks merge, wait ~15s
- .opencode/ artifacts kept on disk, gitignored
