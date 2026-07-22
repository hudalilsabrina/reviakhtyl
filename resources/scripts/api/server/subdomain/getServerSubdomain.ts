import http, { FractalResponseData } from '@/api/http';

export interface ServerSubdomain {
    id: number;
    subdomain: string;
    domain: string;
    cloudflareDomainId: number;
    fqdn: string;
}

export interface SubdomainDomain {
    id: number;
    domain: string;
}

export interface ServerSubdomainState {
    domains: SubdomainDomain[];
    suggested: string;
    subdomain: ServerSubdomain | null;
}

export const rawDataToServerSubdomain = ({ attributes }: FractalResponseData): ServerSubdomain => ({
    id: attributes.id,
    subdomain: attributes.subdomain,
    domain: attributes.domain,
    cloudflareDomainId: attributes.cloudflare_domain_id,
    fqdn: attributes.fqdn,
});

export default async (uuid: string): Promise<ServerSubdomainState> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/subdomain`);

    return {
        domains: data.domains,
        suggested: data.suggested,
        subdomain: data.subdomain ? rawDataToServerSubdomain(data.subdomain) : null,
    };
};
