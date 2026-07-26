import http from '@/api/http';

export interface SplitChild {
    id: number;
    uuid: string;
    identifier: string;
    name: string;
    cpu: number;
    memory: number;
    disk: number;
}

export interface SplitResources {
    cpu: number;
    memory: number;
    disk: number;
}

export interface SplitVariable {
    envVariable: string;
    name: string;
    description: string;
    value: string;
    editable: boolean;
}

export interface SplitDefaults {
    name: string;
    startup: string;
    image: string;
    dockerImages: string[];
    variables: SplitVariable[];
}

export interface SplitsState {
    splitLimit: number;
    canSplit: boolean;
    reason: 'child' | 'limit' | 'max' | null;
    used: number;
    remaining: SplitResources;
    total: SplitResources;
    defaults: SplitDefaults | null;
    children: SplitChild[];
}

export const rawDataToSplitsState = (data: any): SplitsState => ({
    splitLimit: data.split_limit ?? 0,
    canSplit: data.can_split ?? false,
    reason: data.reason ?? null,
    used: (data.children ?? []).length,
    remaining: {
        cpu: data.remaining?.cpu ?? 0,
        memory: data.remaining?.memory ?? 0,
        disk: data.remaining?.disk ?? 0,
    },
    total: {
        cpu: data.total?.cpu ?? data.remaining?.cpu ?? 0,
        memory: data.total?.memory ?? data.remaining?.memory ?? 0,
        disk: data.total?.disk ?? data.remaining?.disk ?? 0,
    },
    defaults: data.defaults
        ? {
              name: data.defaults.name ?? '',
              startup: data.defaults.startup ?? '',
              image: data.defaults.image ?? '',
              dockerImages: data.defaults.docker_images ?? [],
              variables: (data.defaults.variables ?? []).map(
                  (v: any): SplitVariable => ({
                      envVariable: v.env_variable,
                      name: v.name,
                      description: v.description ?? '',
                      value: v.value ?? '',
                      editable: !!v.editable,
                  })
              ),
          }
        : null,
    children: (data.children ?? []).map((child: any): SplitChild => {
        const attributes = child.attributes ?? child;

        return {
            id: attributes.id,
            uuid: attributes.uuid,
            identifier: attributes.identifier,
            name: attributes.name,
            cpu: attributes.cpu,
            memory: attributes.memory,
            disk: attributes.disk,
        };
    }),
});

export default async (uuid: string): Promise<SplitsState> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/splits`);

    return rawDataToSplitsState(data.data ?? data);
};
