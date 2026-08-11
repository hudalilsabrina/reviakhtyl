import http from '@/api/http';
import type { ModProvider, ModSort } from '@/api/server/mods/mods';

export interface ModpackHit {
    id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    iconUrl: string | null;
    downloads: number;
}

export const searchModpacks = async (
    uuid: string,
    provider: ModProvider,
    query: string,
    offset = 0,
    sort: ModSort = 'relevance'
): Promise<{ hits: ModpackHit[]; total: number }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/modpacks/search`, {
        params: { provider, query, offset, sort },
    });

    return {
        hits: (data.hits || []).map((hit: any) => ({
            id: hit.id,
            slug: hit.slug,
            title: hit.title,
            description: hit.description,
            author: hit.author,
            iconUrl: hit.icon_url,
            downloads: hit.downloads,
        })),
        total: data.total || 0,
    };
};

export interface ModpackPreview {
    name: string;
    format: 'modrinth' | 'curseforge';
    mods: { project_id?: string; version_id?: string; file_name?: string; provider: string; path?: string }[];
    download_url: string;
}

export const previewModpack = async (
    uuid: string,
    provider: ModProvider,
    projectId: string
): Promise<ModpackPreview> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/modpacks/preview`, {
        params: { provider, project_id: projectId },
    });
    return data;
};

export interface ModpackInstallResult {
    format: 'modrinth' | 'curseforge';
    name: string;
    success: { project_id: string; title: string; version: string; provider: ModProvider }[];
    failed: { project_id: string | null; provider: string | null; error: string }[];
}

export const installModpack = async (uuid: string, url: string): Promise<ModpackInstallResult> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/modpacks`, { url });
    return data;
};
