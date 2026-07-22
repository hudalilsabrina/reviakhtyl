import http from '@/api/http';

export type PluginProvider = 'modrinth' | 'hangar' | 'spiget';

export interface ServerPlugin {
    id: number;
    provider: PluginProvider;
    projectId: string;
    title: string;
    versionId: string;
    versionNumber: string;
    fileName: string;
    iconUrl: string | null;
    disabled: boolean;
}

export interface PluginHit {
    id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    iconUrl: string | null;
    downloads: number;
    installedVersion: string | null;
}

export const rawDataToServerPlugin = ({ attributes }: any): ServerPlugin => ({
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

export const getServerPlugins = async (
    uuid: string
): Promise<{ plugins: ServerPlugin[]; gameVersion: string | null; loaders: string[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/plugins`);

    return {
        plugins: (data.plugins.data || []).map(rawDataToServerPlugin),
        gameVersion: data.game_version,
        loaders: data.loaders || [],
    };
};

export type PluginSort = 'relevance' | 'downloads' | 'updated';

export const searchPlugins = async (
    uuid: string,
    provider: PluginProvider,
    query: string,
    offset = 0,
    sort: PluginSort = 'relevance'
): Promise<{ hits: PluginHit[]; total: number }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/plugins/search`, {
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

export interface PluginDependency {
    id: string;
    title: string;
    iconUrl: string | null;
    installed: boolean;
    required: boolean;
}

export interface PluginVersion {
    id: string;
    versionNumber: string;
    fileName: string;
    gameVersions: string[];
    loaders: string[];
    dependencies: { projectId: string; required: boolean }[];
}

export const getPluginVersions = async (
    uuid: string,
    provider: PluginProvider,
    projectId: string
): Promise<{ versions: PluginVersion[]; dependencies: Record<string, Omit<PluginDependency, 'required'>> }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/plugins/versions`, {
        params: { provider, project_id: projectId },
    });

    const dependencies: Record<string, Omit<PluginDependency, 'required'>> = {};
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

export const installPlugin = async (
    uuid: string,
    provider: PluginProvider,
    projectId: string,
    title?: string,
    iconUrl?: string | null,
    versionId?: string
): Promise<ServerPlugin> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/plugins`, {
        provider,
        project_id: projectId,
        title,
        icon_url: iconUrl,
        version_id: versionId,
    });

    return rawDataToServerPlugin(data);
};

export const updatePlugin = async (uuid: string, pluginId: number): Promise<ServerPlugin> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/plugins/${pluginId}/update`);

    return rawDataToServerPlugin(data);
};

export const togglePlugin = async (uuid: string, pluginId: number): Promise<ServerPlugin> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/plugins/${pluginId}/toggle`);

    return rawDataToServerPlugin(data);
};

export const deletePlugin = async (uuid: string, pluginId: number): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/plugins/${pluginId}`);
};
