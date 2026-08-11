# AI Orchestra — Multi-Agent Tool Router

Implemented. An evolution of the per-conversation
chatbot (`docs/chatbot/AGENTS.md`) from a single-model chain into a two-tier
**router → sub-agent** system. The router decides which specialized sub-agent(s) own a
user request; each sub-agent has only the tools relevant to its domain.

Read `docs/chatbot/AGENTS.md` first — this design extends it, and every rule that is
reused is linked, not restated.

## Why, in one paragraph

Today a single model gets one giant flat tool list and one all-purpose system prompt every
turn (`ChatbotService::run()`, `chat/AGENTS.md:78`). That has three costs:

1. **Context bloat** — every turn sends definitions for ~50 tools, most irrelevant to the
   request, and a system prompt that must cater to all of them.
2. **Prompt-injection blast radius** — content read from the server (`read_file`, file
   listings, subuser names) reaches a model that simultaneously holds the destructive
   tools (`chat/AGENTS.md:118`). Narrow agents shrink the blast radius structurally.
3. **Cross-domain tasks** are hard — "install this mod, put it in config, restart and watch
   the log" forces one model into a long, brittle single loop.

Orchestration splits the difference: a cheap **router** composes answers and fans out to
narrow **sub-agents**, each with a small tool list it is already good at.

## Core model

```
   user turn
      │
      ▼
 ┌───────────────┐  decomposes request → picks sub-agents  ┌───────────────┐
 │   ROUTER      │─────────────────────────────▶           │               │
 │ (supervisor)  │  may ask clarifying questions           │  FILES AGENT  │  list/read/write/
 │  small tools  │                                         │  only file    │  compress/rename/
 │  cheap model  │        ┌────────────────────┐           │  tools        │  copy/delete
 └───────────────┘  ─────▶│                    │           └───────────────┘
      │   │        result │   SERVER AGENT     │          ┌───────────────┐
      │   └──────────────▶│  server/backups/   │          │   MODS AGENT  │
      │                   │  databases/        │          │  plugin+mods  │
      │  composes final   │  schedules         │          └───────────────┘
      ▼   answer          └───────────┬────────┘          ┌───────────────┐
 user sees                            └──────────────────▶│  POWER AGENT  │
                                                         │  power/console│
                                                         └───────────────┘
```

Additional optional tiers (not in v1): a **guardrail agent** standing in front of every
destructive tool, independent of `require_confirmation`; a **multi-model policy** that swaps
the router to a cheap model and sub-agents to a reasoning model on demand.

## Provenance over the flat design

Everything below either already exists or is a strict reuse. Map:

| Concern | Flat today | Orchestra |
|---|---|---|
| Loop | `ChatbotService::run()` (`chat/AGENTS.md:78`) | same loop reused for router *and* each sub-agent |
| Permissions | `ToolRegistry::availableFor()` → `ToolContext::can()/canAll()` (`chat/AGENTS.md:7, 34`) | unchanged; reused verbatim |
| Tool result framing | `renderForProvider()` / `digestToolResult()` (`chat/AGENTS.md:92`) | unchanged at the sub-agent level |
| Confirmation | `resolveConfirmation()` + `status=awaiting_confirmation` (`chat/AGENTS.md:79`) | extended — the router must resolve sub-agent chains too (`nested confirm`, below) |
| Streaming | `run($emit)` + `StreamAccumulator` (`chat/AGENTS.md:80–82`) | reused per agent; router composes sub-agent transcripts |
| Compaction | `ChatbotSettings` + `summarizeDropped()` (`chat/AGENTS.md:93`) | unchanged at conversation level; per-agent internal history relies on tool digest only |
| Turn serialization | `withTurnLock()` (`chat/AGENTS.md:98`) | unchanged |
| Token windowing | `selectHistory()` (`chat/AGENTS.md:91`) | unchanged at conversation level |

### Plumbing that must change (not just reuse)

Two existing pieces are single-model-shaped and need surgery before orchestration works:

- **`OpenAiClient` hardcodes one model.** `payload()` (`OpenAiClient.php:69-84`) reads
  `$this->settings->model()` for every call, and `chat()`/`stream()` pass no model down. A
  per-agent model override means threading a `?string $model` (and per-role temperature, if
  desired) through `payload()`, `chat()`, `stream()`. `ChatbotSettings::keys()` has no
  `router_model` or per-agent keys yet — new `panel:chatbot:*` keys are required.
