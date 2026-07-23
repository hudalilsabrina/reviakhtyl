import http from '@/api/http';

export interface CreateSplitPayload {
    name: string;
    cpu: number;
    memory: number;
    disk: number;
}

export default async (uuid: string, payload: CreateSplitPayload): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/splits`, payload);
};
