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

export interface SplitsState {
    splitLimit: number;
    canSplit: boolean;
    used: number;
    remaining: SplitResources;
    children: SplitChild[];
}

export const rawDataToSplitsState = (data: any): SplitsState => ({
    splitLimit: data.split_limit ?? 0,
    canSplit: data.can_split ?? false,
    used: (data.children ?? []).length,
    remaining: {
        cpu: data.remaining?.cpu ?? 0,
        memory: data.remaining?.memory ?? 0,
        disk: data.remaining?.disk ?? 0,
    },
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
