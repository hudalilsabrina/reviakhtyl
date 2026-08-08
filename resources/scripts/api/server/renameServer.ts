import http from '@/api/http';

export default (uuid: string, name: string, description?: string, icon?: string | null): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/settings/rename`, { name, description, icon })
            .then(() => resolve())
            .catch(reject);
    });
};
