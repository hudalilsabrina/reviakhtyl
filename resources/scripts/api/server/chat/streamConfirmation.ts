import streamRequest, { ChatStreamHandlers } from '@/api/server/chat/streamRequest';

/**
 * Answers a pending confirmation and reports what follows as it happens. The streaming
 * counterpart of `confirmToolCalls`, and the same request it makes.
 *
 * The stream opens with the message that was awaiting the decision, sent again under its own
 * uuid with the decision applied — so the first `message` event is an upsert of a row already
 * on screen, not a new one. A refusal streams too: the assistant still gets a turn to say why
 * it will not proceed, so events keep coming after the message turns `denied`.
 */
export default async (
    uuid: string,
    conversation: string,
    messageUuid: string,
    approved: boolean,
    handlers: ChatStreamHandlers,
    signal?: AbortSignal
): Promise<void> =>
    streamRequest(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}/confirm/stream`,
        { message_uuid: messageUuid, approved },
        handlers,
        signal
    );
