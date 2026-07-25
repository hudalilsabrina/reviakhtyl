import {
    PluginDependency,
    PluginHit,
    PluginProvider,
    PluginSort,
    PluginVersion,
    ServerPlugin,
    UntrackedJar,
} from '@/api/server/plugins/plugins';

export type { PluginDependency, PluginHit, PluginProvider, PluginSort, PluginVersion, ServerPlugin, UntrackedJar };

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