- **`SystemPromptBuilder` is one-assistant-shaped.** `build()` (`SystemPromptBuilder.php:17`)
  receives the full available tool set and emits "You are the server assistant… use tools to
  find things out". The **router** must instead get a prompt describing the delegation model,
  the agent names, and its read-only role (its only tool is `delegate()`); the **sub-agents**
  need the existing safety rules (lines 51-55) but with their narrow capabilities. The doc
  should specify a `buildForRouter()`/`buildForAgent()` split that reuses the shared safety
  block verbatim.

## Implementation status (audit 2026-08-11)

The design below is implemented as described, with these deltas and fixes:

- **Per-agent model override is plumbed but unused.** `ChatbotAgent::model()` defaults
  to null and every registered agent returns null, so every sub-agent call falls back to
  the panel model. `OpenAiClient::chat()/stream()` accept a `?string $model`; an agent
  override needs no service changes.
- **Agent scoping is enforced at run time, not only in the tool definitions.**
  `RoutingService::runAgent()` rejects any tool call whose name is not in the agent's own
  tool set with a scope-rejection tool result, so a hallucinated or injected out-of-scope
  call (e.g. `power_action` while running as the Files agent) never reaches the executor
  and is never projected for approval. Every announced call id is still answered so the
  provider's next request is valid.
- **Sub-agent provider failures are localised.** A `ChatbotException` from the provider
  inside a sub-agent run marks that run `failed` and digests the error back to the router;
  it does not fail the router's whole turn.
- **Agent progress chips now emit.** `RoutingService::executeDelegate()` emits `agent`
  stream events (running → complete/failed/waiting), which the client renders as chips on
  the router's message. Without this the whole frontend contract (`agent` handler,
  `applyAgentRun`, `AgentProgressChips`) was dead code.
- **Nested delegation (`parent_run_id`) is not used in v1** — the migration reserves the
  column and the depth cap, but `delegate()` only ever runs one agent per call.

## Sub-agents as a declared concept

Each sub-agent is a class in `app/Services/Chatbot/Agents/` (`AgentRegistry` instantiate
from `config/chatbot.php` → `agents`). Each declares:

- **`id()`** — stable string, e.g. `files`, `server`, `power`, `mods`.
- **`name()` / `systemDirective()`** — the narrow 3–6 sentence role prompt fragment.
- **`toolGroups()`** — ordered list of `ChatbotToolGroup` values that narrow to its toolkit.
- **`model()`?** — optional per-agent model override (defaults to panel model).
- **`can(RoutingContext)`** — true only when the requesting user passes `Gate` checks for
  *at least one* tool in its groups; an agent offering nothing to this user is not routable
  to them (`chat/AGENTS.md:7, 34`).

The router's tool definitions are **not** the server-tool list. The router's single custom
tool is `delegate(request, to_agent_ids, context_budget)`. It never holds a handle to any
panel tool, keeping the router incapable of side effects itself — it composes only.

### Agent set vs. today's real groups

**Ground truth first:** `ChatbotToolGroup` (`app/Enum/ChatbotToolGroup.php`) has exactly
**10** cases: `server, power, console, files, subusers, startup, plugins, mods, web, admin`.
Tools are grouped by what their `group()` returns. The **backup, database and schedule tools
— and `rename_server` — all return `ChatbotToolGroup::Server`** (verified in `Backups/*`,
`Databases/*`, `Schedules/*`, `Server/RenameServerTool.php`). There is **no** `backups`,
`databases` or `schedules` group today, so those capabilities cannot be scoped to their own
agent without first splitting the `server` group in the enum.

Recommended v1 agent set:

| Agent | Groups | Covers |
|---|---|---|
| `files` | `files` | read/write/compress/rename/copy/delete/folders |
| `server` | `server` | status, resources, history, activity, logs, backups, databases, schedules, rename — all one reachable set |
| `power` | `power`, `console` | start/stop/restart/kill, console commands |
| `startup` | `startup` | startup vars & parts |
| `mods` | `plugins`, `mods` | plugin + mod lifecycle |
| `subusers` | `subusers` | accounts + permissions |
| `web` | `web` | fetch_url (SSRF-guarded public fetches) — off by default like the group |

