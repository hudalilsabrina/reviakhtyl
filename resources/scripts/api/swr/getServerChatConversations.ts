import useSWR, { ConfigInterface } from 'swr';
import getConversations from '@/api/server/chat/getConversations';
import { ChatConversation } from '@/api/server/chat/types';

export default (uuid: string, enabled = true, config?: ConfigInterface<ChatConversation[]>) =>
    useSWR<ChatConversation[]>(enabled ? ['server:chat:conversations', uuid] : null, () => getConversations(uuid), {
        revalidateOnFocus: false,
        ...(config || {}),
    });
