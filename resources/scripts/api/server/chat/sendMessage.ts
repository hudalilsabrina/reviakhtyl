import http from '@/api/http';
import { rawDataToChatMessage, RawChatMessage } from '@/api/server/chat/transformers';
import { ChatMessage } from '@/api/server/chat/types';

// The assistant has to round-trip to a model (and potentially run tools) before it
// answers, which comfortably outruns the 20s default configured on the http instance.
export const ASSISTANT_REQUEST_TIMEOUT = 180000;

export default async (uuid: string, conversation: string, content: string): Promise<ChatMessage[]> => {
    const { data } = await http.post<{ data: { messages: RawChatMessage[] } }>(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}/messages`,
        { content },
        { timeout: ASSISTANT_REQUEST_TIMEOUT }
    );

    return (data.data.messages || []).map(rawDataToChatMessage);
};