All seven are registered in `config/chatbot.php`. Note that `server` stays a fat agent today
because the `server` group is already fat. A follow-up (enum split into e.g. `server`,
`backups`, `databases`, `schedules`) is what would let each of those run as a dedicated
narrow agent. Group toggles keep working as-is: an agent with a disabled group simply
reduces its routable toolset (`chat/AGENTS.md:114`), and `AgentRegistry::availableFor()`
drops an agent whose every group is disabled.

## New storage

Two additions so that sub-agent exchanges are replayable and auditable without polluting the
conversation transcript.

**`chatbot_agents`** (optional if the agent set is hard-coded; add when admins should define
custom agents):

| column | purpose |
|---|---|
| `id` | pk |
| `key` | stable string id |
| `name` | display name |
| `system_directive` | role prompt |
| `tool_groups` | json array |
| `model` nullable | per-agent model override |

**`chatbot_agent_runs`** — one row per router→delegate fan-out, capturing the inner loop as
opaque rows the provider can replay (mirrors `chatbot_messages` for the sub-agent's private
turn):

| column | purpose |
|---|---|
| `id` | pk |
| `conversation_id` | FK → `chatbot_conversations` |
| `parent_run_id` nullable | nested delegation |
| `agent_key` | which agent |
| `request` text | the routed instruction |
| `transcript` json | the sub-agent's provider-shaped exchange (user+assistant+tool) |
| `result` text | composer-ready outcome digest |
| `status` | `running` / `complete` / `denied` / `failed` / `awaiting_confirmation` |
| `created_at` / `updated_at` | |

The router sees a sub-agent through its **result digest**, not its raw transcript, before
deciding on the next fan-out or composing the final answer.

## Routing algorithm

`delegate()` runs as the router's only tool call, so it inherits the approval/streaming
machinery for free. `RoutingService::run()` (implemented):

```
1. Router loop (MAX_ROUTER_ITERATIONS = 3) classifies each response:
   - no tool call → persist its text, stop;
   - answer_directly() → the flat single-model loop answers (nothing of the router's
     turn is persisted, the placeholder row is deleted);
   - delegate() calls → up to MAX_DELEGATES_PER_TURN run, one per call, each one a
     sub-agent run.
2. For each delegate call:
     a. resolve the agent through AgentRegistry::resolveFor (context-scoped)
     b. create a chatbot_agent_runs row and run the sub-agent loop with its OWN
        definitions + directive + the routed request; emit agent progress chips
     c. on destructive + require_confirmation → run status awaiting_confirmation,
        the sub-agent's calls are projected onto the router's message as the pending
        confirmation, STOP (remaining delegates in the batch are answered with a
        skipped digest)
     d. digest the run (result truncated to 2000 chars), hand the digest to the router
3. Next router iteration composes from the digests.
```

A run paused for approval resumes in `resumeRun()`: `resolveConfirmation` writes the
decision's tool results, they are appended to the run's transcript, and the sub-agent loop
re-enters with its own history — the router does not compose on this path, the sub-agent's
final answer is the visible one. A second destructive proposal pauses the run again.

Nested delegations (agent → agent) are **not used in v1**: `parent_run_id` is reserved but
`delegate()` honours only the first agent id in a call. The router loop budget
(`MAX_ROUTER_ITERATIONS`) and each sub-agent's own `max_iterations` bound the whole turn.

## Security model (unchanged guarantees, new leverage)

- **Every tool call still re-checks `ServerPolicy` immediately before execution** — same
  `ToolExecutor` + `ToolContext` path (`chat/AGENTS.md:7`). Orchestration adds no privilege.
- **Agent scoping is defense-in-depth, not the permission gate**: a `files`-only agent cannot
  be given `power` tools by prompt injection because it simply never holds their definitions.
  This is the headline win over the flat design (`chat/AGENTS.md:118`).
- **...and it is enforced at run time too.** `runAgent()` re-checks every announced call
  against the agent's own tool set before the executor or an approval prompt ever sees it;
  an out-of-scope call is answered with a scope-rejection tool result. Scoping in the
  definitions alone would leave a hallucinated out-of-scope name reachable through the
  executor (`ToolExecutor::execute()` resolves against the full user tool set).
