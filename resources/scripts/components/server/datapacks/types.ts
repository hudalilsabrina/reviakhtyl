import type { ServerDatapack, DatapackHit, DatapackProvider, UntrackedZip, DatapackSort } from '@/api/server/datapacks/datapacks';

export type { ServerDatapack, DatapackHit, DatapackProvider, UntrackedZip, DatapackSort };

export interface InstallProgress {
    title: string;
    step: number;
    version?: string;
}

export interface ReplaceConflict {
    provider: string;
    title: string;
    retry: () => void;
}
