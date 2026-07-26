import streamRequest, { ChatStreamHandlers } from '@/api/server/chat/streamRequest';

/**
 * Sends a message and reports the turn as it happens rather than when it ends. The
 * streaming counterpart of `sendMessage`, and the same request it makes — only the reply
 * arrives in pieces.
 */
export default async (
    uuid: string,
    conversation: string,
    content: string,
    handlers: ChatStreamHandlers,
    signal?: AbortSignal
): Promise<void> =>
    streamRequest(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}/messages/stream`,
        { content },
        handlers,
        signal
    );