- **The router is read-only by construction** — its only tool is `delegate()`. It cannot
  perform a side effect itself.
- **Delegation is still user-scoped.** `RoutingContext` carries the same (server, user) pair;
  an injected sub-agent request is framed as untrusted data exactly like any tool result.
- **Nested confirms keep the double-submit defense.** A nested run claims its status with the
  same conditional `UPDATE … WHERE status = 'awaiting_confirmation'` rule
  (`chat/AGENTS.md:98`).

## Known trade-offs / current state of review

1. **Cost & latency**: a fan-out costs several model calls (1 router + N agents + 1 composer).
   In practice the router classifies most requests to `answer_directly()` (the flat loop), so
   orchestration overhead only applies to genuinely multi-step requests. Sub-agent digests are
   truncated to 2000 chars before the router sees them; raw transcripts stay in the run row.
2. **Error localisation**: implemented — a failed sub-agent fails *that run* (status `failed`,
   error digest) and the router's turn continues.
3. **Flat-loop fallback**: implemented — `answer_directly()` routes simple requests to the flat
   loop, and `panel:chatbot:orchestration` (default off) disables orchestration entirely.
4. **Confirmation UI**: implemented for one level of nesting — a paused sub-agent's calls are
   projected onto the router's message and resolved by the existing `confirm` endpoints;
   `resumeRun()` re-enters the sub-agent loop and can pause again. Chains of independent
   sub-agent pauses are serialised (a paused batch stops further fan-out).
5. **Streaming UX**: implemented — sub-agents emit `agent` progress chips (name + status +
   one-line summary) on the router's message; the sub-agent's own tool calls and transcript
   stay internal.
6. **Which agent writes the final visible answer**: the **router** owns the visible answer
   except on the resume path, where the sub-agent's final answer is the visible one. Tool
   messages are stripped from API responses and agent runs are not replayed as history.
7. **Budget accounting**: the router loop is capped (`MAX_ROUTER_ITERATIONS` = 3, 3 delegates
   per batch) ahead of the shared `max_iterations`; each sub-agent consumes its own
   `max_iterations` inside a single run. Per-turn model-call accounting beyond this is not
   implemented.

## Open questions (resolved / still open)

- **Agents are a hard-coded register** in `config/chatbot.php` → `agents`, instantiated by
  `AgentRegistry`. No `chatbot_agents` table (admin-definable agents remain future work).
- **The `server` group is still fat** — backups/databases/schedules live under
  `ChatbotToolGroup::Server`, so `ServerAgent` holds the whole set. Splitting the enum into
  separate groups is still the prerequisite for dedicated narrow agents.
- **Sub-agents share the conversation window**; the router selects history once per turn and
  sub-agent runs carry only their own request + tool exchange, so no per-agent history limit
  has been needed.
- **Admin review sees the router's visible answer + activity log** (`server:chatbot.tool`);
  `chatbot_agent_runs` rows are queryable for replay but are not exposed in any admin UI.

## Rollout plan (implemented)

1. `App\Services\Chatbot\Agents\*` register + `AgentRegistry` (context-scoped
   `availableFor()` / `resolveFor()`, memoized per server+user) — done.
2. `chatbot_agent_runs` migration + `delegate()` router tool — done.
3. `RoutingService` reusing the shared `ManagesChatbotTurns` machinery; router and per-agent
   system prompts; flat loop kept as the `answer_directly` path and the orchestration-off
   path — done.
4. Nested confirmation + streaming progress chips in the chat client (`agent` stream events,
   `AgentProgressChips`, `applyAgentRun` in `thread.ts`) — done.
5. Admin tab: `panel:chatbot:orchestration` toggle in `app/Filament/Pages/Settings.php` →
   AI Chatbot, plus the `web` tool group and `WebAgent`. Per-agent model overrides are
   plumbed but not exposed in the admin UI (v1 ships none).
6. Tests: `RoutingServiceRunAgentTest` (unit, real registered tools + mocked provider),
   `RoutingServiceDelegateDefinitionTest`, `SystemPromptBuilderRouterTest`, `AgentRegistryTest`
   (feature), `RegisteredAgentsTest` (feature). No real DB anywhere.
