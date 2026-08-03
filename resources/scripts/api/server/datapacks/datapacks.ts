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

export interface UntrackedZip {
    file_name: string;
    size: number;
    slug: string;
    title: string;
    pack_format: number | null;
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
        total: data.total,
    };
};

export const getDatapackVersions = async (
    uuid: string,
    provider: DatapackProvider,
    projectId: string
): Promise<{ versions: any[]; dependencies: any[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks/versions`, {
        params: { provider, project_id: projectId },
    });

    return { versions: data.versions || [], dependencies: data.dependencies || [] };
};

export const getUntrackedDatapacks = async (
    uuid: string
): Promise<UntrackedZip[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/datapacks/untracked`);

    return (data.data || []).map((zip: any) => ({
        file_name: zip.file_name,
        size: zip.size,
        slug: zip.slug,
        title: zip.title,
        pack_format: zip.pack_format,
    }));
};

export const installDatapack = async (
    uuid: string,
    payload: {
        provider: DatapackProvider;
        projectId: string;
        title?: string;
        iconUrl?: string;
        versionId?: string;
        slug?: string;
        replace?: boolean;
    }
): Promise<ServerDatapack> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/datapacks`, payload);

    return rawDataToServerDatapack({ attributes: data });
};

export const linkDatapack = async (
    uuid: string,
    datapackId: number,
    payload: {
        provider: DatapackProvider;
        projectId: string;
        title?: string;
        iconUrl?: string;
        versionId?: string;
        versionNumber?: string;
        slug?: string;
    }
): Promise<ServerDatapack> => {
    const { data } = await http.post(
        `/api/client/servers/${uuid}/datapacks/${datapackId}/link`,
        payload
    );

    return rawDataToServerDatapack({ attributes: data });
};

export const updateDatapack = async (
    uuid: string,
    datapackId: number
): Promise<ServerDatapack> => {
    const { data } = await http.patch(
        `/api/client/servers/${uuid}/datapacks/${datapackId}/update`
    );

    return rawDataToServerDatapack({ attributes: data });
};

export const toggleDatapack = async (
    uuid: string,
    datapackId: number
): Promise<ServerDatapack> => {
    const { data } = await http.post(
        `/api/client/servers/${uuid}/datapacks/${datapackId}/toggle`
    );

    return rawDataToServerDatapack({ attributes: data });
};

export const deleteDatapack = async (
    uuid: string,
    datapackId: number
): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/datapacks/${datapackId}`);
};

export const registerDatapack = async (
    uuid: string,
    payload: {
        file_name: string;
        title?: string;
        slug?: string;
        version?: string;
    }
): Promise<ServerDatapack> => {
    const { data } = await http.post(
        `/api/client/servers/${uuid}/datapacks/register`,
        payload
    );

    return rawDataToServerDatapack({ attributes: data });
};

export const bulkUpdateDatapacks = async (
    uuid: string,
    datapackIds: number[]
): Promise<{ success: any[]; failed: any[] }> => {
    const { data } = await http.post(
        `/api/client/servers/${uuid}/datapacks/bulk/update`,
        { datapack_ids: datapackIds }
    );

    return { success: data.success || [], failed: data.failed || [] };
};

export const bulkDeleteDatapacks = async (
    uuid: string,
    datapackIds: number[]
): Promise<{ success: any[]; failed: any[] }> => {
    const { data } = await http.delete(`/api/client/servers/${uuid}/datapacks/bulk`, {
        data: { datapack_ids: datapackIds },
    });

    return { success: data.success || [], failed: data.failed || [] };
};
