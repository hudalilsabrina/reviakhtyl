import http from '@/api/http';

export default (uuid: string, file: File): Promise<string> => {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('image', file);

        http.post(`/api/client/servers/${uuid}/settings/icon`, formData)
            .then(({ data }) => resolve(data.icon))
            .catch(reject);
    });
};
