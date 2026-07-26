import http from '@/api/http';

export type PropertyType = 'bool' | 'int' | 'string' | 'enum';

export interface PropertyDefinition {
    key: string;
    type: PropertyType;
    default: string;
    options: string[] | null;
    min: number | null;
    max: number | null;
    // Panel managed, rendered read-only and ignored on save.
    locked: boolean;
    sensitive: boolean;
    // Worth warning about before it is changed on a live world.
    warn: boolean;
    label: string;
    description: string | null;
}

export interface PropertyGroup {
    id: string;
    label: string;
    properties: PropertyDefinition[];
}

export interface ServerProperties {
    exists: boolean;
    eulaAccepted: boolean;
    raw: string;
    values: Record<string, string>;
    groups: PropertyGroup[];
}

const rawDataToServerProperties = (data: any): ServerProperties => ({
    exists: data.exists,
    eulaAccepted: data.eula_accepted,
    raw: data.raw,
    values: data.values || {},
    groups: data.groups || [],
});

export const getProperties = async (uuid: string): Promise<ServerProperties> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/properties`);

    return rawDataToServerProperties(data);
};

export const updateProperties = async (uuid: string, properties: Record<string, string>): Promise<ServerProperties> => {
    const { data } = await http.put(`/api/client/servers/${uuid}/properties`, { properties });

    return rawDataToServerProperties(data);
};

export const updateRawProperties = async (uuid: string, content: string): Promise<ServerProperties> => {
    const { data } = await http.put(`/api/client/servers/${uuid}/properties/raw`, { content });

    return rawDataToServerProperties(data);
};

export const acceptEula = async (uuid: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/properties/eula`);
};
