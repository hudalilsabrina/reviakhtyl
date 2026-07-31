import http from '@/api/http';
import { rawDataToChatMessage, RawChatMessage } from '@/api/server/chat/transformers';
import { ChatMessage } from '@/api/server/chat/types';

export default async (
    uuid: string,
    conversation: string,
    messageUuid: string,
): Promise<ChatMessage[]> => {
    const { data } = await http.post<{ data: { messages: RawChatMessage[] } }>(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}/messages/regenerate`,
        { message_uuid: messageUuid },
        { timeout: 180_000 },
    );

    return (data.data.messages || []).map(rawDataToChatMessage);
};
