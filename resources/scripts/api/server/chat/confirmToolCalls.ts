import http from '@/api/http';
import { ASSISTANT_REQUEST_TIMEOUT } from '@/api/server/chat/sendMessage';
import { rawDataToChatMessage, RawChatMessage } from '@/api/server/chat/transformers';
import { ChatMessage } from '@/api/server/chat/types';

export interface ChatDecision {
    id: string;
    approved: boolean;
}

export default async (
    uuid: string,
    conversation: string,
    messageUuid: string,
    decisions: ChatDecision[]
): Promise<ChatMessage[]> => {
    const { data } = await http.post<{ data: { messages: RawChatMessage[] } }>(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}/confirm`,
        { message_uuid: messageUuid, decisions },
        { timeout: ASSISTANT_REQUEST_TIMEOUT }
    );

    return (data.data.messages || []).map(rawDataToChatMessage);
};
