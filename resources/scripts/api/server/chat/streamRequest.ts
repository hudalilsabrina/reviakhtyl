import {
    RawChatMessage,
    RawChatToolCall,
    rawDataToChatMessage,
    rawDataToChatToolCall,
} from '@/api/server/chat/transformers';
import { AgentProgress, ChatMessage, ChatToolCall } from '@/api/server/chat/types';
import { store } from '@/state';

/**
 * The terminal state of a streamed turn. `denied` only ever comes from a confirmation the
 * user refused; a turn that was never held for approval cannot end that way.
 */
export type ChatStreamStatus = 'awaiting_confirmation' | 'complete' | 'failed' | 'denied';

export interface ChatStreamHandlers {
    /** A message was persisted server-side. Upsert it by uuid. */
    onMessage?: (message: ChatMessage) => void;
    /** Append a text fragment to the message with this uuid. Fragments arrive in order. */
    onDelta?: (uuid: string, content: string) => void;
    /** A tool call on this message changed state. Upsert it by `call.id`. */
    onTool?: (uuid: string, call: ChatToolCall) => void;
    /** An agent working on this message changed state. Upsert it by `key`. */
    onAgent?: (uuid: string, agent: AgentProgress) => void;
    onStatus?: (status: ChatStreamStatus) => void;
    /** The authoritative message list for the exchange; replaces anything accumulated from deltas. */
    onDone?: (messages: ChatMessage[]) => void;
    onError?: (message: string) => void;
}

export interface ServerSentEvent {
    event: string;
    data: string;
}

/**
 * Thrown for anything the stream could not deliver. It carries a `response` in the same
 * shape axios uses so `httpErrorToHuman` reads it exactly as it reads the rest of the API.
 */
export class ChatStreamError extends Error {
    public readonly status: number;
    public readonly response: { status: number; data: unknown };
    /**
     * The endpoint is not there to be streamed from — an older backend, a proxy that ate
     * the stream, or a browser with no `ReadableStream`. The caller should quietly use the
     * blocking endpoint instead of showing this to anyone.
     */
    public readonly unsupported: boolean;

    constructor(message: string, status: number, data: unknown = null, unsupported?: boolean) {
        super(message);

        this.name = 'ChatStreamError';
        this.status = status;
        this.response = { status, data };
        this.unsupported = unsupported ?? (status === 404 || status === 405);
    }
}

export const isStreamUnsupported = (error: unknown): boolean => error instanceof ChatStreamError && error.unsupported;

/**
 * The stream reuses the same shaper as every other chat endpoint, so its `message` and `done`
 * payloads are ordinary snake_case `RawChatMessage`s and go through the one transformer that
 * knows that shape. The only thing added here is a guard on the timestamp: an absent
 * `created_at` would become an Invalid Date, which throws when the row is formatted rather
 * than when it is parsed — a long way from the cause.
 */
const toChatMessage = (data: RawChatMessage): ChatMessage =>
    rawDataToChatMessage(data.created_at ? data : { ...data, created_at: new Date().toISOString() });

/**
 * Incrementally turns raw response chunks into SSE events.
 *
 * Chunk boundaries are arbitrary — one `read()` can carry three events, or the first half of
 * a `data:` line, or a `\r` whose `\n` has not arrived yet. Everything is therefore held in a
 * buffer and only consumed once a complete `\n\n` terminated block is present.
 */
export const createEventStreamParser = (onEvent: (event: ServerSentEvent) => void) => {
    let buffer = '';
    let danglingCarriageReturn = false;

    const dispatch = (block: string): void => {
        let name = '';
        const data: string[] = [];

        block.split('\n').forEach((line) => {
            // A line starting with a colon is a comment; servers send them purely to keep
            // idle connections from being reaped by a proxy.
            if (line.length === 0 || line.startsWith(':')) return;

            const separator = line.indexOf(':');
            const field = separator === -1 ? line : line.slice(0, separator);
            const raw = separator === -1 ? '' : line.slice(separator + 1);
            const value = raw.startsWith(' ') ? raw.slice(1) : raw;

            if (field === 'event') {
                name = value;
            } else if (field === 'data') {
                data.push(value);
            }
        });

        if (name === '' && data.length === 0) return;

        onEvent({ event: name === '' ? 'message' : name, data: data.join('\n') });
    };

    const drain = (): void => {
        let boundary = buffer.indexOf('\n\n');

        while (boundary !== -1) {
            dispatch(buffer.slice(0, boundary));
            buffer = buffer.slice(boundary + 2);
            boundary = buffer.indexOf('\n\n');
        }
    };

    return {
        push(chunk: string): void {
            let text = danglingCarriageReturn ? `\r${chunk}` : chunk;
            danglingCarriageReturn = false;

            // A chunk can end between the CR and the LF of a CRLF pair. Normalising that CR
            // now would invent a line break, and two invented breaks in a row would split one
            // event into two half-events — so it is held back until its LF turns up.
            if (text.endsWith('\r')) {
                text = text.slice(0, -1);
                danglingCarriageReturn = true;
            }

            buffer += text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            drain();
        },

        /**
         * Called once the response body ends. A well-behaved server terminates its last event
         * with a blank line, but one that does not should still have its final event read; a
         * genuinely truncated one fails to parse as JSON and is discarded downstream.
         */
        flush(): void {
            if (danglingCarriageReturn) {
                buffer += '\n';
                danglingCarriageReturn = false;
            }

            drain();

            const remainder = buffer;
            buffer = '';

            if (remainder.length > 0) dispatch(remainder);
        },
    };
};

