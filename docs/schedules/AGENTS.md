# Schedules

Cron-driven task automation for game servers. A schedule holds an ordered list of tasks; when its cron expression fires, the panel queues the first task, and each task runs in sequence (with an optional per-task delay). Tasks send a power signal, send a console command, or start a backup.

## Execution model, in one paragraph

A Laravel scheduler entry (`p:schedule:process`, every minute, `withoutOverlapping`) finds due schedules whose server is installed (`servers.status IS NULL`), active, and not already processing, then hands each to `ProcessScheduleService::handle()`. That service marks the schedule `is_processing`, computes the next run, and dispatches a `RunTaskJob` for the first task (delayed by that task's `time_offset`). Each `RunTaskJob` performs its action against Agent and, on success, dispatches the next task in sequence. The final task — or any failure path — clears `is_processing` and stamps `last_run_at`. Manual execution (`POST /{schedule}/execute`) runs the same flow with `now=true`, which dispatches the first job immediately (no delay).

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/schedules`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/` | `ScheduleController::index` | All schedules with tasks |
| POST | `/` | `ScheduleController::store` | Create. Throttled: `ResourceLimit::Schedule` (2/min per server) |
| GET | `/{schedule}` | `ScheduleController::view` | 404 if not this server's |
| POST | `/{schedule}` | `ScheduleController::update` | Resets `is_processing` when toggling `is_active` |
| POST | `/{schedule}/execute` | `ScheduleController::execute` | Immediate run. Checks per-task action permissions |
| DELETE | `/{schedule}` | `ScheduleController::delete` | Cascades to tasks (FK) |
| POST | `/{schedule}/tasks` | `ScheduleTaskController::store` | Enforces `per_schedule_task_limit` (default 10) |
| POST | `/{schedule}/tasks/{task}` | `ScheduleTaskController::update` | Re-sequences sibling tasks |
| DELETE | `/{schedule}/tasks/{task}` | `ScheduleTaskController::delete` | Decrements following sequences |

All schedule/task requests inherit `ViewScheduleRequest`, which requires `schedule.read` and 404s cross-server/task mismatches. Task create/update additionally require the permission for the specific task action (`StoreTaskRequest::authorize()` → `Task::permissionForAction()`).

**Models**

- `Schedule` — five `cron_*` string columns (`minute hour day_of_month month day_of_week`), `is_active`, `is_processing`, `only_when_online`, `last_run_at`, `next_run_at`. `getNextRunDate()` recombines the columns into a standard 5-field cron expression and evaluates it with `dragonmantank/cron-expression`.
- `Task` — `schedule_id`, `sequence_id` (1-based, contiguous), `action` (`power|command|backup`), `payload`, `time_offset` (0–900s), `is_queued`, `continue_on_failure`. `permissionForAction()` maps each action (and power payload) to the subuser permission the operator must hold. `server` is a `belongsToThrough` on `Schedule::server`.
- `TaskLog` / `tasks_log` table — **dead code**. The model exists but nothing reads or writes it; task history is not recorded.

**Backend services**

- `app/Services/Schedules/ProcessScheduleService.php` — `handle(Schedule, bool $now = false)`. Picks the first task, marks the schedule processing inside a transaction, checks `only_when_online` against Agent's live state (`offline`/`stopping` → silently fail), then dispatches the first `RunTaskJob`. For `now=true` it uses `dispatchNow` and re-throws the exception after `failed()`.
- `app/Jobs/Schedule/RunTaskJob.php` — the per-task worker. Skips work if the schedule was deactivated (unless `manualRun`), aborts if the server got suspended/reinstalling (`status` non-null), runs the action against Agent, then queues the next task. `continue_on_failure` only swallows `DaemonConnectionException`; any other exception aborts the chain.
- `app/Console/Commands/Schedule/ProcessRunnableCommand.php` — `p:schedule:process`. `whereRaw('next_run_at <= NOW()')`, `is_active`, `is_processing = false`, and `whereRelation('server', whereNull('status'))`. Logs per-schedule errors and keeps going.

**Frontend** — `resources/scripts/components/server/schedules/`

- `ScheduleContainer.tsx` (list) → `ScheduleEditContainer.tsx` (detail, route `schedules/:id/*`).
- `ScheduleRow.tsx` / `ScheduleCronRow.tsx` — list row + cron column breakdown. All labels via `server/schedules` i18n namespace.
- `EditScheduleModal.tsx` — create/edit cron, `onlyWhenOnline`, `isActive`. Defaults `onlyWhenOnline` to **true** on new schedules (differs from the backend model default of false).
- `TaskDetailsModal.tsx` — create/edit a task; yup schema messages come from `server/schedules` keys.
- `NewTaskButton.tsx`, `RunScheduleButton.tsx`, `DeleteScheduleButton.tsx` — row actions.
- API helpers: `resources/scripts/api/server/schedules/`. All take the server's `uuid`.

**Chatbot tools** — `app/Services/Chatbot/Tools/Schedules/`

- `CreateScheduleTool` — validates the cron string, stores it split into the five `cron_*` columns, enforces the per-schedule task limit, and requires the permission each task action needs. Sequence defaults are 1-based (`$index + 1`).
- `ListSchedulesTool` — returns schedules with `cron` in standard `minute hour day month dow` order (matching `Schedule::getNextRunDate`).
- `ExecuteScheduleTool` — immediate run; requires `schedule.update` **plus** every task action permission (mirrors `ScheduleController::authorizeTasks`).
- `DeleteScheduleTool` — deletes the schedule.

## Patterns unique to this feature

- **Sequence renumbering**: creating a task at an earlier position increments all `sequence_id >= requested`; updating shifts the range between old and new positions; deleting decrements tasks after the removed one. All inside a transaction. A task can never have `sequence_id < 1` (request clamps, and the model rule is `min:1`).
- **`is_processing` is the double-execution guard**: the `ProcessRunnableCommand` query only picks up schedules with `is_processing = false`, and `ProcessScheduleService` sets it true before dispatching. Toggling `is_active` on an edit resets a stuck `is_processing` (see pterodactyl/panel#2425). There is no DB `lockForUpdate` on this flag — two overlapping command runs (e.g. two queue workers hitting the same due schedule) can both pass the `is_processing = false` predicate. `p:schedule:process` is registered `withoutOverlapping`, so in practice only one process picks schedules up.
- **Task action permissions are per-action, not just `schedule.update`**: creating a task, updating it, or executing a schedule re-checks `Task::permissionForAction()` against the requesting user. A subuser with `schedule.update` but no `control.console` cannot add or run a command task. The chatbot tools enforce the same mapping.
- **`only_when_online` re-checks live daemon state**: the daemon is polled via `DaemonServerRepository::getDetails()` at dispatch time; `offline`/`stopping` silently completes the schedule without running. A daemon connection failure also fails silently (job `failed()`), since the schedule itself is valid — the next cron fire retries.
- **`continue_on_failure` is daemon-only**: a `DaemonConnectionException` on a task with `continue_on_failure` swallows the error and proceeds to the next task. Any other failure (invalid action, backup service errors) always aborts the chain and clears `is_processing` via `RunTaskJob::failed()`.
- **Manual execution ignores `is_active`**: `RunTaskJob` only skips inactive schedules when `manualRun` is false. `only_when_online`, however, applies to manual runs too (the online check happens before dispatch regardless of `now`).

## Gotchas

- **Cron column order is `minute hour day_of_month month day_of_week`** — both in the DB schema and in the frontend. The `ListSchedulesTool` and `CreateScheduleTool` use this order; do not reorder the columns when touching cron handling.
- **`StoreScheduleRequest` must validate `month`**: it maps the five cron request fields to the `Schedule::getRules()` entries; if `month` is missing from the rules array, `ScheduleController::getNextRunAt()` receives `null` for month and throws a `TypeError` (not a `DisplayException` — `TypeError` is not an `Exception`), yielding a 500 with a stack trace instead of a clean 400.
- **`onlyWhenOnline` default mismatch**: the edit modal defaults new schedules to `onlyWhenOnline = true`; the backend model default is `false`. A schedule created through the UI is online-only unless the user toggles it; a schedule created via the API/chatbot defaults to offline-tolerant.
- **`RunTaskJob` retries**: the job declares no `$tries`/`$timeout`/`$retryUntil`. With `queue:work --tries=1` (the Docker default) a non-`DaemonConnectionException` failure marks the job failed once and calls `failed()`. Without `--tries=1`, Laravel's default `maxTries = 1` for `queue:work` still applies, so a failing task never re-runs the whole chain by itself.
- **No N+1 in the API**: `ScheduleController::index` uses `$server->schedules->loadMissing('tasks')`, and `Schedule`'s `protected $with = ['tasks']` means the transformer's task include is already loaded. `Task` touches its parent schedule on update so `last_run_at` reflects task changes.
- **`TaskLog` is unused**: `tasks_log` has no writers. Do not build new features on it without adding the persistence path first.
- **`is_queued` is informational**: it is set true when a job is dispatched and false when the job runs/fails, but the queue worker does not consult it. A crashed worker leaves `is_queued = true` rows behind; the `is_processing` flag on the schedule is what stops re-dispatch.
- **i18n**: all user-facing strings in `resources/lang/en/server/schedules.php`, loaded via the `server/schedules` namespace. Cron labels, task action names, power signals, and the schedule edit/task modals are all keyed there — add new locales at `resources/lang/<locale>/server/schedules.php`.

## Tests

Pest tests live in `tests/Feature/Services/Schedules/` (Mockery, in-memory models, no DB):

- `ProcessScheduleServiceTest` — dispatch/no-dispatch behavior, `only_when_online` handling, empty-schedule error, manual vs delayed dispatch.
- `ScheduleTaskTest` — `permissionForAction` mapping, `getNextRunDate` column ordering, the `month` validation rule.
- `ChatbotScheduleToolsTest` — chatbot tool permission gates (create/execute), task limit, cron string ordering.

Run: `vendor/bin/pest tests/Feature/Services/Schedules/`.
