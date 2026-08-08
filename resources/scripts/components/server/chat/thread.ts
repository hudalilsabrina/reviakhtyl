import { AgentProgress, ChatMessage, ChatToolCall } from '@/api/server/chat/types';

/**
 * The pure part of keeping a thread on screen in step with the server: everything that turns
 * one stream event, or one blocking response, into the next list of messages. Kept out of the
 * container so it can be reasoned about — and tested — without a rendered chat.
 */

/**
 * The local echo of a message the user has sent but the server has not yet
 * confirmed. Without it the composer clears and nothing appears until the whole
 * turn returns — which can take a minute — so it looks like nothing was sent.
 */
export const optimisticMessage = (content: string): ChatMessage => ({
    uuid: `pending-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    pending: true,
    role: 'user',
    content,
    reasoning: null,
    status: 'complete',
    toolCalls: [],
    createdAt: new Date(),
});

/**
 * Upserts by uuid rather than appending. Both the send and the confirm stream re-send messages
 * that are already on screen — a confirmation opens by returning the very message it resolved,
 * same uuid, with the decision applied — and appending those would show the exchange twice.
 */
export const mergeMessages = (existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] => {
    // Local echoes are dropped wholesale: the server returns the real message
    // with its own uuid, so keeping them would show the text twice.
    const merged = existing.filter((message) => !message.pending);

    incoming.forEach((message) => {
        const index = merged.findIndex((m) => m.uuid === message.uuid);

        if (index === -1) {
            merged.push(message);
        } else {
            merged[index] = message;
        }
    });

    return merged.sort((a, b) => a.createdAt.getTime() - b.createdAt.getTime());
};

/** Appends a streamed fragment to the message it belongs to. */
export const applyDelta = (messages: ChatMessage[], uuid: string, fragment: string): ChatMessage[] =>
    messages.map((message) =>
        message.uuid === uuid ? { ...message, content: (message.content ?? '') + fragment } : message
    );

/** Appends a streamed chain-of-thought fragment to the message it belongs to. */
export const applyReasoning = (messages: ChatMessage[], uuid: string, fragment: string): ChatMessage[] =>
    messages.map((message) =>
        message.uuid === uuid ? { ...message, reasoning: (message.reasoning ?? '') + fragment } : message
    );

/** Upserts a tool call by id — a call is announced when proposed and again once it has run. */
export const applyToolCall = (messages: ChatMessage[], uuid: string, call: ChatToolCall): ChatMessage[] =>
    messages.map((message) => {
        if (message.uuid !== uuid) return message;

        const index = message.toolCalls.findIndex((existing) => existing.id === call.id);

        return {
            ...message,
            toolCalls:
                index === -1
                    ? [...message.toolCalls, call]
                    : message.toolCalls.map((existing, position) => (position === index ? call : existing)),
        };
    });

/** Upserts an agent run by key — an agent is announced when it starts and again once it finishes. */
export const applyAgentRun = (messages: ChatMessage[], uuid: string, agent: AgentProgress): ChatMessage[] =>
    messages.map((message) => {
        if (message.uuid !== uuid) return message;

        const runs = message.agentRuns ?? [];
        const index = runs.findIndex((existing) => existing.key === agent.key);

        return {
            ...message,
            agentRuns:
                index === -1
                    ? [...runs, agent]
                    : runs.map((existing, position) => (position === index ? agent : existing)),
        };
    });
