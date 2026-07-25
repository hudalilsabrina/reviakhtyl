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
- No nested splits; min 1% CPU / 8MB RAM / 8MB disk (child AND parent must retain)
- Parent must be stopped/installed/unsuspended; owner-only (`can('*', $server)`)
- `split_limit` admin-set via EditServer form, default 0
- Client API: `GET/POST /servers/{server}/splits`, `POST /servers/{server}/splits/{child}/merge`; `{child}` custom-bound in SubstituteClientBindings

### Key bug fixes
1. **500 error (a2bff11)**: Wings fetches config from panel during `create()`; inside outer tx row uncommitted → panel 404 → wings aborted. Fix: create child OUTSIDE transfer transaction, then transfer parent resources in tx, deleting child on failure.

2. **Node transfer conflicts (1531dee)**: Transfer blocked if `isSplitChild()` or `children()->exists()`; merge rejects if nodes differ; admin UI button disabled.

3. **Egg variable validation (25c49d5)**: Validates at admin level via `VariableValidatorService` (required, regex, etc.) before creating child.

4. **Critical edge cases (ac29f11)**:
   - Parent deletion with children blocked (prevents orphaning + resource loss)
   - Child manual deletion returns resources to parent (locks parent, adds back via BuildModificationService)
   - Resource exhaustion prevented (enforces 1% CPU / 8MB RAM / 8MB disk minimum reserve on parent)

### Create modal mirrors admin creation
- Fields: name (prefilled `<parent> (split)`), cpu/memory/disk, startup (editable, default parent), docker image (Select from egg docker_images), egg service variables (parent values; user_editable=false disabled)
- Allocation auto-assigned (claimAllocation, lockForUpdate)
- Backend validates egg variables at admin level before creating child
- API index returns `defaults` object: name/startup/image/docker_images/variables[] (env_variable,name,description,value,editable)

## Current Status
**PRODUCTION-READY** - Comprehensive edge case analysis complete (2026-07-25 12:05 UTC)

### Commits (18 total)
- a1a5dcd..9874120: base feature, env copy fix
- 0b677ff: declutter stats (single row)
- a2bff11: fix split 500 (child created outside tx)
- 409bfb9: create form → modal
- 50fb686: mirror admin creation fields (startup/image/variables)
- 7171172: untracked .opencode/ from git
- 1531dee: block node transfer for split parents/children
- 25c49d5: validate egg variables in split
- ac29f11: fix critical edge cases (orphaning, exhaustion, manual delete)

### Edge Case Analysis Results
**Critical issues fixed** (ac29f11):
- ✅ Parent deletion orphaning children (now blocked)
- ✅ Child manual deletion losing resources (now returns to parent)
- ✅ Resource exhaustion (enforces minimum reserve on parent)

**Already handled**:
- ✅ Concurrent operations (lockForUpdate + TX rollback)
- ✅ Node transfer conflicts (multiple protections)
- ✅ Egg variable validation (admin level)
- ✅ Wings offline during split (rollback logic)
- ✅ Allocation management (FK SET NULL)
- ✅ Security (owner-only, CSRF protected)
- ✅ Database integrity (proper FK constraints)

**Minor issues (low priority)**:
- No pagination for children list (acceptable at typical split_limit values)
- Frontend validation weaker than backend (UX: shows backend error)
- No explicit parent_id index (FK may create implicit; add if performance issue)
- No activity log for manual child delete (audit gap)

**By design**:
- Parent suspension doesn't cascade to children (independent servers)
- Merge requires child offline (prevents merging active containers)
- Split limit can be reduced below current child count (admin action)

All checks green: pint, phpstan, tsc, eslint, build.

## Key Files
- `app/Services/Servers/ServerSplitService.php` — split()/merge()/claimAllocation(), env validation, minimum reserve enforcement
- `app/Services/Servers/ServerDeletionService.php` — blocks parent delete w/ children; returns resources on child delete
- `app/Http/Controllers/Api/Client/Servers/SplitController.php` — index/store
- `app/Http/Requests/Api/Client/Servers/SplitServerRequest.php` — validation
- `resources/scripts/components/server/splitter/SplitterContainer.tsx` — UI
- `resources/scripts/api/server/splits/getSplits.ts` — SplitsState
- `app/Models/Server.php` — parent()/children()/isSplitChild()/canSplit()/validateTransferState()
- `app/Filament/Resources/Servers/Pages/EditServer.php` — split_limit field, canTransfer()
- `app/Exceptions/Http/Server/ServerStateConflictException.php` — split transfer block messages
- `database/migrations/2026_07_23_000000_add_server_splitting_to_servers_table.php` — parent_id (unsignedInteger, nullOnDelete), split_limit

## Test server
uuid e077c1e4-5be0-4179-9577-04966f21204f, split_limit=2, node singapore.arinata.my.id:8443 (wings reachable, 93 free allocations)

## Pending Tasks
None — comprehensive review and edge case analysis complete. Ready for production use.

## Recommendations (Deferred)
**Medium priority**:
1. Add frontend validation for memory/disk minimums + parent reserve (better UX)
2. Add activity log event for manual child deletion (audit trail)
3. Add explicit parent_id index if performance degrades

**Low priority**:
4. Add pagination for children list if split_limit commonly >50
5. Add specific rate limiting to split endpoints if abuse occurs
6. Validate split_limit reduction doesn't go below current children count

## Notes
- Merge blocked while child status != offline/crashed (isRunning); after split child is 'offline' once installed — brief window during install blocks merge, wait ~15s
- .opencode/ artifacts kept on disk, gitignored
- Current time: 2026-07-25 12:05 UTC
- Full edge case analysis report in /tmp/edge_case_final_report.md
