# AI Chatbot

A per-server assistant backed by any OpenAI-compatible chat completions API. The user talks to it on a server's chat page; it answers questions and performs actions by calling *tools*. It can never exceed the permissions of the user talking to it.

## The security model, in one paragraph

Every capability is a tool class declaring the subuser permissions it needs. A tool is offered to the model only if (a) an administrator enabled its group panel-wide and (b) the requesting user passes a `Gate` check for each of its permissions — the same `ServerPolicy` the HTTP API uses. The check is repeated immediately before execution, so narrowing a subuser's access mid-conversation takes effect at once. Tools that change state are marked destructive and, by default, are held for the user to approve before they run. The provider never receives credentials, and content read from the server is framed to the model as untrusted data.

## Entry points

**Client API** — `routes/api-client.php`, prefix `/api/client/servers/{server}/chat`

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | `/config` | `ChatbotController::config` | Feature flag, model name, and the tools *this* user has here |
| GET | `/conversations` | `ChatbotController::index` | Requesting user's threads, newest first, capped at 50 |
| POST | `/conversations` | `ChatbotController::store` | Empty thread |
| GET | `/conversations/{conversation}` | `ChatbotController::view` | Thread with full message history |
| POST | `/conversations/{conversation}/messages` | `ChatbotController::message` | Sends a message, runs the loop. Rate-limited: `api.chatbot` (10/min per user) |
| POST | `/conversations/{conversation}/messages/stream` | `ChatbotController::stream` | Same exchange as server-sent events. Same rate limit |
| POST | `/conversations/{conversation}/confirm` | `ChatbotController::confirm` | Approves/denies pending tool calls, resumes the loop. Same rate limit |
| POST | `/conversations/{conversation}/confirm/stream` | `ChatbotController::confirmStream` | Same decision as server-sent events. Same rate limit |
| DELETE | `/conversations/{conversation}` | `ChatbotController::delete` | 204 |

The nested route parameter is `{chatbotConversation}`, not `{conversation}`: the client API group is registered with `->scopeBindings()`, so Laravel resolves the child through a relation named after the parameter — `Server::chatbotConversations()`. Renaming the parameter without adding the matching relation produces a `BadMethodCallException` on every nested route.

Conversations are bound by `uuid` and are **user-scoped**: `authorizeConversation()` 404s a subuser trying to read the owner's threads even though they can see the server. `ChatbotConversation` was added to the `server_id` group in `ResourceBelongsToServer` so the middleware enforces server ownership too.

**Core services** — `app/Services/Chatbot/`

- `ChatbotService` — the orchestrator. `sendMessage()`, `resolveConfirmation()`, `pendingConfirmation()`, `toolsFor()`.
- `OpenAiClient` — `chat()`, `models()`, and static `verify()` used by the admin test button.
- `ToolRegistry` — instantiates the classes in `config/chatbot.php` and filters them per `ToolContext` (memoized per server+user).
- `ToolExecutor` — validates arguments, re-checks permissions, runs the tool, truncates the result, writes the activity log.
- `ToolContext` — the (server, user) pair; every `Gate` check goes through `can()`/`canAll()`.
- `SystemPromptBuilder` — server facts, working rules, and the absolute safety rules.
- `ChatbotSettings` — lazily reads `settings::panel:chatbot:*`, falling back to `config/panel.php`.

**Models**

- `ChatbotConversation` — `uuid`, `server_id`, `user_id`, `title`, `last_message_at`. `messages()` hasMany, **ordered ascending by id**.
- `ChatbotMessage` — `role` (`user`/`assistant`/`tool`), `content`, `tool_calls` (json), `tool_call_id`, `tool_name`, `status` (`complete`/`awaiting_confirmation`/`denied`/`failed`).

**Admin** — `app/Filament/Pages/Settings.php` → AI Chatbot tab

`panel:chatbot:` keys: `enabled`, `base_url`, `api_key` (Encrypter-encrypted), `model`, `temperature`, `max_tokens`, `max_iterations`, `history_limit`, `timeout`, `require_confirmation`, `system_prompt`, `tool_groups` (JSON array). `testChatbotConnection()` calls `OpenAiClient::verify()` against `/models` without saving.

**Frontend** — `resources/scripts/components/server/chat/ChatContainer.tsx`

