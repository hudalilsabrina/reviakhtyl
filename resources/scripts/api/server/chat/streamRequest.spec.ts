import { afterEach, describe, expect, it, vi } from 'vitest';
import streamConfirmation from '@/api/server/chat/streamConfirmation';
import streamMessage from '@/api/server/chat/streamMessage';
import streamRequest, {
    ChatStreamError,
    ChatStreamHandlers,
    ServerSentEvent,
    createEventStreamParser,
    handleEvent,
    isStreamUnsupported,
} from '@/api/server/chat/streamRequest';
import { ChatMessage } from '@/api/server/chat/types';
import { applyDelta, applyToolCall, mergeMessages } from '@/components/server/chat/thread';

/** Feeds the parser a body split at the given offsets, returning everything it dispatched. */
const parse = (chunks: string[]): ServerSentEvent[] => {
    const events: ServerSentEvent[] = [];
    const parser = createEventStreamParser((event) => events.push(event));

    chunks.forEach((chunk) => parser.push(chunk));
    parser.flush();

    return events;
};

const frame = (event: string, data: unknown): string => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;

const message = (uuid: string, role: 'user' | 'assistant', content: string | null) => ({
    uuid,
    role,
    content,
    reasoning: null,
    status: 'complete',
    tool_calls: [],
    created_at: '2026-07-26T12:00:00+00:00',
});

describe('createEventStreamParser', () => {
    it('dispatches several events arriving in a single chunk', () => {
        const events = parse([
            `${frame('message', { message: message('a', 'user', 'hi') })}${frame('delta', {
                uuid: 'b',
                content: 'he',
            })}${frame('delta', { uuid: 'b', content: 'llo' })}`,
        ]);

        expect(events.map((event) => event.event)).toEqual(['message', 'delta', 'delta']);
        expect(JSON.parse(events[1]!.data)).toEqual({ uuid: 'b', content: 'he' });
    });

    it('reassembles an event split between its event and data lines', () => {
        const events = parse(['event: delta\nda', 'ta: {"uuid":"b","content":"x"}\n\n']);

        expect(events).toEqual([{ event: 'delta', data: '{"uuid":"b","content":"x"}' }]);
    });

    it('reassembles an event split in the middle of its JSON payload', () => {
        const events = parse(['event: delta\ndata: {"uuid":"b","con', 'tent":"half and half"}\n\n']);

        expect(JSON.parse(events[0]!.data)).toEqual({ uuid: 'b', content: 'half and half' });
    });

    it('reassembles an event split on the blank line that terminates it', () => {
        const body = frame('status', { status: 'complete' });
        const events = parse([body.slice(0, body.length - 1), body.slice(body.length - 1)]);

        expect(events).toEqual([{ event: 'status', data: '{"status":"complete"}' }]);
    });

    it('survives a body delivered one character at a time', () => {
        const body = [
            frame('message', { message: message('a', 'user', 'restart it') }),
            ': keep-alive\n\n',
            frame('message', { message: message('b', 'assistant', '') }),
            frame('delta', { uuid: 'b', content: 'Restarting' }),
            frame('delta', { uuid: 'b', content: ' now.' }),
            frame('status', { status: 'complete' }),
            frame('done', { messages: [message('a', 'user', 'restart it')] }),
        ].join('');

        const events = parse(body.split(''));

        expect(events.map((event) => event.event)).toEqual(['message', 'message', 'delta', 'delta', 'status', 'done']);
    });

    it('holds back a CR whose LF has not arrived yet', () => {
        // The blank line separating the two events straddles the chunk boundary as `\r` + `\n`.
        const events = parse([
            'event: delta\r\ndata: {"uuid":"b","content":"x"}\r\n\r',
            '\nevent: status\r\ndata: {}\r\n\r\n',
        ]);

        expect(events).toEqual([
            { event: 'delta', data: '{"uuid":"b","content":"x"}' },
            { event: 'status', data: '{}' },
        ]);
    });

    it('emits a final event that was not terminated by a blank line', () => {
        const events = parse(['event: done\ndata: {"messages":[]}\n']);

        expect(events).toEqual([{ event: 'done', data: '{"messages":[]}' }]);
    });

    it('does not emit a trailing event that is still incomplete', () => {
        const events = parse([frame('delta', { uuid: 'b', content: 'x' }), 'event: delta\ndata: {"uuid":"b","con']);

        // The truncated tail is handed on, but it is not valid JSON, so nothing acts on it.
        expect(events).toHaveLength(2);
        expect(() => JSON.parse(events[1]!.data)).toThrow();
    });

    it('ignores comments and joins multi-line data with newlines', () => {
        const events = parse([': keep-alive\n\nevent: error\ndata: line one\ndata: line two\n\n']);

        expect(events).toEqual([{ event: 'error', data: 'line one\nline two' }]);
    });

    it('defaults an unnamed event to `message`', () => {
        const events = parse(['data: {}\n\n']);

        expect(events[0]!.event).toBe('message');
    });
});

