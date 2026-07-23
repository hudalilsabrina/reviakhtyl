import http from '@/api/http';

export default async (uuid: string, childUuid: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/splits/${childUuid}/merge`);
};