export const handleEvent = (event: ServerSentEvent, handlers: ChatStreamHandlers): void => {
    let payload: any;

    try {
        payload = JSON.parse(event.data);
    } catch {
        // A keep-alive, or the tail of a connection that dropped mid-event. Either way there
        // is nothing to act on, and `done` is the source of truth for what was really said.
        return;
    }

    if (payload === null || typeof payload !== 'object') return;

    switch (event.event) {
        case 'message':
            if (payload.message) handlers.onMessage?.(toChatMessage(payload.message));
            break;
        case 'delta':
            if (typeof payload.uuid === 'string' && typeof payload.content === 'string') {
                handlers.onDelta?.(payload.uuid, payload.content);
            }
            break;
        case 'tool':
            if (typeof payload.uuid === 'string' && payload.call) {
                handlers.onTool?.(payload.uuid, rawDataToChatToolCall(payload.call as RawChatToolCall));
            }
            break;
        case 'agent':
            if (typeof payload.uuid === 'string' && typeof payload.agent === 'object' && payload.agent !== null) {
                handlers.onAgent?.(payload.uuid, payload.agent as AgentProgress);
            }
            break;
        case 'status':
            if (typeof payload.status === 'string') handlers.onStatus?.(payload.status as ChatStreamStatus);
            break;
        case 'done':
            handlers.onDone?.((payload.messages || []).map(toChatMessage));
            break;
        case 'error':
            handlers.onError?.(
                typeof payload.message === 'string' && payload.message.length > 0
                    ? payload.message
                    : 'The assistant could not finish this response.'
            );
            break;
    }
};

/**
 * `@/api/http` is an axios instance configured with `withCredentials` and the default XSRF
 * handling, neither of which `fetch` does on its own. These reproduce it: the session cookie
 * is sent, and Laravel's `XSRF-TOKEN` cookie is echoed back in the header its middleware
 * expects. Without them every request is a 419.
 */
const readCookie = (name: string): string | null => {
    const value = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`))?.[1];

    return value === undefined ? null : decodeURIComponent(value);
};

const buildHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'text/event-stream',
        'Content-Type': 'application/json',
    };

    const token = readCookie('XSRF-TOKEN');
    if (token !== null) headers['X-XSRF-TOKEN'] = token;

    return headers;
};

const errorFromResponse = async (response: Response): Promise<ChatStreamError> => {
    let data: unknown = null;
    let message = `Request failed with status code ${response.status}`;

    try {
        const body = await response.text();

        if (body.length > 0) {
            data = JSON.parse(body);
            const detail = (data as any)?.errors?.[0]?.detail;

            if (typeof detail === 'string' && detail.length > 0) message = detail;
        }
    } catch {
        // A non-JSON error body (an HTML proxy page, most likely). The status is the message.
    }

    return new ChatStreamError(message, response.status, data);
};

/**
 * POSTs to a chat endpoint that answers with an event stream, and reports the turn as it
 * happens rather than when it ends. Every such endpoint speaks the same events, so this is
 * the whole of the transport: starting a turn and confirming one differ only in where they
 * post and what they post there.
 *
 * `EventSource` cannot POST, so the response body is read as a stream and framed by hand.
 * Resolves once the server closes the connection; rejects on transport or HTTP failures, and
 * with an `AbortError` if `signal` fires.
 */
export default async (
    path: string,
    body: Record<string, unknown>,
    handlers: ChatStreamHandlers,
    signal?: AbortSignal
): Promise<void> => {
    store.getActions().progress.startContinuous();

    try {
        const response = await fetch(path, {
            method: 'POST',
            credentials: 'include',
            headers: buildHeaders(),
            body: JSON.stringify(body),
            signal,
        });

        if (!response.ok) throw await errorFromResponse(response);

        if (!response.body) {
            throw new ChatStreamError('Streaming responses are not available here.', response.status, null, true);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        const parser = createEventStreamParser((event) => handleEvent(event, handlers));

        for (;;) {
            const { done, value } = await reader.read();

            if (done) break;
            if (value) parser.push(decoder.decode(value, { stream: true }));
        }

        parser.push(decoder.decode());
        parser.flush();
    } finally {
        store.getActions().progress.setComplete();
    }
};
