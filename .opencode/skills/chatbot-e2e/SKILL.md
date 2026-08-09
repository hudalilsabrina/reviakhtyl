---
name: chatbot-e2e
description: Drive a real end-to-end chatbot tool-call turn against the live Reviactyl panel — send a message, approve destructive calls, verify changes on the node, check the audit log, and clean up. Use when testing a new chatbot tool (e.g. edit_file, a mod installer, a database create) for real on the production install.
---

# Chatbot E2E

Requires an account API key. Use the `reviactyl-api-key` skill to create and delete one.

Read `APP_URL` and DB credentials from `.env` at runtime. The server uuid may be read from the `/api/client` list or from `mysql servers.uuid`.

The chat API is under `$APP_URL/api/client/servers/{uuid}/chat/`.

## 1. Get a server uuid

```bash
curl -s -H "Authorization: Bearer $KEY" -H "Accept: application/json" \
  "$APP_URL/api/client" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['data'][0]['attributes']['uuid'])"
```

## 2. Create a conversation

```bash
curl -s -X POST -H "Authorization: Bearer $KEY" -H "Accept: application/json" \
  "$APP_URL/api/client/servers/{uuid}/chat/conversations"
```

Store the returned `data.uuid` as `$CONV`.

## 3. Send a message

```bash
curl -s --max-time 180 -X POST -H "Authorization: Bearer $KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"content":"Read server.properties, then change motd to Hello World."}' \
  "$APP_URL/api/client/servers/{uuid}/chat/conversations/$CONV/messages"
```

- Field is `content`, not `message`. Max 8000 chars.
- `--max-time 180`: a turn chaining tool calls can outlast any single provider call.

## 4. Read the result

The response contains `data.messages[]` — the **last** one is the final assistant turn:

```python
import json,sys
d = json.load(sys.stdin)
for m in d['data']['messages']:
    tc = [(t['name'], t['status'], t['ok']) for t in m['tool_calls']]
    print(m['role'], m['status'], tc, (m['content'] or '')[:200])
```

- `status: complete, ok: true` → tool executed.
- `status: complete, ok: false` → tool failed (DisplayException surfaced).
- `status: awaiting_confirmation` → destructive tool held; capture `message_uuid` + the call's `id`.
- `status: denied` → the user rejected it.

## 5. Approve or deny pending calls

```bash
curl -s --max-time 180 -X POST -H "Authorization: Bearer $KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"message_uuid":"<assistant_uuid>","decisions":[{"id":"<call_id>","approved":true}]}' \
  "$APP_URL/api/client/servers/{uuid}/chat/conversations/$CONV/confirm"
```

Confirm resumes the same loop — the model may chain more calls.

## 6. Verify changes on the node

Read the file through the panel file API (the panel proxies Wings):

```bash
curl -s -H "Authorization: Bearer $KEY" -H "Accept: application/json" \
  "$APP_URL/api/client/servers/{uuid}/files/contents?file=/server.properties"
```

## 7. Check the audit log

```bash
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  -e "SELECT id,event,properties FROM activity_logs WHERE event='server:chatbot.tool' ORDER BY id DESC LIMIT 5;"
```

The tool name, group, and arguments are stored in `properties`. Argument values >512 chars are redacted in the log.

## 8. Clean up

- Restore any file modified by the test to its original value (POST `/files/write?file=…` with `{"content": "..."}`). Do NOT leave a test motd on a real server.
- Delete the conversation: `curl -X DELETE …/chat/conversations/$CONV`
- Delete the API key (see `reviactyl-api-key` skill).

## Gotchas

- `require_confirmation` is on in production: destructive tools pause for approval.
- 10 messages/min rate limit per user (one turn is one message, even if it chains 8 calls).
- `ASSISTANT_REQUEST_TIMEOUT` is 180 s client-side; curl `--max-time 180` matches.
- File write: the endpoint is `POST …/files/write?file=<path>` with body `{"content": "…"}`; raw body returns 400.
- `content` / `message` mismatch: the chat API field is `content`, not `message`.
- The tool list the model sees is gated by subuser permissions — if the tool is not offered, check `settings::panel:chatbot:tool_groups` and the user's permissions.
- Conversations are private per user (admin can't read another user's chat), but every tool call is logged to `activity_logs`.
