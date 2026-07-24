# Mission: Server Splitter

## M1: Backend — schema, model, service | status: completed
### T1.1: Migration + Model | agent:Worker
- [x] S1.1.1: Migration add `parent_id` + `split_limit` to servers | size:S
- [x] S1.1.2: Server model: fillable/casts/rules, relations parent/children, isSplitChild/canSplit | size:S

### T1.2: Service | agent:Worker | depends:T1.1
- [x] S1.2.1: ServerSplitService::split | size:M
- [x] S1.2.2: ServerSplitService::merge | size:M

### T1.3: Client API | agent:Worker | depends:T1.2
- [x] S1.3.1: SplitController + SplitMergeController + SplitServerRequest | size:M
- [x] S1.3.2: Routes /splits, /splits/{child}/merge w/ child binder | size:S
- [x] S1.3.3: ServerSplitTransformer | size:S

## M2: Admin (Filament) | status: completed
### T2.1: ServerResource | agent:Worker
- [x] S2.1.1: split_limit numeric input | size:S
- [x] S2.1.2: parent link on child form + ChildrenRelationManager | size:S

## M3: Frontend (React) | status: completed
### T3.1: API layer + route | agent:Worker
- [x] S3.1.1: api/server/splits/{getSplits,createSplit,mergeSplit} | size:S
- [x] S3.1.2: SplitterContainer | size:M
- [x] S3.1.3: Route + nav item "Splitter" | size:S

## M4: Verification | status: completed
### T4.1: Reviewer full pass | agent:Reviewer | status: completed
- [x] S4.1.1: `pnpm tsc` clean | verified: exit 0
- [x] S4.1.2: `composer pint:check` clean | verified: {"result":"passed"}
- [x] S4.1.3: phpstan no errors | verified: [OK] No errors
- [x] S4.1.4: `pnpm run build` succeeds | verified: built in 19.53s, exit 0
- [x] S4.1.5: Migration SQL sane (`php artisan migrate --pretend`) | verified: adds parent_id nullable + split_limit default 0 + FK on delete set null + index
- [x] S4.1.6: Logic review | verified: a–k PASS; race-on-split-limit fixed (commit 7fd8f6e), nav-visibility item wont-fix (documented)
