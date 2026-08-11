import http from '@/api/http';

export interface SubdomainStatus {
    hasSubdomain: boolean;
    propagated: boolean;
}

export default async (uuid: string): Promise<SubdomainStatus> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/subdomain/status`);

    return {
        hasSubdomain: data.has_subdomain === true,
        propagated: data.propagated === true,
    };
};
