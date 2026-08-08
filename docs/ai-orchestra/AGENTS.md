# AI Orchestra — Multi-Agent Tool Router

Proposed design (planning, not yet implemented). An evolution of the per-conversation
chatbot (`docs/chatbot/AGENTS.md`) from a single-model chain into a two-tier
**router → sub-agent** system. The router decides which specialized sub-agent(s) own a
user request; each sub-agent has only the tools relevant to its domain.

Status: **DRAFT / NOT IMPLEMENTED**. This document is the design target. No code in the
repo depends on it yet. Read `docs/chatbot/AGENTS.md` first — this design anextension
of it, and every rule there that is reused is linked, not restated.

## Why, in one paragraph

Today a single model gets one giant flat tool list and one all-purpose system prompt every
turn (`ChatbotService::run()`, `chat/AGENTS.md:245`). That has three costs:

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
      │   │        result │  SERVER-OPS AGENT  │          ┌───────────────┐
      │   └──────────────▶│  power/console/    │          │   MODS AGENT  │
      │                   │  logs/resources    │          │  plugin+mods  │
      │  composes final   └───────────┬────────┘          └───────────────┘
      ▼   answer                      │                   ┌───────────────┐
 user sees                            └──────────────────▶│  LIFECYCLE     │
                                                        │  startup/sched │
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
| Permissions | `ToolRegistry::availableFor()` → `ToolContext::can()/canAll()` (`chat/AGENTS.md:46, 7`) | unchanged; reused verbatim |
| Tool result framing | `renderForProvider()` / `digestToolResult()` (`chat/AGENTS.md:92`) | unchanged at the sub-agent level |
| Confirmation | `resolveConfirmation()` + `status=awaiting_confirmation` (`chat/AGENTS.md:79`) | extended — the router must resolve sub-agent chains too (`nested confirm`, below) |
| Streaming | `run($emit)` + `StreamAccumulator` (`chat/AGENTS.md:80–82`) | reused per agent; router composes sub-agent transcripts |
| Compaction | `ChatbotSettings` + `summarizeDropped()` (`chat/AGENTS.md:93`) | unchanged at conversation level; per-agent internal history relies on tool digest only |
| Turn serialization | `withTurnLock()` (`chat/AGENTS.md:98`) | unchanged |
| Token windowing | `selectHistory()` (`chat/AGENTS.md:91`) | unchanged at conversation level |

## Sub-agents as a declared concept

Add `app/Services/Chatbot/Agents/` with one class per sub-agent. Each declares:

- **`id()`** — stable string, e.g. `files`, `server_ops`, `mods`, `lifecycle`.
- **`name()` / `systemDirective()`** — the narrow 3–6 sentence role prompt fragment.
- **`toolGroups()`** — ordered list of `ChatbotToolGroup` values that narrow to its toolkit.
- **`model()`?** — optional per-agent model override (defaults to panel model).
- **`can(RoutingContext)`** — true only when the requesting user passes `Gate` checks for
  *at least one* tool in its groups; an agent offering nothing to this user is not routable
  to them (`chat/AGENTS.md:46`).

The router's tool definitions are **not** the server-tool list. The router's single custom
tool is `delegate(request, to_agent_ids, context_budget)`. It never holds a handle to any
panel tool, keeping the router incapable of side effects itself — it composes only.

### Proposed v1 agent set (matches existing `config/chatbot.php` groups)

| Agent | Groups | Covers |
|---|---|---|
| `files` | `files` | read/write/compress/rename/copy/delete/folders |
| `server_ops` | `server`, `power`, `console`, `schedules*` | status, resources, history, activity, power, console, schedules |
| `startup` | `startup` | startup vars & parts, rename |
| `mods` | `plugins`, `mods` | plugin + mod lifecycle |
| `backups` | `backups` | list/create/restore/delete backup |
| `databases` | `databases` | list/create/delete database |
| `subusers` | `subusers` | accounts + permissions |

`*` schedules straddle power and lifecycle; decide ownership in review. Group toggles keep
working as-is: an agent with a disabled group simply reduces its routable toolset
(`chat/AGENTS.md:114`).

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
machinery for free. Proposed `RoutingService::run()`:

