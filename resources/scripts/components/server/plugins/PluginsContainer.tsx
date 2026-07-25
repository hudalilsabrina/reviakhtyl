import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import { css } from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import {
    deletePlugin,
    getPluginVersions,
    getServerPlugins,
    installPlugin,
    linkPlugin,
    searchPlugins,
    togglePlugin,
    updatePlugin,
    getUntrackedJars,
    registerJar,
} from '@/api/server/plugins/plugins';
import { InstallProgressModal } from './InstallProgressModal';
import { VersionPickerModal } from './VersionPickerModal';
import { InstalledTab } from './InstalledTab';
import { BrowseTab } from './BrowseTab';
import {
    ServerPlugin,
    PluginHit,
    PluginProvider,
    PluginSort,
    PluginVersion,
    PluginDependency,
    UntrackedJar,
    InstallProgress,
    ReplaceConflict,
} from './types';

type MissingDep = { projectId: string; required: boolean; info?: Omit<PluginDependency, 'required'> };

const PluginsContainer = () => {
    const { t } = useTranslation('server/plugins');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [tab, setTab] = useState<'installed' | 'browse'>('browse');
    const [loading, setLoading] = useState(true);
    const [plugins, setPlugins] = useState<ServerPlugin[]>([]);
    const [gameVersion, setGameVersion] = useState<string | null>(null);
    const [loaders, setLoaders] = useState<string[]>([]);

    const [provider, setProvider] = useState<PluginProvider>('modrinth');
    const [sort, setSort] = useState<PluginSort>('relevance');
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<PluginHit[]>([]);
    const [total, setTotal] = useState(0);
    const [searching, setSearching] = useState(false);

    const [busy, setBusy] = useState<string | null>(null);
    const [installing, setInstalling] = useState<InstallProgress | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<ServerPlugin | null>(null);
    const [versionsFor, setVersionsFor] = useState<PluginHit | null>(null);
    const [linkingPlugin, setLinkingPlugin] = useState<ServerPlugin | null>(null);
    const [versions, setVersions] = useState<PluginVersion[] | null>(null);
    const [installedRow, setInstalledRow] = useState<string | null>(null);
    const [dependencies, setDependencies] = useState<Record<string, Omit<PluginDependency, 'required'>>>({});
    const [untracked, setUntracked] = useState<UntrackedJar[]>([]);
    const [trackJar, setTrackJar] = useState<UntrackedJar | null>(null);
    const [replaceConflict, setReplaceConflict] = useState<ReplaceConflict | null>(null);
    const searchId = useRef(0);

    useEffect(() => {
        clearFlashes('server:plugins');
        getServerPlugins(uuid)
            .then((data) => {
                setPlugins(data.plugins);
                setGameVersion(data.gameVersion);
                setLoaders(data.loaders);
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    }, []);

    const doSearch = (offset = 0, term = query) => {
        const id = ++searchId.current;
        setSearching(true);
        searchPlugins(uuid, provider, term, offset, sort)
            .then((data) => {
                if (id !== searchId.current) return;
                setHits(offset === 0 ? data.hits : (prev) => [...prev, ...data.hits]);
                setTotal(data.total);
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => id === searchId.current && setSearching(false));
    };

    useEffect(() => {
        if (tab !== 'browse') return;
        setHits([]);
        doSearch(0);
    }, [tab, provider, sort]);

    useEffect(() => {
        if (tab !== 'browse') return;
        const timer = setTimeout(() => doSearch(0), 350);

        return () => clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        if (tab !== 'installed') return;
        getUntrackedJars(uuid)
            .then(setUntracked)
            .catch(() => setUntracked([]));
    }, [tab]);

    const track = (jar: UntrackedJar) => {
        setBusy(`track:${jar.file_name}`);
        clearFlashes('server:plugins');
        registerJar(uuid, jar)
            .then((plugin) => {
                setPlugins((prev) => [...prev, plugin]);
                setUntracked((prev) => prev.filter((j) => j.file_name !== jar.file_name));
                setTrackJar(null);
                addFlash({
                    type: 'success',
                    key: 'server:plugins',
                    message: t('track_success', { title: plugin.title }) ?? '',
                });
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    const handleInstallError = (error: any, retry: () => void) => {
        const err = error?.response?.data?.errors?.[0];
        if (error?.response?.status === 409 && err?.code === 'CrossProviderDuplicate') {
            setInstalling(null);
            setBusy(null);
            setReplaceConflict({ provider: err.meta.provider, title: err.meta.title, retry });

            return true;
        }

        return false;
    };

    const doInstall = (hit: PluginHit, versionId?: string, step = 0, replace = false) => {
        if (linkingPlugin) {
            setBusy(`link:${linkingPlugin.id}`);
            clearFlashes('server:plugins');
            getPluginVersions(uuid, provider, hit.id)
                .then(({ versions: vs }) => {
                    const version = versionId ? vs.find((v) => v.id === versionId) : vs[0];
                    if (!version) {
                        addError({ key: 'server:plugins', message: 'No compatible version found.' });
                        return;
                    }
                    linkPlugin(
                        uuid,
                        linkingPlugin.id,
                        provider,
                        hit.id,
                        hit.title,
                        hit.iconUrl,
                        version.id,
                        version.versionNumber,
                        hit.slug
                    )
                        .then((plugin) => {
                            setPlugins((prev) => prev.map((p) => (p.id === plugin.id ? plugin : p)));
                            setLinkingPlugin(null);
                            addFlash({
                                type: 'success',
                                key: 'server:plugins',
                                message: t('link_success', { title: plugin.title }) ?? `Linked ${plugin.title}`,
                            });
                        })
                        .catch((error) => {
                            addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                        })
                        .finally(() => setBusy(null));
                })
                .catch((error) => {
                    addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                    setBusy(null);
                });
            return;
        }

        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: versionId ? Math.max(step, 1) : step });
        clearFlashes('server:plugins');
        installPlugin(uuid, provider, hit.id, hit.title, hit.iconUrl, versionId, hit.slug, replace)
            .then((plugin) => {
                if (versionId) setInstalledRow(versionId);
                setInstalling({ title: hit.title, step: 3, version: plugin.versionNumber });
                setPlugins((prev) => [...prev.filter((p) => p.id !== plugin.id), plugin]);
                setHits((prev) =>
                    prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: plugin.versionNumber } : h))
                );
                addFlash({
                    type: 'success',
                    key: 'server:plugins',
                    message: t('install_success', { title: plugin.title, version: plugin.versionNumber }) ?? '',
                });
                setTimeout(() => setInstalling(null), 1600);
            })
            .catch((error) => {
                if (!handleInstallError(error, () => doInstall(hit, versionId, step, true))) {
                    addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                    setInstalling(null);
                }
            })
            .finally(() => setBusy(null));
    };

    const install = (hit: PluginHit) => {
        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: 0 });
        clearFlashes('server:plugins');
        getPluginVersions(uuid, provider, hit.id)
            .then((data) => {
                const latest = data.versions[0];
                const missing = (latest?.dependencies ?? [])
                    .filter((d) => d.required)
                    .filter((d) => {
                        const info = data.dependencies[d.projectId];

                        return info && !info.installed;
                    });
                const chain = missing.reduce<Promise<void>>(
                    (prev, dep) =>
                        prev.then(() => {
                            const info = data.dependencies[dep.projectId]!;

                            return installPlugin(uuid, provider, info.id, info.title, info.iconUrl).then((p) => {
                                setPlugins((existing) => [...existing.filter((x) => x.id !== p.id), p]);
                            });
                        }),
                    Promise.resolve()
                );

                setInstalling({ title: hit.title, step: 1 });
                chain
                    .then(() => doInstall(hit, latest?.id, 1))
                    .catch((error) => {
                        addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                        setInstalling(null);
                        setBusy(null);
                    });
            })
            .catch((error) => {
                addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                setInstalling(null);
                setBusy(null);
            });
    };

    const installDep = (dep: MissingDep) => {
        if (!dep.info) return;
        const key = `dep:${dep.projectId}`;
        setBusy(key);
        setInstalling({ title: dep.info.title, step: 0 });
        clearFlashes('server:plugins');
        installPlugin(uuid, provider, dep.info.id, dep.info.title, dep.info.iconUrl)
            .then((p) => {
                setInstalling({ title: p.title, step: 3, version: p.versionNumber });
                setPlugins((prev) => [...prev.filter((x) => x.id !== p.id), p]);
                setDependencies((prev) => {
                    const entry = prev[dep.projectId];

                    return entry ? { ...prev, [dep.projectId]: { ...entry, installed: true } } : prev;
                });
                addFlash({
                    type: 'success',
                    key: 'server:plugins',
                    message: t('install_success', { title: p.title, version: p.versionNumber }) ?? '',
                });
                setTimeout(() => setInstalling(null), 1600);
            })
            .catch((error) => {
                addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                setInstalling(null);
            })
            .finally(() => setBusy(null));
    };

    const installWithDeps = (hit: PluginHit, version: PluginVersion, missing: MissingDep[], replace = false) => {
        const toInstall = missing.filter((d) => d.required);
        const chain = toInstall.reduce<Promise<void>>(
            (prev, dep) =>
                prev.then(() =>
                    installPlugin(uuid, provider, dep.info!.id, dep.info!.title, dep.info!.iconUrl).then((p) => {
                        setPlugins((existing) => [...existing.filter((x) => x.id !== p.id), p]);
                    })
                ),
            Promise.resolve()
        );

        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: 1 });
        clearFlashes('server:plugins');
        chain
            .then(() => installPlugin(uuid, provider, hit.id, hit.title, hit.iconUrl, version.id, hit.slug, replace))
            .then((plugin) => {
                setInstalledRow(version.id);
                setInstalling({ title: hit.title, step: 3, version: plugin.versionNumber });
                setPlugins((prev) => [...prev.filter((p) => p.id !== plugin.id), plugin]);
                setHits((prev) =>
                    prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: plugin.versionNumber } : h))
                );
                setDependencies((prev) => {
                    const next = { ...prev };
                    toInstall.forEach((d) => {
                        const entry = next[d.projectId];
                        if (entry) next[d.projectId] = { ...entry, installed: true };
                    });

                    return next;
                });
                addFlash({
                    type: 'success',
                    key: 'server:plugins',
                    message: t('install_success', { title: plugin.title, version: plugin.versionNumber }) ?? '',
                });
                setTimeout(() => setInstalling(null), 1600);
            })
            .catch((error) => {
                if (!handleInstallError(error, () => installWithDeps(hit, version, missing, true))) {
                    addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                    setInstalling(null);
                }
            })
            .finally(() => setBusy(null));
    };

    const openVersions = (hit: PluginHit) => {
        setVersionsFor(hit);
        setVersions(null);
        setInstalledRow(null);
        setDependencies({});
        getPluginVersions(uuid, provider, hit.id)
            .then((data) => {
                setVersions(data.versions);
                setDependencies(data.dependencies);
            })
            .catch((error) => {
                addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                setVersionsFor(null);
            });
    };

    const runUpdate = (plugin: ServerPlugin) => {
        setBusy(`update:${plugin.id}`);
        setInstalling({ title: plugin.title, step: 0 });
        clearFlashes('server:plugins');
        getPluginVersions(uuid, plugin.provider, plugin.projectId)
            .then(({ versions: vs }) => {
                if (!vs[0] || vs[0].id === plugin.versionId) {
                    addError({ key: 'server:plugins', message: t('up_to_date') ?? '' });
                    setInstalling(null);
                    setBusy(null);
                    return;
                }
                setInstalling({ title: plugin.title, step: 1 });
                updatePlugin(uuid, plugin.id)
                    .then((p) => {
                        setInstalling({ title: plugin.title, step: 3, version: p.versionNumber });
                        setPlugins((prev) => prev.map((x) => (x.id === p.id ? p : x)));
                        addFlash({
                            type: 'success',
                            key: 'server:plugins',
                            message: t('update_success', { title: p.title, version: p.versionNumber }) ?? '',
                        });
                        setTimeout(() => setInstalling(null), 1600);
                    })
                    .catch((error) => {
                        addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                        setInstalling(null);
                    })
                    .finally(() => setBusy(null));
            })
            .catch((error) => {
                addError({ key: 'server:plugins', message: httpErrorToHuman(error) });
                setInstalling(null);
                setBusy(null);
            });
    };

    const remove = (plugin: ServerPlugin) => {
        setBusy(`delete:${plugin.id}`);
        clearFlashes('server:plugins');
        deletePlugin(uuid, plugin.id)
            .then(() => setPlugins((prev) => prev.filter((p) => p.id !== plugin.id)))
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => {
                setBusy(null);
                setConfirmRemove(null);
            });
    };

    const handleToggle = (plugin: ServerPlugin) => {
        setBusy(`toggle:${plugin.id}`);
        clearFlashes('server:plugins');
        togglePlugin(uuid, plugin.id)
            .then((p) => setPlugins((prev) => prev.map((x) => (x.id === p.id ? p : x))))
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    const openLinkSearch = (plugin: ServerPlugin) => {
        setLinkingPlugin(plugin);
        setTab('browse');
        setQuery(plugin.title);
    };

    const tabButtonCss = (active: boolean) => css`
        ${tw`px-4 py-2 text-sm font-semibold rounded-ui transition-colors duration-150 border-b-2 -mb-px rounded-b-none`}
        ${active
            ? tw`text-gray-100 border-reviactyl bg-gray-800/60`
            : tw`text-gray-400 border-transparent hover:text-gray-200 hover:bg-gray-800/30`}
    `;

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:plugins'} css={tw`mb-4`} />

            <InstallProgressModal installing={installing} onDismissed={() => setInstalling(null)} />

            <ConfirmationModal
                visible={!!confirmRemove}
                title={t('remove')}
                buttonText={t('remove')}
                onConfirmed={() => confirmRemove && remove(confirmRemove)}
                showSpinnerOverlay={!!busy}
                onModalDismissed={() => setConfirmRemove(null)}
            >
                {confirmRemove && t('confirm_remove', { plugin: confirmRemove.title })}
            </ConfirmationModal>

            <ConfirmationModal
                visible={!!trackJar}
                title={t('track')}
                buttonText={t('track')}
                onConfirmed={() => trackJar && track(trackJar)}
                showSpinnerOverlay={!!busy}
                onModalDismissed={() => setTrackJar(null)}
            >
                {trackJar && t('track_confirm', { title: trackJar.title, version: trackJar.version })}
            </ConfirmationModal>

            <ConfirmationModal
                visible={!!replaceConflict}
                title={t('replace_title')}
                buttonText={t('replace_confirm')}
                onConfirmed={() => {
                    replaceConflict?.retry();
                    setReplaceConflict(null);
                }}
                onModalDismissed={() => setReplaceConflict(null)}
            >
                {replaceConflict &&
                    t('replace_body', { title: replaceConflict.title, provider: replaceConflict.provider })}
            </ConfirmationModal>

            <VersionPickerModal
                hit={versionsFor}
                versions={versions}
                dependencies={dependencies}
                installedRow={installedRow}
                busy={busy}
                onInstall={installWithDeps}
                onInstallDep={installDep}
                onDismiss={() => setVersionsFor(null)}
            />

            <div css={tw`flex items-end justify-between border-b border-gray-700 mb-4 flex-wrap gap-2`}>
                <div css={tw`flex`}>
                    <button css={tabButtonCss(tab === 'browse')} onClick={() => setTab('browse')}>
                        {t('browse')}
                    </button>
                    <button css={tabButtonCss(tab === 'installed')} onClick={() => setTab('installed')}>
                        {t('installed')} ({plugins.length})
                    </button>
                </div>
                {gameVersion && (
                    <span css={tw`text-xs text-gray-400 pb-2`}>
                        {t('detected', { version: gameVersion, loader: loaders[0] ?? '' })}
                    </span>
                )}
            </div>
            <p css={tw`text-xs text-gray-500 mb-4`}>{t('restart_notice')}</p>

            {loading ? (
                <Spinner centered />
            ) : tab === 'installed' ? (
                <InstalledTab
                    plugins={plugins}
                    untracked={untracked}
                    busy={busy}
                    onUpdate={runUpdate}
                    onToggle={handleToggle}
                    onRemove={setConfirmRemove}
                    onLink={openLinkSearch}
                    onTrack={setTrackJar}
                />
            ) : (
                <BrowseTab
                    provider={provider}
                    sort={sort}
                    query={query}
                    hits={hits}
                    total={total}
                    searching={searching}
                    busy={busy}
                    linkingPlugin={linkingPlugin}
                    onProviderChange={setProvider}
                    onSortChange={setSort}
                    onQueryChange={setQuery}
                    onSearch={() => doSearch(0)}
                    onLoadMore={() => doSearch(hits.length)}
                    onInstall={install}
                    onOpenVersions={openVersions}
                    onCancelLink={() => setLinkingPlugin(null)}
                />
            )}
        </ServerContentBlock>
    );
};

export default PluginsContainer;
