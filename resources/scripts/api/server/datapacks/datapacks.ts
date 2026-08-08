import http from '@/api/http';

export type DatapackProvider = 'modrinth' | 'curseforge' | 'manual';

export interface ServerDatapack {
    id: number;
    provider: DatapackProvider;
    projectId: string;
    title: string;
    versionId: string;
    versionNumber: string;
    fileName: string;
    iconUrl: string | null;
    disabled: boolean;
}

export interface DatapackHit {
    id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    iconUrl: string | null;
    downloads: number;
    installedVersion: string | null;
}

export const rawDataToServerDatapack = ({ attributes }: any): ServerDatapack => ({
    id: attributes.id,
    provider: attributes.provider,
    projectId: attributes.project_id,
    title: attributes.title,
    versionId: attributes.version_id,
    versionNumber: attributes.version_number,
    fileName: attributes.file_name,
    iconUrl: attributes.icon_url,
    disabled: attributes.disabled,
});

export const getServerDatapacks = async (
    uuid: string
): Promise<{ datapacks: ServerDatapack[]; gameVersion: string | null }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks`);

    return {
        datapacks: (data.datapacks.data || []).map(rawDataToServerDatapack),
        gameVersion: data.game_version,
    };
};

export type DatapackSort = 'relevance' | 'downloads' | 'updated';

export const searchDatapacks = async (
    uuid: string,
    provider: DatapackProvider,
    query: string,
    offset = 0,
    sort: DatapackSort = 'relevance'
): Promise<{ hits: DatapackHit[]; total: number }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks/search`, {
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
            installedVersion: hit.installed_version,
        })),
        total: data.total || 0,
    };
};

export interface DatapackVersion {
    id: string;
    versionNumber: string;
    fileName: string;
    gameVersions: string[];
}

export const getDatapackVersions = async (
    uuid: string,
    provider: DatapackProvider,
    projectId: string
): Promise<{ versions: DatapackVersion[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks/versions`, {
        params: { provider, project_id: projectId },
    });

    return {
        versions: (data.versions || []).map((v: any) => ({
            id: v.id,
            versionNumber: v.version_number,
            fileName: v.file_name,
            gameVersions: v.game_versions || [],
        })),
    };
};

export const installDatapack = async (
    uuid: string,
    provider: DatapackProvider,
    projectId: string,
    title?: string,
    iconUrl?: string | null,
    versionId?: string,
    slug?: string,
    replace = false
): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks`, {
        provider,
        project_id: projectId,
        title,
        icon_url: iconUrl,
        version_id: versionId,
        slug,
        replace,
    });

    return rawDataToServerDatapack(data);
};

export const updateDatapack = async (uuid: string, datapackId: number): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks/${datapackId}/update`);

    return rawDataToServerDatapack(data);
};

export const linkDatapack = async (
    uuid: string,
    datapackId: number,
    provider: DatapackProvider,
    projectId: string,
    title: string,
    iconUrl: string | null,
    versionId: string,
    versionNumber: string,
    slug: string
): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks/${datapackId}/link`, {
        provider,
        project_id: projectId,
        title,
        icon_url: iconUrl,
        version_id: versionId,
        version_number: versionNumber,
        slug,
    });

    return rawDataToServerDatapack(data);
};

export const toggleDatapack = async (uuid: string, datapackId: number): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks/${datapackId}/toggle`);

    return rawDataToServerDatapack(data);
};

export const deleteDatapack = async (uuid: string, datapackId: number): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/datapacks/${datapackId}`);
};

export interface UntrackedZip {
    file_name: string;
    size: number;
    slug: string;
    title: string;
    version: string;
}

export const getUntrackedZips = async (uuid: string): Promise<UntrackedZip[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks/untracked`);

    return data.data || [];
};

export const registerZip = async (uuid: string, zip: UntrackedZip): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks/register`, {
        file_name: zip.file_name,
        title: zip.title,
        slug: zip.slug,
        version: zip.version,
    });

    return rawDataToServerDatapack(data);
};

export interface BulkOperationResult {
    success: { id: number; title: string; version?: string }[];
    failed: { id: number; title: string; error: string }[];
}

export const bulkUpdateDatapacks = async (uuid: string, datapackIds: number[]): Promise<BulkOperationResult> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks/bulk/update`, {
        datapack_ids: datapackIds,
    });
    return data;
};

export const bulkDeleteDatapacks = async (uuid: string, datapackIds: number[]): Promise<BulkOperationResult> => {
    const { data } = await http.delete(`/api/client/servers/${uuid}/datapacks/bulk`, {
        data: { datapack_ids: datapackIds },
    });
    return data;
};