```
1. Router builds a plan (one model call): which agent(s) handle what, in what order.
2. Router asks any clarifying question it needs (returns, no delegation).
3. For each planned agent:
     a. guard/sub-agent readiness (injection-safe start? reject and say why if unsafe)
     b. call the sub-agent loop with its OWN definitions + directive + the routed request
     c. on destructive + require_confirmation → mark run awaiting_confirmation,
        surface a nested confirm prompt, STOP (do not fan out further)
     d. digest the run, hand the digest to the router
4. Router composes the final answer from the digests (one last model call).
```

Nested delegations (agent → agent) are allowed but bounded by a depth cap (default 2) and roll
up to the conversation's existing `max_iterations` budget so a router cannot spin forever
(`chat/AGENTS.md:100`).

## Security model (unchanged guarantees, new leverage)

- **Every tool call still re-checks `ServerPolicy` immediately before execution** — same
  `ToolExecutor` + `ToolContext` path (`chat/AGENTS.md:7`). Orchestration adds no privilege.
- **Agent scoping is defense-in-depth, not the permission gate**: a `files`-only agent cannot
  be given `power` tools by prompt injection because it simply never holds their definitions.
  This is the headline win over the flat design (`chat/AGENTS.md:118`).
- **The router is read-only by construction** — its only tool is `delegate()`. It cannot
  perform a side effect itself.
- **Delegation is still user-scoped.** `RoutingContext` carries the same (server, user) pair;
  an injected sub-agent request is framed as untrusted data exactly like any tool result.
- **Nested confirms keep the double-submit defense.** A nested run claims its status with the
  same conditional `UPDATE … WHERE status = 'awaiting_confirmation'` rule
  (`chat/AGENTS.md:98`).

## Known trade-offs / risks to resolve in review

1. **Cost & latency**: a fan-out costs several model calls (1 router + N agents + 1 composer).
   Mitigate: cheap router model, `history_limit` per agent run, and never resend a sub-agent's
   raw transcript to the router (digest only).
2. **Error localisation**: a failed sub-agent must fail *that run*, not the whole router turn.
   The router should see the failure as a digest and decide whether to retry, fall back to the
   flat loop, or apologize.
3. **Flat-loop fallback**: keep the existing single-model loop reachable (per-server toggle).
   Orchestration should be the default, not the only path — some requests are answered best by
   a single capable model.
4. **Confirmation UI**: the existing client resolves one pending assistant message
   (`confirm/stream`, `chat/AGENTS.md:110`). Nested delegation needs the pending node to carry
   its whole `agent_runs` chain so the confirm endpoint resolves up the stack atomically.
5. **Streaming UX**: the router builds the visible answer; sub-agent activity should stream as
   progress chips (agent name + one-line digest) rather than raw tool calls, so the panel can
   still show "Files agent is checking read permissions…".
6. **Which agent writes the final visible answer**: recommend the **router** owns the visible
   assistant message and sub-agent transcripts stay internal (`chat/AGENTS.md:88`) — tool
   messages are already stripped from API responses; agent runs should be too, unless admins
   enable audit.
7. **Budget accounting**: conversation `max_iterations` / `context_tokens` are shared; an
   agent-heavy turn must not starve the next user message. Put a per-turn model-call budget
   ahead of the shared cap.

## Open questions for the reviewer

- Should `chatbot_agents` be a DB table (admin-definable) or a hard-coded register in
  `config/chatbot.php` like tools? v1 recommends hard-coded, DB later.
- Are `schedules` better under `server_ops` or a `lifecycle` agent (startup + persistence +
  schedules)?
- Do sub-agents need their own per-agent `history_limit`, or is the shared conversation window
  sufficient give tool digests shrink old results?
- Should admin review see sub-agent transcripts, or only the router's visible answer +
  activity log (existing `server:chatbot.tool` entries, `chat/AGENTS.md:104`)?

## Rollout plan (if approved)

1. Introduce `App\Services\Chatbot\Agents\*` register + `RoutingContext` (pure) — no behavior.
2. Add `chatbot_agent_runs` migration; write the `delegate()` router tool behind a flag.
3. Implement `RoutingService` reusing `ChatbotService` turn helpers; add router system prompt;
   keep flat loop as fallback.
4. Surface nested confirmation + streaming progress chips in `ChatContainer.tsx`
   (`resources/scripts/components/server/chat/`).
5. Admin tab additions in `app/Filament/Pages/Settings.php` → AI Chatbot: enable orchestration,
   optional per-agent models, optional guardrail toggle.
6. Tests: `RoutingService` unit + Pest feature (mock provider), mirroring the chatbot suite's
   in-memory mocks (`AGENTS.md` — no real DB in tests).
