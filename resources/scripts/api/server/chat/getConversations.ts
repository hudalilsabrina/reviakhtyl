import http from '@/api/http';
import { rawDataToChatConversation, RawChatConversation } from '@/api/server/chat/transformers';
import { ChatConversation } from '@/api/server/chat/types';

export default async (uuid: string): Promise<ChatConversation[]> => {
    const { data } = await http.get<{ data: RawChatConversation[] }>(`/api/client/servers/${uuid}/chat/conversations`);

    return (data.data || []).map(rawDataToChatConversation);
};
