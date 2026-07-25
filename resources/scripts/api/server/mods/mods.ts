import http from '@/api/http';

export type ModProvider = 'modrinth' | 'manual';

export interface ServerMod {
    id: number;
    provider: ModProvider;
    projectId: string;
    title: string;
    versionId: string;
    versionNumber: string;
    fileName: string;
    iconUrl: string | null;
    disabled: boolean;
}

export interface ModHit {
    id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    iconUrl: string | null;
    downloads: number;
    installedVersion: string | null;
}

export const rawDataToServerMod = ({ attributes }: any): ServerMod => ({
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

export const getServerMods = async (
    uuid: string
): Promise<{ mods: ServerMod[]; gameVersion: string | null; loaders: string[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods`);

    return {
        mods: (data.mods.data || []).map(rawDataToServerMod),
        gameVersion: data.game_version,
        loaders: data.loaders || [],
    };
};

export type ModSort = 'relevance' | 'downloads' | 'updated';

export const searchMods = async (
    uuid: string,
    provider: ModProvider,
    query: string,
    offset = 0,
    sort: ModSort = 'relevance'
): Promise<{ hits: ModHit[]; total: number }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods/search`, {
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

export interface ModDependency {
    id: string;
    title: string;
    iconUrl: string | null;
    installed: boolean;
    required: boolean;
}

export interface ModVersion {
    id: string;
    versionNumber: string;
    fileName: string;
    gameVersions: string[];
    loaders: string[];
    dependencies: { projectId: string; required: boolean }[];
}

export const getModVersions = async (
    uuid: string,
    provider: ModProvider,
    projectId: string
): Promise<{ versions: ModVersion[]; dependencies: Record<string, Omit<ModDependency, 'required'>> }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods/versions`, {
        params: { provider, project_id: projectId },
    });

    const dependencies: Record<string, Omit<ModDependency, 'required'>> = {};
    Object.entries(data.dependencies || {}).forEach(([key, dep]: [string, any]) => {
        dependencies[key] = {
            id: dep.id,
            title: dep.title,
            iconUrl: dep.icon_url,
            installed: dep.installed,
        };
    });

    return {
        versions: (data.versions || []).map((v: any) => ({
            id: v.id,
            versionNumber: v.version_number,
            fileName: v.file_name,
            gameVersions: v.game_versions || [],
            loaders: v.loaders || [],
            dependencies: (v.dependencies || []).map((d: any) => ({
                projectId: d.project_id,
                required: d.required,
            })),
        })),
        dependencies,
    };
};

export const installMod = async (
    uuid: string,
    provider: ModProvider,
    projectId: string,
    title?: string,
    iconUrl?: string | null,
    versionId?: string,
    slug?: string,
    replace = false
): Promise<ServerMod> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/mods`, {
        provider,
        project_id: projectId,
        title,
        icon_url: iconUrl,
        version_id: versionId,
        slug,
        replace,
    });

    return rawDataToServerMod(data);
};

export const updateMod = async (uuid: string, modId: number): Promise<ServerMod> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/mods/${modId}/update`);

    return rawDataToServerMod(data);
};

export const linkMod = async (
    uuid: string,
    modId: number,
    provider: ModProvider,
    projectId: string,
    title: string,
    iconUrl: string | null,
    versionId: string,
    versionNumber: string,
    slug: string
): Promise<ServerMod> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/mods/${modId}/link`, {
        provider,
        project_id: projectId,
        title,
        icon_url: iconUrl,
        version_id: versionId,
        version_number: versionNumber,
        slug,
    });

    return rawDataToServerMod(data);
};

export const toggleMod = async (uuid: string, modId: number): Promise<ServerMod> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/mods/${modId}/toggle`);

    return rawDataToServerMod(data);
};

export const deleteMod = async (uuid: string, modId: number): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/mods/${modId}`);
};

export interface UntrackedJar {
    file_name: string;
    size: number;
    slug: string;
    title: string;
    version: string;
}

export const getUntrackedJars = async (uuid: string): Promise<UntrackedJar[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods/untracked`);

    return data.data || [];
};

export const registerJar = async (uuid: string, jar: UntrackedJar): Promise<ServerMod> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/mods/register`, {
        file_name: jar.file_name,
        title: jar.title,
        slug: jar.slug,
        version: jar.version,
    });

    return rawDataToServerMod(data);
};