describe('handleEvent', () => {
    const collect = () => {
        const calls: string[] = [];
        const handlers: ChatStreamHandlers = {
            onMessage: (m) => calls.push(`message:${m.uuid}:${m.content ?? ''}`),
            onDelta: (uuid, content) => calls.push(`delta:${uuid}:${content}`),
            onTool: (uuid, call) => calls.push(`tool:${uuid}:${call.id}:${call.status}`),
            onStatus: (status) => calls.push(`status:${status}`),
            onDone: (messages) => calls.push(`done:${messages.map((m) => m.uuid).join(',')}`),
            onError: (m) => calls.push(`error:${m}`),
        };

        return { calls, handlers };
    };

    it('routes each event to its handler', () => {
        const { calls, handlers } = collect();
        const body = [
            frame('message', { message: message('a', 'user', 'restart it') }),
            frame('message', { message: message('b', 'assistant', '') }),
            frame('delta', { uuid: 'b', content: 'Restart' }),
            frame('delta', { uuid: 'b', content: 'ing.' }),
            frame('tool', {
                uuid: 'b',
                call: { id: 'call_1', name: 'power_action', summary: 'Restart', status: 'executed', ok: true },
            }),
            frame('status', { status: 'complete' }),
            frame('done', { messages: [message('a', 'user', 'restart it'), message('b', 'assistant', 'Restarting.')] }),
        ].join('');

        const parser = createEventStreamParser((event) => handleEvent(event, handlers));
        // Awkward split points: mid-event, mid-`data:` line and mid-payload all at once.
        [0, 37, 91, 140, 213].forEach((offset, index, offsets) => parser.push(body.slice(offset, offsets[index + 1])));
        parser.flush();

        expect(calls).toEqual([
            'message:a:restart it',
            'message:b:',
            'delta:b:Restart',
            'delta:b:ing.',
            'tool:b:call_1:executed',
            'status:complete',
            'done:a,b',
        ]);
    });

    it('reports a turn that ended in a refusal', () => {
        const { calls, handlers } = collect();

        // Only a confirmation can close this way. It is a state of the turn like any other and
        // must reach the client, which is what stops the cursor blinking on a message.
        handleEvent({ event: 'status', data: '{"status":"denied"}' }, handlers);

        expect(calls).toEqual(['status:denied']);
    });

    it('transforms a message exactly as the endpoint emits it', () => {
        const received: ChatMessage[] = [];
        // Byte for byte what the endpoint writes for a freshly created assistant message,
        // shaped by the same serialiser as `GET /conversations/{uuid}`.
        const body =
            'event: message\n' +
            'data: {"message":{"uuid":"80e1a0c2-1e5c-4e1a-9f6c-3f2b0a7d5e11","role":"assistant",' +
            '"content":null,"reasoning":null,"status":"complete",' +
            '"tool_calls":[{"id":"call_1","name":"read_file","summary":"Read latest.log","status":"executed",' +
            '"ok":true}],"created_at":"2026-07-27T02:04:31+07:00"}}\n\n';

        const parser = createEventStreamParser((event) => handleEvent(event, { onMessage: (m) => received.push(m) }));
        parser.push(body);
        parser.flush();

        expect(received).toEqual([
            {
                uuid: '80e1a0c2-1e5c-4e1a-9f6c-3f2b0a7d5e11',
                role: 'assistant',
                content: null,
                reasoning: null,
                status: 'complete',
                toolCalls: [
                    {
                        id: 'call_1',
                        name: 'read_file',
                        summary: 'Read latest.log',
                        status: 'executed',
                        ok: true,
                        destructive: false,
                    },
                ],
                createdAt: new Date('2026-07-27T02:04:31+07:00'),
            },
        ]);
    });

    it('stamps a message that somehow arrives without a timestamp', () => {
        const received: ChatMessage[] = [];

        handleEvent(
            {
                event: 'message',
                data: '{"message":{"uuid":"b","role":"assistant","content":"hi","reasoning":null,"status":"complete"}}',
            },
            { onMessage: (m) => received.push(m) }
        );

        // Should never happen; it must not reach the row and throw there as an Invalid Date.
        expect(received[0]!.createdAt.getTime()).not.toBeNaN();
    });

    it('drops an event whose payload is not valid JSON', () => {
        const { calls, handlers } = collect();

        handleEvent({ event: 'delta', data: '{"uuid":"b","con' }, handlers);

        expect(calls).toEqual([]);
    });

    it('falls back to a readable message when an error event carries none', () => {
        const { calls, handlers } = collect();

        handleEvent({ event: 'error', data: '{}' }, handlers);

        expect(calls).toEqual(['error:The assistant could not finish this response.']);
    });
});

