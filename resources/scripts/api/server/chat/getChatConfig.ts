import http from '@/api/http';
import { rawDataToChatConfig, RawChatConfig } from '@/api/server/chat/transformers';
import { ChatConfig } from '@/api/server/chat/types';

export default async (uuid: string): Promise<ChatConfig> => {
    const { data } = await http.get<RawChatConfig>(`/api/client/servers/${uuid}/chat/config`);

    return rawDataToChatConfig(data);
};
