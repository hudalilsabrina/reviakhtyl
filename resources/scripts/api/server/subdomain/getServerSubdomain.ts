import http, { FractalResponseData } from '@/api/http';

export interface ServerSubdomain {
    id: number;
    subdomain: string;
    domain: string;
    fqdn: string;
}

export interface ServerSubdomainState {
    domain: string;
    suggested: string;
    subdomain: ServerSubdomain | null;
}

export const rawDataToServerSubdomain = ({ attributes }: FractalResponseData): ServerSubdomain => ({
    id: attributes.id,
    subdomain: attributes.subdomain,
    domain: attributes.domain,
    fqdn: attributes.fqdn,
});

export default async (uuid: string): Promise<ServerSubdomainState> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/subdomain`);

    return {
        domain: data.domain,
        suggested: data.suggested,
        subdomain: data.subdomain ? rawDataToServerSubdomain(data.subdomain) : null,
    };
};