/** A `Response` stand-in whose body arrives in the given chunks. */
const streamingResponse = (chunks: string[]): Response => {
    const encoder = new TextEncoder();
    let index = 0;

    return {
        ok: true,
        status: 200,
        body: {
            getReader: () => ({
                read: () =>
                    Promise.resolve(
                        index < chunks.length
                            ? { done: false, value: encoder.encode(chunks[index++]) }
                            : { done: true, value: undefined }
                    ),
            }),
        },
    } as unknown as Response;
};

const stubStream = (chunks: string[]) => {
    const fetchMock = vi.fn(() => Promise.resolve(streamingResponse(chunks)));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
};

const requestOf = (fetchMock: ReturnType<typeof stubStream>): [string, RequestInit] =>
    fetchMock.mock.calls[0] as unknown as [string, RequestInit];

const stubFailure = (status: number, body = '') =>
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: false, status, text: () => Promise.resolve(body) } as Response))
    );

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('streamRequest', () => {
    it('posts with the panel credentials and reports the turn as it arrives', async () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('token/with+padding=')}`;

        const fetchMock = stubStream([
            `event: message\ndata: ${JSON.stringify({ message: message('a', 'user', 'hi') })}\n\nevent: del`,
            `ta\ndata: {"uuid":"b","content":"hey"}\n\nevent: done\ndata: {"messages":[]}\n\n`,
        ]);

        const calls: string[] = [];
        await streamRequest(
            '/api/client/servers/server-uuid/chat/conversations/conversation-uuid/messages/stream',
            { content: 'hi' },
            {
                onMessage: (m) => calls.push(`message:${m.uuid}`),
                onDelta: (uuid, content) => calls.push(`delta:${uuid}:${content}`),
                onDone: (messages) => calls.push(`done:${messages.length}`),
            },
            new AbortController().signal
        );

        expect(calls).toEqual(['message:a', 'delta:b:hey', 'done:0']);

        const [url, init] = requestOf(fetchMock);
        expect(url).toBe('/api/client/servers/server-uuid/chat/conversations/conversation-uuid/messages/stream');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('include');
        expect(init.body).toBe('{"content":"hi"}');
        expect(init.headers).toMatchObject({
            Accept: 'text/event-stream',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'token/with+padding=',
        });
    });

    it('throws an error the http layer can render, and flags 404 as unsupported', async () => {
        stubFailure(404, '{"errors":[{"detail":"Not Found"}]}');

        const error = await streamRequest('/stream', {}, {}).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(ChatStreamError);
        expect((error as ChatStreamError).response.data).toEqual({ errors: [{ detail: 'Not Found' }] });
        expect((error as ChatStreamError).message).toBe('Not Found');
        expect(isStreamUnsupported(error)).toBe(true);
    });

    it('treats a response with no readable body as an unsupported stream', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: true, status: 200, body: null } as Response))
        );

        const error = await streamRequest('/stream', {}, {}).catch((e: unknown) => e);

        expect(isStreamUnsupported(error)).toBe(true);
    });

    it('does not flag a server error as unsupported', async () => {
        stubFailure(500);

        const error = await streamRequest('/stream', {}, {}).catch((e: unknown) => e);

        expect(isStreamUnsupported(error)).toBe(false);
        expect((error as ChatStreamError).message).toBe('Request failed with status code 500');
    });
});

describe('streamMessage', () => {
    it('sends the content to the message stream of the conversation', async () => {
        const fetchMock = stubStream([frame('done', { messages: [] })]);

        await streamMessage('server-uuid', 'conversation-uuid', 'restart it', {});

        const [url, init] = requestOf(fetchMock);
        expect(url).toBe('/api/client/servers/server-uuid/chat/conversations/conversation-uuid/messages/stream');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"content":"restart it"}');
    });
});

describe('streamConfirmation', () => {
    /** The assistant message the thread is holding, stopped on a destructive call. */
    const awaiting = (): ChatMessage => ({
        uuid: 'assistant-1',
        role: 'assistant',
        content: 'I can restart the server for you.',
        reasoning: null,
        status: 'awaiting_confirmation',
        toolCalls: [
            {
                id: 'call_1',
                name: 'power_action',
                summary: 'Restart the server',
                status: 'pending',
                ok: null,
                destructive: true,
            },
        ],
        createdAt: new Date('2026-07-26T12:00:00+00:00'),
    });

    /** The same message as the stream returns it, with the decision applied to it. */
    const resolved = (status: 'complete' | 'denied', call: 'pending' | 'executed' | 'denied') => ({
        uuid: 'assistant-1',
        role: 'assistant',
        content: 'I can restart the server for you.',
        reasoning: null,
        status,
        tool_calls: [
            {
                id: 'call_1',
                name: 'power_action',
                summary: 'Restart the server',
                status: call,
                ok: call === 'executed',
                destructive: true,
            },
        ],
        created_at: '2026-07-26T12:00:00+00:00',
    });

    const continuation = (content: string) => ({
        uuid: 'assistant-2',
        role: 'assistant',
        content,
        reasoning: null,
        status: 'complete',
        tool_calls: [],
        created_at: '2026-07-26T12:00:09+00:00',
    });

    /**
     * Reads a confirmation stream into a thread the way the container does, returning both what
     * is left on screen and how the decided message read after every event along the way.
     */
    const play = async (approved: boolean): Promise<{ thread: ChatMessage[]; trace: string[] }> => {
        let thread = [awaiting()];
        const trace: string[] = [];
        const record = () => trace.push(`${thread[0]!.status}/${thread[0]!.toolCalls[0]!.status}`);

        await streamConfirmation('server-uuid', 'conversation-uuid', 'assistant-1', approved, {
            onMessage: (m) => {
                thread = mergeMessages(thread, [m]);
                record();
            },
            onDelta: (uuid, fragment) => {
                thread = applyDelta(thread, uuid, fragment);
            },
            onTool: (uuid, call) => {
                thread = applyToolCall(thread, uuid, call);
                record();
            },
            onDone: (messages) => {
                thread = mergeMessages(thread, messages);
                record();
            },
        });

        return { thread, trace };
    };

    it('posts the decision to the confirmation stream of the conversation', async () => {
        const fetchMock = stubStream([frame('done', { messages: [] })]);

        await streamConfirmation('server-uuid', 'conversation-uuid', 'assistant-1', true, {});

        const [url, init] = requestOf(fetchMock);
        expect(url).toBe('/api/client/servers/server-uuid/chat/conversations/conversation-uuid/confirm/stream');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"message_uuid":"assistant-1","approved":true}');
        expect(init.headers).toMatchObject({ Accept: 'text/event-stream' });
    });

    it('resolves the message it was asked about in place rather than adding a second copy', async () => {
        stubStream([
            // The stream opens with the message the approval panel was showing, same uuid: the
            // decision has been recorded, but the calls it approved have not run yet.
            frame('message', { message: resolved('complete', 'pending') }),
            frame('tool', {
                uuid: 'assistant-1',
                call: {
                    id: 'call_1',
                    name: 'power_action',
                    summary: 'Restart the server',
                    status: 'executed',
                    ok: true,
                    destructive: true,
                },
            }),
            frame('message', { message: continuation('') }),
            frame('delta', { uuid: 'assistant-2', content: 'Restarted' }),
            frame('delta', { uuid: 'assistant-2', content: ' it for you.' }),
            frame('status', { status: 'complete' }),
            frame('done', { messages: [resolved('complete', 'executed'), continuation('Restarted it for you.')] }),
        ]);

        const { thread, trace } = await play(true);

        expect(thread).toHaveLength(2);
        expect(thread[0]!.uuid).toBe('assistant-1');
        // The panel comes off the screen on the first event, and the action it was asking about
        // reports separately once it has actually run.
        expect(trace).toEqual(['complete/pending', 'complete/executed', 'complete/executed', 'complete/executed']);
        expect(thread[0]!.status).toBe('complete');
        expect(thread[0]!.toolCalls).toEqual([
            {
                id: 'call_1',
                name: 'power_action',
                summary: 'Restart the server',
                status: 'executed',
                ok: true,
                destructive: true,
            },
        ]);
        expect(thread[1]!.content).toBe('Restarted it for you.');
    });

    it('keeps streaming the assistant reply after a refusal', async () => {
        stubStream([
            frame('message', { message: resolved('denied', 'pending') }),
            frame('tool', {
                uuid: 'assistant-1',
                call: {
                    id: 'call_1',
                    name: 'power_action',
                    summary: 'Restart the server',
                    status: 'denied',
                    ok: false,
                    destructive: true,
                },
            }),
            frame('message', { message: continuation('') }),
            frame('delta', { uuid: 'assistant-2', content: 'Understood — ' }),
            frame('delta', { uuid: 'assistant-2', content: 'I have not touched the server.' }),
            frame('status', { status: 'denied' }),
            frame('done', {
                messages: [resolved('denied', 'denied'), continuation('Understood — I have not touched the server.')],
            }),
        ]);

        const { thread } = await play(false);

        expect(thread).toHaveLength(2);
        expect(thread[0]!.status).toBe('denied');
        expect(thread[0]!.toolCalls[0]!.status).toBe('denied');
        expect(thread[0]!.toolCalls[0]!.ok).toBe(false);
        // A refusal is not the end of the turn: the assistant still answers in words.
        expect(thread[1]!.content).toBe('Understood — I have not touched the server.');
    });

    it('flags a backend without the confirmation stream so the caller can fall back', async () => {
        stubFailure(404);

        const error = await streamConfirmation('a', 'b', 'c', true, {}).catch((e: unknown) => e);

        // The signal `handleDecision` reads before quietly posting to the blocking `/confirm`.
        expect(isStreamUnsupported(error)).toBe(true);
    });

    it('does not fall back when the decision itself was refused', async () => {
        stubFailure(409, '{"errors":[{"detail":"This message is no longer awaiting a decision."}]}');

        const error = await streamConfirmation('a', 'b', 'c', true, {}).catch((e: unknown) => e);

        // Replaying that against the blocking endpoint would fail the same way and hide why.
        expect(isStreamUnsupported(error)).toBe(false);
        expect((error as ChatStreamError).message).toBe('This message is no longer awaiting a decision.');
    });
});
