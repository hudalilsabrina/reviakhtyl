import http from '@/api/http';

export default async (uuid: string): Promise<boolean> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/subdomain/status`);

    return data.propagated === true;
};
