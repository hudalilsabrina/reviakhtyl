import http from '@/api/http';

/**
 * Deletes a message and everything after it from the conversation.
 * The backend enforces that at least one user message always remains.
 */
export default async (uuid: string, conversation: string, messageUuid: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/chat/conversations/${conversation}/messages/${messageUuid}`);
};
