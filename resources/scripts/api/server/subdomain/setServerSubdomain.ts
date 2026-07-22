import http from '@/api/http';
import { rawDataToServerSubdomain, ServerSubdomain } from '@/api/server/subdomain/getServerSubdomain';

export default async (uuid: string, subdomain: string, domainId: number): Promise<ServerSubdomain> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/subdomain`, {
        subdomain,
        domain_id: domainId,
    });

    return rawDataToServerSubdomain(data);
};
