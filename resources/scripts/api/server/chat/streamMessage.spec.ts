import { afterEach, describe, expect, it, vi } from 'vitest';
import streamMessage, {
    ChatStreamError,
    ChatStreamHandlers,
    ServerSentEvent,
    createEventStreamParser,
    handleEvent,
    isStreamUnsupported,
} from '@/api/server/chat/streamMessage';

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

    it('accepts the camelCase form of a message as well as the snake_case one', () => {
        const { calls, handlers } = collect();

        handleEvent(
            {
                event: 'message',
                data: JSON.stringify({
                    message: {
                        uuid: 'b',
                        role: 'assistant',
                        content: 'hi',
                        reasoning: null,
                        status: 'complete',
                        toolCalls: [{ id: 'call_1', name: 'read_file', summary: 'Read', status: 'executed', ok: true }],
                        createdAt: '2026-07-26T12:00:00+00:00',
                    },
                }),
            },
            handlers
        );

        expect(calls).toEqual(['message:b:hi']);
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

describe('streamMessage', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('posts with the panel credentials and reports the turn as it arrives', async () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('token/with+padding=')}`;

        const fetchMock = vi.fn(() =>
            Promise.resolve(
                streamingResponse([
                    `event: message\ndata: ${JSON.stringify({ message: message('a', 'user', 'hi') })}\n\nevent: del`,
                    `ta\ndata: {"uuid":"b","content":"hey"}\n\nevent: done\ndata: {"messages":[]}\n\n`,
                ])
            )
        );
        vi.stubGlobal('fetch', fetchMock);

        const calls: string[] = [];
        await streamMessage(
            'server-uuid',
            'conversation-uuid',
            'hi',
            {
                onMessage: (m) => calls.push(`message:${m.uuid}`),
                onDelta: (uuid, content) => calls.push(`delta:${uuid}:${content}`),
                onDone: (messages) => calls.push(`done:${messages.length}`),
            },
            new AbortController().signal
        );

        expect(calls).toEqual(['message:a', 'delta:b:hey', 'done:0']);

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
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
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: false,
                    status: 404,
                    text: () => Promise.resolve('{"errors":[{"detail":"Not Found"}]}'),
                } as unknown as Response)
            )
        );

        const error = await streamMessage('a', 'b', 'hi', {}).catch((e: unknown) => e);

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

        const error = await streamMessage('a', 'b', 'hi', {}).catch((e: unknown) => e);

        expect(isStreamUnsupported(error)).toBe(true);
    });

    it('does not flag a server error as unsupported', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('') } as Response))
        );

        const error = await streamMessage('a', 'b', 'hi', {}).catch((e: unknown) => e);

        expect(isStreamUnsupported(error)).toBe(false);
        expect((error as ChatStreamError).message).toBe('Request failed with status code 500');
    });
});
