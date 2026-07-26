import http from '@/api/http';

export default async (uuid: string, conversation: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/chat/conversations/${conversation}`);
};
