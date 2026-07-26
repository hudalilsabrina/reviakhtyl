import useSWR, { ConfigInterface } from 'swr';
import getConversations from '@/api/server/chat/getConversations';
import { ChatConversation } from '@/api/server/chat/types';

export default (uuid: string, enabled = true, config?: ConfigInterface<ChatConversation[]>) =>
    useSWR<ChatConversation[]>(enabled ? ['server:chat:conversations', uuid] : null, () => getConversations(uuid), {
        revalidateOnFocus: false,
        // Without a cap SWR retries a failing request forever with backoff, so a broken
        // endpoint quietly hammers the panel for as long as the page is open.
        errorRetryCount: 3,
        ...(config || {}),
    });
