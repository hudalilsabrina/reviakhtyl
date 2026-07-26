import http from '@/api/http';
import { rawDataToChatConversationDetails, RawChatConversation } from '@/api/server/chat/transformers';
import { ChatConversationDetails } from '@/api/server/chat/types';

export default async (uuid: string, conversation: string): Promise<ChatConversationDetails> => {
    const { data } = await http.get<{ data: RawChatConversation }>(
        `/api/client/servers/${uuid}/chat/conversations/${conversation}`
    );

    return rawDataToChatConversationDetails(data.data);
};
