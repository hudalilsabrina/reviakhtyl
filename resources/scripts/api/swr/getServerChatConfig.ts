import useSWR, { ConfigInterface } from 'swr';
import getChatConfig from '@/api/server/chat/getChatConfig';
import { ChatConfig } from '@/api/server/chat/types';

export default (uuid: string, config?: ConfigInterface<ChatConfig>) =>
    useSWR<ChatConfig>(['server:chat:config', uuid], () => getChatConfig(uuid), {
        revalidateOnFocus: false,
        errorRetryCount: 2,
        ...(config || {}),
    });