- Route `chat` in `resources/scripts/routers/routes.ts` under `server.control`, no permission gate — every user with server access may open the page; `GET /config` decides what it can actually offer. Label from `resources/lang/en/routes.php` → `server.chat`.
- Sub-components: `ConversationList`, `ChatMessage`, `MessageComposer`, `PendingApproval`, `ToolCallChip`, `MessageContent`.
- API helpers: `resources/scripts/api/server/chat/*.ts` (+ `types.ts`, `transformers.ts`); SWR hooks in `resources/scripts/api/swr/getServerChat{Config,Conversations}.ts`.
- `MessageContent` renders through the existing `@/reviactyl/ui/Md2React` (bold + links) and additionally splits ``` fences into `<pre>` blocks and single backticks into inline `<code>`. No markdown dependency was added.

## Tools

Registered in `config/chatbot.php`; each extends `App\Services\Chatbot\Tools\ChatbotTool`.

| Group | Tools | Destructive |
|-------|-------|-------------|
| `server` | `get_server_details`, `get_server_resources`, `get_resource_history`, `get_activity_log` | — |
| `power` | `power_action` | yes |
| `console` | `send_console_command` | yes |
| `files` | `list_files`, `read_file`, `write_file`, `edit_file`, `create_folder`, `rename_files`, `copy_file`, `delete_files`, `compress_files`, `decompress_file` | write, rename, delete, decompress, copy, compress, edit |
| `subusers` | `list_subusers`, `list_subuser_permissions`, `create_subuser`, `update_subuser_permissions`, `delete_subuser` | create, update, delete |
| `startup` | `get_startup`, `update_startup_variable`, `update_startup_parts` | the two updates |
| `plugins` | `list_plugins`, `search_plugins`, `install_plugin`, `update_plugin`, `remove_plugin`, `toggle_plugin` | install, update, remove, toggle |
| `mods` | `list_mods`, `search_mods`, `install_mod`, `update_mod`, `remove_mod`, `toggle_mod` | install, update, remove, toggle |

`ChatbotToolGroup::defaults()` enables `server`, `power`, `files` and `startup` on a fresh install. `console` and `subusers` are off because they are the easiest to abuse via prompt injection from content the model reads; `plugins` and `mods` are off because installing one means fetching third-party code from the internet and running it on the game server.

The two registry groups are gated **twice**: by the group toggle and the subuser permission as usual, and then again inside every tool by `isEnabledFor($server)`, which is the egg allowlist (`panel:plugins:egg_ids` / `panel:mods:egg_ids`) that the plugin and mod pages use. A tool that skipped that check would install onto servers where an administrator had switched the feature off, so `PluginTool`/`ModTool` call it first in every `handle()`.

To add a tool: implement the abstract class, list it in `config/chatbot.php`. Permissions must match the equivalent `app/Http/Requests/Api/Client/Servers/**` request class — do not invent new ones.

## Patterns unique to this feature

- **The loop** (`ChatbotService::run()`): send history + tool definitions → if the model returns no tool calls, store the answer and stop → if any requested tool is destructive and confirmation is on, store the calls as `awaiting_confirmation` and return control to the user → otherwise execute every call, store one `tool` message per call, and go round again, up to `max_iterations`.
- **Confirmation resumes the same loop.** `resolveConfirmation()` writes a tool result for *every* pending call (a denial is a result too — "the user denied this action") and then re-enters `run()`. Skipping this would leave the provider with an unanswered `tool_call_id`, which is a hard API error.
- **Streaming reuses the loop rather than forking it.** `run()` takes an optional `$emit`; when present it streams from the provider and emits `message` / `delta` / `tool` events, ending with `status` and a `done` carrying the authoritative message list. Payloads are the same snake_case shape every other chat endpoint returns, so the client runs them through the same transformer. The turn is still one request under the same lock and the same rate limit — streaming changes when the user sees it, not how it runs.
- **A streamed assistant row is written before the text arrives.** Deltas need a uuid to attach to, so the row is created empty and filled in as the answer streams; `persistAssistant()` updates that row instead of creating a second one. Without it every streamed turn would leave an empty message behind.
- **`StreamAccumulator` exists because tool calls arrive shredded.** Content streams as plain fragments, but a tool call's name lands in one chunk and its JSON arguments dribble across many more, tied together only by `index`, with providers disagreeing on whether the id repeats. It reassembles them into the same `ChatCompletion` the blocking path builds, so nothing downstream knows which path was used. A provider that rejects streaming falls back to `chat()`.
- **`X-Accel-Buffering: no` is not optional.** nginx buffers proxied responses by default, which holds every event until the turn ends and silently defeats the whole feature.
- **The client merges by uuid, it does not append.** A confirmation leads with the resolved assistant message — same uuid, updated `status` — followed by anything new, so `mergeMessages()` upserts on uuid and re-sorts by `created_at`. Over the stream that message arrives with its calls still `pending` and each one resolves through its own `tool` event, which is why the client upserts tool calls by `call.id` rather than replacing the array.
- **The assistant's requests need a longer HTTP timeout than the rest of the panel.** `@/api/http` uses 20s; `sendMessage.ts` exports `ASSISTANT_REQUEST_TIMEOUT` (180s) and both send and confirm use it. A turn that chains tool calls routinely exceeds 30s, so PHP-FPM, nginx and any CDN in front of the panel need matching limits.
- **Reasoning is separated from the answer.** `ChatCompletion::fromResponse()` pulls chain-of-thought out of `message.reasoning_content` (DeepSeek, vLLM), `message.reasoning` (OpenRouter), *and* inline `<think>…</think>` blocks (MiniMax and friends), storing it in `chatbot_messages.reasoning`. The client shows it collapsed behind a "Show thinking" toggle (`ReasoningDisclosure.tsx`). An unterminated `<think>` — the model hit its token cap mid-thought — is treated as reasoning to the end of the string, so half a monologue is never rendered as the answer.
- **Reasoning is never replayed to the provider.** `buildProviderMessages()` omits it deliberately; providers that expose reasoning reject or degrade on requests that echo a previous turn's chain-of-thought back at them.
- **Tool messages are internal.** `ChatbotController::messages()` strips `role: tool` from API responses; the client sees outcomes as `status`/`ok` on the assistant message's `tool_calls` entries, each with a human-readable `summary` from `ChatbotTool::summarize()`.
- **`reorder()` everywhere.** The `messages()` relation carries a default ascending sort that silently wins over a later `latest('id')`. Any query in `ChatbotService` that wants newest-first must call `reorder('id', 'desc')`.
- **UUIDs are assigned by the service, not a model hook.** The base `Model` validates on `saving`, which fires *before* `creating`, so a hook-generated uuid would fail validation.
- **History is windowed by estimated tokens, not message count.** `selectHistory()` walks newest-first against `context_tokens`, after charging the system prompt and any existing summary against the same budget. `history_limit` remains only as a backstop. Counting messages was wrong because one tool result can outweigh fifty chat turns. The window is then trimmed from the front until it starts on a `user` message, because an assistant turn separated from its tool results is invalid input; if that empties it (one turn bigger than the whole budget), the turn is replayed in full.
- **Tool results are digested once their turn is over.** A result over 400 bytes from an earlier turn is replaced by `{"ok": …, "note": "Earlier result from <tool>, omitted…"}` — the outcome and the tool name survive so the model can re-call it, but the payload it has already reasoned about is not re-sent every message. Results from the turn in progress are always sent in full.
- **Compaction rolls forward, it does not rebuild.** When messages fall out of the window, `summarizeDropped()` spends one extra provider call to fold *only the newly-dropped gap* into `chatbot_conversations.summary`, tracked by `summary_through_id`, and injects it as a second system message. It is skipped when fewer than two messages have been dropped, capped at 2000 characters, and a summarization failure is logged and swallowed — the conversation degrades to plain forgetting, which is what would have happened anyway. Disable with `panel:chatbot:compaction`.
- **Panel-generated failures are not replayed.** Messages with `status: failed` hold panel error text, never model output; replaying them would teach the model to imitate error messages.
- **Result truncation** (`ToolExecutor::truncate()`): results over ~12 KB are trimmed, preferring keys named `content`, `output`, `files` or `entries` — name large payloads accordingly.
- **Internal exceptions are not shown to the model.** Only `DisplayException` messages are passed through; anything else becomes a generic string, because a `DaemonConnectionException` carries node addresses.
- **`max_tokens` fallback.** Newer OpenAI models reject `max_tokens` and require `max_completion_tokens`; `OpenAiClient::chat()` retries once with the other key rather than making the administrator know which is which.
- **Turns are serialized per conversation** by a `Cache::lock` (`withTurnLock()`), and a confirmation decision is claimed with a conditional `UPDATE … WHERE status = 'awaiting_confirmation'`. Both exist because the read-then-write version let two concurrent approvals execute the same destructive batch twice. The lock is never waited on — a second request means a double submit.
- **Copying and archiving are destructive.** They consume disk in proportion to what they are pointed at, and `compress_files` holds a 15-minute daemon timeout, so injected instructions could otherwise fill a node without the user ever being asked.
- **A single model response may execute at most `MAX_CALLS_PER_TURN` (8) tool calls.** Each call is a daemon request; the surplus is dropped and the model is told why so it narrows its next attempt.
- **`get_resource_history` summarises rather than dumps.** It returns SQL-computed averages and peaks over the whole window (accurate regardless of sample count), when each peak occurred, and a trend downsampled to 12 points from at most 400 rows. Handing a model 28 raw rows costs context and answers the question worse.
- **`get_activity_log` never returns IP addresses.** The panel shows an IP only to the actor themselves; rather than reproduce that rule the field is dropped outright, along with `useragent`. It otherwise mirrors `ActivityLogController`: `ActivityLog::DISABLED_EVENTS` excluded, and `config('activity.hide_admin_activity')` honoured by rejecting root-admin actors who are not the owner or a subuser.
- **Tool results are permission-shaped, not just permission-gated.** `get_server_details` mirrors `ServerTransformer`: without `allocation.read` it returns only the primary allocation with `notes` nulled. When adding a tool, check what the equivalent *transformer* hides, not only what the request class requires.
- **Activity log**: `server:chatbot.tool`, with `tool`, `group` and `arguments` properties — argument values over 512 characters are replaced with a placeholder, because activity properties are readable by anyone with `activity.read` and a `write_file` body would otherwise be exposed to a user without `file.read-content`. Translation in `resources/lang/en/activity.php`. Log failures never break a conversation.

## Gotchas

- **Suspended/installing servers**: the chat routes sit under `AuthenticateServerAccess`, so the assistant is unreachable exactly when the rest of the server API is.
- **Rate limit is per user, not per server**: 10 messages/min shared across all of a user's servers. One message can still cost several provider calls (up to `max_iterations`).
- **A bad `message_uuid` on `confirm/stream` returns 404**, which the client cannot tell apart from the endpoint not existing, so it silently retries against blocking `/confirm` — which 404s too, and the user sees that. Correct outcome, one wasted request. Only a distinct status for "not awaiting a decision" would remove the ambiguity.
- **`timeout` bounds each provider call, not the whole turn.** A turn chaining `max_iterations` calls can outlast any single one of them; the client allows 180s and the conversation lock 300s.
- **`require_confirmation` off means destructive tools run immediately**, with no undo. The system prompt compensates by telling the model to ask in text first, but that is a request, not a guarantee.
- **A pending confirmation blocks the thread**: `sendMessage()` refuses while one is outstanding. The client must resolve it or the user must start a new conversation.
- **Group toggles only ever reduce access.** Enabling `subusers` does not grant anyone `user.create`; it only stops the panel from hiding those tools from users who already have it.
- **`PANEL_CHATBOT_TOOL_GROUPS`** is a comma-separated env var parsed in `config/panel.php`; the database setting stores JSON. They are read through the same accessor, which tolerates both.
- **`create_subuser` skips the per-server creation throttle.** `POST /users` carries `ResourceLimit::Subuser` (10 per 15 min per server); the tool has no equivalent, so only `api.chatbot` (10 messages/min per user) bounds it. Not an escalation — `user.create` is still required and permissions are still capped — but it is a rate-control gap.
- **Conversations are private to their user.** A server owner cannot read a subuser's chats through the API. What the assistant *did* is still auditable: every tool call is written to the activity log with the acting user.
- **Prompt injection is mitigated, not solved.** `read_file`, `list_files` and subuser names return attacker-influenceable text. The system prompt forbids treating it as instructions and the permission model caps the blast radius, but an administrator enabling every group for a low-trust user is still trusting the model.
