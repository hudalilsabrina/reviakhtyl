# Nests & Eggs

Nests group related Eggs (game server templates). An Egg defines the Docker image(s), startup command, config-file templates, install script, and the environment variables a server created from it will expose. This is the admin-side feature; the runtime consumption side (per-server startup command building) lives in `app/Services/Servers/` and is documented in `docs/modular-startup/AGENTS.md`.

## Entry points

**Application API** — `routes/api-application.php`, prefix `/api/application`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/nests` | `NestController::index` | Paginated nests |
| GET | `/nests/{nest}` | `NestController::view` | Single nest |
| GET | `/nests/{nest}/eggs` | `EggController::index` | Eggs in a nest |
| GET | `/nests/{nest}/eggs/{egg}` | `EggController::view` | Single egg |

All application API routes are read-only and require a `root_admin` account (see `AuthenticateApplicationUser`) plus an API key with the `nests`/`eggs` ACL resource (`AdminAcl::RESOURCE_NESTS`, `RESOURCE_EGGS`). There is no write/delete/create API for nests/eggs — administration is Filament-only.

**Admin (Filament)** — `app/Filament/Resources/Nests/`

- `NestResource.php` — nest CRUD (name, image, author). Table shows egg/server counts via `counts()`.
- `RelationManagers/EggsRelationManager.php` — egg list inside a nest edit page, with create (redirects to egg form pre-set `nest_id`) and per-egg export.
- `EggResource.php` — the big one: tabs for Configuration, Variables, Startup Parts, Install Script.
- `Pages/` — `CreateNest`, `EditNest`, `ListNests` (also hosts the **Egg import** action), and `Eggs/Pages/{CreateEgg,EditEgg,ListEggs}`.
- `ListEggs` is a landing shim that redirects to the first nest's edit page.

**Core services**

- `app/Services/Nests/` — `NestCreationService`, `NestUpdateService`, `NestDeletionService`.
- `app/Services/Eggs/` — `EggCreationService`, `EggUpdateService`, `EggDeletionService`, `EggParserService`, `EggConfigurationService`, `Scripts/InstallScriptService`.
- `app/Services/Eggs/Variables/` — `VariableCreationService`, `VariableUpdateService`.
- `app/Services/Eggs/Sharing/` — `EggExporterService`, `EggImporterService`, `EggUpdateImporterService`.

**Models**

- `Nest` — `uuid`, `author`, `name`, `description`, `image`. `eggs()` hasMany, `servers()` hasMany.
- `Egg` — `uuid`, `nest_id`, `author`, `name`, `docker_images` (assoc array name→URI), `startup`, `config_*` (JSON strings), `config_from`/`copy_script_from` (parent egg IDs, `SET NULL` FK), `script_*`, `file_denylist`, `features`, `image`. `inherit_*` accessors resolve parent values when `config_from` is set. `VERSIONS = ['RCYL_v26', 'PTDL_v2', 'PTDL_v1']`; `EXPORT_VERSION = 'RCYL_v26'`.
- `EggVariable` — `egg_id`, `name`, `description`, `env_variable`, `default_value`, `user_viewable`, `user_editable`, `rules` (pipe-separated Laravel validation rules). `server_value` is populated only when loaded through `Server::variables()`.
- `EggStartupPart` — modular startup fragments (see `docs/modular-startup/AGENTS.md`).
- `ServerVariable` — per-server override of an `EggVariable` value (`variable_value`).

## Deletion rules

Both `EggDeletionService` and `NestDeletionService` **refuse** deletion when servers reference the record, and `EggDeletionService` also refuses when other eggs copy config from the target egg (`config_from`), throwing `HasActiveServersException` / `HasChildrenException`. The Filament UI mirrors this:

- `EggResource` + `EggsRelationManager`: `DeleteAction`/`DeleteBulkAction` `before()` hooks check `servers()->count()` and `cancel()` with a `admin/eggs.notices.cannot_delete*` notification.
- `NestResource` + `EditNest`: same pattern via `admin/nests.notices.cannot_delete*`.

Why both layers: the DB-level FKs on `servers.nest_id`/`servers.egg_id` have **no `ON DELETE` action** (they were migrated from legacy `services`/`service_options` with a bare `references()`), so a raw SQL delete would either fail or orphan rows depending on the engine's FK enforcement. Eloquent's `delete()` also does not cascade. The service + UI guards are the only protection.

## Import / Export

**Export** — `EggExporterService::handle(int $eggId): string`
- Loads `scriptFrom`, `configFrom`, `variables`, `startupParts` (eager) and emits a JSON blob with `meta.version = Egg::EXPORT_VERSION`, the egg fields, and `variables`/`startup_parts` arrays with `id`, `egg_id`, timestamps stripped and `field_type = 'text'` merged in.
- `file_denylist` is filtered to drop empty values; `inherit_*` config is exported (so the export is self-contained even for child eggs).

**Import (new egg)** — `EggImporterService::handle(UploadedFile, int $nestId)`
- `EggParserService` decodes/validates the JSON (`meta.version` must be in `Egg::VERSIONS`), converts `PTDL_v1` single-image to the modern `docker_images` map.
- Runs inside a DB transaction. `forceCreate`s the egg (assigning a fresh `uuid`), then `forceCreate`s each variable/startup part with the new `egg_id`.
- `field_type` keys in the import are stripped by `EggVariableObserver` (`creating`/`updating`), which is registered in `EventServiceProvider`.

**Import (update existing)** — `EggUpdateImporterService::handle(Egg, UploadedFile)`
- `updateOrCreate`s variables keyed on `env_variable`; deletes egg variables not present in the import. Same for startup parts keyed on `name`.
- Note: the **update** importer is not wired to any Filament action yet — only the new-egg import is reachable from `ListNests`.

## Egg variable validation

- `env_variable` is restricted by `EggVariable::$validationRules` to `regex:/^[\w]{1,191}$/` and `notIn` the `RESERVED_ENV_NAMES` list (`SERVER_MEMORY,SERVER_IP,SERVER_PORT,ENV,HOME,USER,STARTUP,SERVER_UUID,UUID`).
- `rules` is a pipe-separated Laravel validation rule string (e.g. `required|string`, `regex:/.../`). On create/update, `ValidatesValidationRules::validateRules()` compiles the rules against a throwaway validator to reject unknown rules (→ `BadValidationRuleException`) and malformed regexes (→ the same exception, via the `\ErrorException` from `preg_match`). Both are `DisplayException`s rendered as form errors.
- `VariableUpdateService` additionally enforces per-egg uniqueness of `env_variable` (a `findCountWhere` excluding the current ID → `DisplayException`). This is app-level only; there is no DB unique index on `(egg_id, env_variable)`.
- The reserved-name and uniqueness error messages use `exceptions.nest.variables.*` keys in `resources/lang/en/exceptions.php`.

## Patterns unique to this feature

- **Disabled form fields still validate server-side**: `uuid`/`author` on the egg form are `disabled()` (non-dehydrated), so `CreateEgg` injects them in `mutateFormDataBeforeCreate` (`uuid` = `Ramsey\Uuid\Uuid::uuid4()`, `author` = `config('panel.service.author')`). Without this the egg model's `saving` validation fails with `uuid/author required`.
- **`config_from` is nest-scoped**: both `EggCreationService` and `EggUpdateService` verify the parent egg lives in the same nest (`findCountWhere` on `nest_id` + `id`), else `NoParentConfigurationFoundException`.
- **`copy_script_from` requires a non-copying egg in the same nest**: `InstallScriptService` delegates to `EggRepository::isCopyableScript()` (parent must have `copy_script_from` NULL). This service is currently not wired to any controller/page — egg scripts are edited directly in the Filament form instead.
- **`file_denylist` cannot be updated through `EggUpdateService`**: it's unset before the repository update (`TODO(dane)` in the source), though the Filament form does persist it via the model directly.
- **Child eggs inherit at read time**: `getInheritConfigFilesAttribute()` etc. lazily resolve `config_from`'s values. `EggConfigurationService` and the remote server-list controller eager-load `configFrom` to avoid an N+1 per server.
- **`docker_images` is an assoc array**: the `KeyValue` form field writes `name => URI`. The `docker_images.*` validation regex accepts `~`-prefixed names (image-pull-on-install convention). `SetDockerImageRequest`/`SettingsController` validate against `array_values($server->egg->docker_images)`.

## Gotchas

- **No DB-level delete cascade for servers** — always go through `EggDeletionService`/`NestDeletionService` (or the Filament guards). `egg_variables` and `egg_startup_parts` do cascade on egg delete; `egg_mount` cascades too. `servers` does not.
- **`EggVariable::egg()` is `hasOne`** (not `belongsTo`) — a quirk; `$variable->egg` is the owning egg only if loaded with the right foreign key.
- **`VariableUpdateService` gets `options` as an array of flags** (`['user_viewable', ...]`) from the legacy admin contract, while the Filament form submits booleans directly. The two call sites normalize differently — don't conflate them.
- **Rules written with `;;` separators**: `VariableUpdateService` explodes `;;` before validating (legacy admin UI format). The Filament form submits pipe-separated strings.
- **Importer never validates imported variables' `rules`**: `EggImporterService` `forceCreate`s variables verbatim. An egg JSON with garbage rules will import fine but 500 later when a client edits the variable. `EggUpdateImporterService` has the same gap.
- **`EggUpdateImporterService` is dead code**: no route or Filament action calls it. Before wiring it up, decide how to handle the `rules` validation and the `name`-keyed part merge.
- **`env_variable` uniqueness is app-level only**: a concurrent double-submit can insert duplicates (no DB unique index). Low risk in the single-admin Filament flow.
- **Nest author is immutable after create**: `NestUpdateService` drops `author` if present; the form disables the field.
- **`ListEggs` always redirects**: it's a shim; don't add table config to it expecting it to render.

## Tests

`tests/Feature/Services/Nests/` (Pest, no DB):
- `EnvironmentServiceTest` — STARTUP_PARTS assembly from enabled/default parts.
- `VariableUpdateServiceTest` — reserved names, uniqueness, rule validation, option normalization.
- `EggCreationServiceTest` / `EggUpdateServiceTest` — `config_from` nest scoping, `file_denylist` stripping.
- `EggDeletionServiceTest` / `NestDeletionServiceTest` — server/children guards.
- `EggExporterServiceTest` — export version, id/timestamp stripping, `field_type`.

Plus `tests/Unit/Services/Eggs/EggParserServiceTest.php` for the import parser.

## i18n

- Admin labels: `resources/lang/en/admin/nests.php`, `resources/lang/en/admin/eggs.php` (nest/egg forms, import action, delete-blocked notifications).
- Exception messages: `resources/lang/en/exceptions.php` under `nest.*` (incl. `nest.variables.*`). Note `VariableUpdateService` historically referenced `exceptions.service.variables.*`, which does not exist — fixed to `exceptions.nest.variables.*`.
