import http from '@/api/http';

export interface PlayerEntry {
    uuid: string;
    name: string;
    level?: number;
    reason?: string;
}

export interface PlayerStatus {
    online: boolean;
    whitelist: PlayerEntry[];
    ops: PlayerEntry[];
    bans: PlayerEntry[];
}

export const rawDataToPlayerEntry = (attributes: any): PlayerEntry => ({
    uuid: attributes.uuid || '',
    name: attributes.name || '',
    level: attributes.level,
    reason: attributes.reason,
});

export const getPlayerStatus = async (uuid: string): Promise<PlayerStatus> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/players`);

    return {
        online: data.online,
        whitelist: (data.whitelist || []).map(rawDataToPlayerEntry),
        ops: (data.ops || []).map(rawDataToPlayerEntry),
        bans: (data.bans || []).map(rawDataToPlayerEntry),
    };
};

export const getOnlinePlayers = async (uuid: string): Promise<{ online: boolean; players: string[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/players/online`);

    return {
        online: data.online,
        players: data.players || [],
    };
};

export const whitelistAdd = async (uuid: string, name: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/players/whitelist`, { name });
};

export const whitelistRemove = async (uuid: string, name: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/players/whitelist/${name}`);
};

export const opPlayer = async (uuid: string, name: string, level = 4): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/players/ops`, { name, level });
};

export const deopPlayer = async (uuid: string, name: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/players/ops/${name}`);
};

export const banPlayer = async (uuid: string, name: string, reason?: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/players/bans`, { name, reason });
};

export const unbanPlayer = async (uuid: string, name: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/players/bans/${name}`);
};
