import http from '@/api/http';

export interface CreateSplitPayload {
    name: string;
    cpu: number;
    memory: number;
    disk: number;
    startup?: string;
    image?: string;
    environment?: Record<string, string>;
}

export default async (uuid: string, payload: CreateSplitPayload): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/splits`, payload);
};
