import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled, { css } from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaCheck, FaCircle, FaDownload, FaListUl, FaMagnifyingGlass, FaPuzzlePiece, FaTrash } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Select from '@/reviactyl/elements/Select';
import { Button } from '@/reviactyl/elements/button/index';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import Modal from '@/reviactyl/elements/Modal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import {
    deletePlugin,
    getPluginVersions,
    getServerPlugins,
    installPlugin,
    PluginDependency,
    PluginHit,
    PluginProvider,
    PluginSort,
    PluginVersion,
    searchPlugins,
    ServerPlugin,
    togglePlugin,
    updatePlugin,
    getUntrackedJars,
    registerJar,
    UntrackedJar,
} from '@/api/server/plugins/plugins';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

const Badge = styled.span<{ $variant: 'provider' | 'disabled' | 'installed' | 'manual' }>`
    ${tw`uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded`}
    font-size: 10px;

    ${(props) =>
        props.$variant === 'manual' &&
        css`
            ${tw`bg-blue-600/30 text-blue-200`};
        `}
    ${(props) =>
        props.$variant === 'provider' &&
        css`
            ${tw`bg-gray-700/70 text-gray-300`};
        `}
    ${(props) =>
        props.$variant === 'disabled' &&
        css`
            ${tw`bg-yellow-600/30 text-yellow-200`};
        `}
    ${(props) =>
        props.$variant === 'installed' &&
        css`
            background-color: rgba(34, 197, 94, 0.15);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.4);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
        `}
`;

const ProgressBar = styled.div`
    height: 4px;
    border-radius: 9999px;
    background-color: rgba(255, 255, 255, 0.08);
    overflow: hidden;

    & > div {
        height: 100%;
        border-radius: 9999px;
        background-color: #4ade80;
        transition: width 600ms cubic-bezier(0.4, 0, 0.2, 1);
    }
`;

// Timed fake-ish progress: eases toward 90%, jumps to 100% on finish.
const useProgress = (active: boolean) => {
    const [width, setWidth] = useState(0);

    useEffect(() => {
        if (!active) {
            setWidth(0);
            return;
        }
        setWidth(10);
        const timer = setInterval(() => setWidth((w) => (w >= 90 ? w : w + (90 - w) * 0.08 + 1)), 400);

        return () => clearInterval(timer);
    }, [active]);

    return width;
};

const PluginIcon = ({ url }: { url: string | null }) =>
    url ? (
        <img src={url} alt={''} css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui object-cover flex-shrink-0`} />
    ) : (
        <div
            css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui bg-gray-800 border border-gray-700 flex items-center justify-center flex-shrink-0`}
        >
            <FaPuzzlePiece css={tw`text-gray-500 text-lg`} />
        </div>
    );

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
    const [installing, setInstalling] = useState<{ title: string; step: number; version?: string } | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<ServerPlugin | null>(null);
    const [versionsFor, setVersionsFor] = useState<PluginHit | null>(null);
    const [versions, setVersions] = useState<PluginVersion[] | null>(null);
    const [installedRow, setInstalledRow] = useState<string | null>(null);
    const [dependencies, setDependencies] = useState<Record<string, Omit<PluginDependency, 'required'>>>({});
    const [untracked, setUntracked] = useState<UntrackedJar[]>([]);
    const [trackJar, setTrackJar] = useState<UntrackedJar | null>(null);
    const searchId = useRef(0);
    const progressWidth = useProgress(!!installing && installing.step < 3);

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

    // Debounced live search while typing.
    useEffect(() => {
        if (tab !== 'browse') return;
        const timer = setTimeout(() => doSearch(0), 350);

        return () => clearTimeout(timer);
    }, [query]);

    // Scan for manually-uploaded jars when opening the Installed tab.
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

    // On 409 (same plugin installed from another provider) ask the user to confirm replacement.
    const [replaceConflict, setReplaceConflict] = useState<{
        provider: string;
        title: string;
        retry: () => void;
    } | null>(null);

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

    // Quick install from a card: resolve the latest version, silently pull in any
    // missing REQUIRED dependencies, then install — no picker detour.
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

    type MissingDep = { projectId: string; required: boolean; info?: Omit<PluginDependency, 'required'> };

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
        // Install only missing REQUIRED dependencies, then the plugin itself.
        const toInstall = missing.filter((d) => d.required);
        // ponytail: optional deps are shown as chips but never auto-installed.
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
                // Keep the version picker open; mark installed deps as such.
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

    const mutate = (key: string, action: Promise<ServerPlugin>) => {
        setBusy(key);
        clearFlashes('server:plugins');
        action
            .then((plugin) => setPlugins((prev) => prev.map((p) => (p.id === plugin.id ? plugin : p))))
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    const runUpdate = (plugin: ServerPlugin) => {
        setBusy(`update:${plugin.id}`);
        setInstalling({ title: plugin.title, step: 0 });
        clearFlashes('server:plugins');
        // Phase 1: resolve latest compatible version, then phase 2: download.
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

    const tabButtonCss = (active: boolean) => css`
        ${tw`px-4 py-2 text-sm font-semibold rounded-ui transition-colors duration-150 border-b-2 -mb-px rounded-b-none`}
        ${active
            ? tw`text-gray-100 border-reviactyl bg-gray-800/60`
            : tw`text-gray-400 border-transparent hover:text-gray-200 hover:bg-gray-800/30`}
    `;

    const installSteps = [t('step_resolve'), t('step_download'), t('step_finish')];

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:plugins'} css={tw`mb-4`} />

            <Modal visible={!!installing} onDismissed={() => setInstalling(null)} dismissable={false} size={'sm'}>
                {installing && (
                    <>
                        <h2 css={tw`text-lg sm:text-xl font-semibold mb-1 truncate`}>
                            {installing.step >= 3
                                ? t('install_done', { title: installing.title })
                                : t('installing_title', { title: installing.title })}
                        </h2>
                        <div css={tw`space-y-2.5 my-4`}>
                            {installSteps.map((label, i) => {
                                const done = installing.step > i;
                                const current = installing.step === i;
                                const isLast = i === installSteps.length - 1;
                                return (
                                    <div key={label} css={tw`flex items-center gap-2.5 text-sm`}>
                                        {done ? (
                                            <FaCheck style={{ color: '#4ade80', fontSize: '12px', flexShrink: 0 }} />
                                        ) : current ? (
                                            <Spinner size={'small'} />
                                        ) : (
                                            <FaCircle style={{ color: '#374151', fontSize: '8px', flexShrink: 0 }} />
                                        )}
                                        <span
                                            css={done || current ? tw`text-gray-200` : tw`text-gray-500`}
                                            style={done ? { color: '#9ca3af' } : undefined}
                                        >
                                            {isLast && done && installing.version
                                                ? t('step_finish_done', { version: installing.version })
                                                : label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                        <ProgressBar>
                            <div style={{ width: `${installing.step >= 3 ? 100 : progressWidth}%` }} />
                        </ProgressBar>
                    </>
                )}
            </Modal>
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

            <Modal visible={!!versionsFor} onDismissed={() => setVersionsFor(null)} size={'lg'}>
                {versionsFor && (
                    <>
                        <h2 css={tw`text-xl sm:text-2xl mb-1 truncate`}>{versionsFor.title}</h2>
                        <p css={tw`text-sm text-gray-400 mb-4 sm:mb-6`}>{t('pick_version')}</p>
                        {!versions ? (
                            <Spinner centered />
                        ) : versions.length === 0 ? (
                            <p css={tw`text-sm text-gray-500 text-center py-6`}>{t('no_results')}</p>
                        ) : (
                            <div css={tw`overflow-y-auto max-h-96 divide-y divide-gray-800`}>
                                {versions.map((version) => {
                                    const missing = version.dependencies
                                        .map((d) => ({ ...d, info: dependencies[d.projectId] }))
                                        .filter((d) => d.info && !d.info.installed);
                                    const requiredCount = missing.filter((d) => d.required).length;

                                    return (
                                        <div key={version.id} css={tw`py-2.5`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <div css={tw`flex-1 min-w-0`}>
                                                    <p css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                        {version.versionNumber}
                                                    </p>
                                                    <p css={tw`text-xs text-gray-500 truncate`}>
                                                        {version.gameVersions.length > 0 &&
                                                            version.gameVersions.join(', ')}
                                                        {version.gameVersions.length > 0 &&
                                                            version.loaders.length > 0 &&
                                                            ' · '}
                                                        {version.loaders.join(', ')}
                                                    </p>
                                                </div>
                                                {installedRow === version.id ? (
                                                    <Badge $variant={'installed'}>
                                                        <FaCheck style={{ fontSize: '9px' }} />
                                                        <span css={tw`font-mono`}>{version.versionNumber}</span>
                                                    </Badge>
                                                ) : (
                                                    <Button.Success
                                                        size={Button.Sizes.Small}
                                                        disabled={!!busy}
                                                        onClick={() => installWithDeps(versionsFor, version, missing)}
                                                    >
                                                        {busy === `install:${versionsFor.id}` ? (
                                                            <Spinner size={'small'} />
                                                        ) : requiredCount > 0 ? (
                                                            t('install_with_deps', { count: requiredCount })
                                                        ) : (
                                                            t('install')
                                                        )}
                                                    </Button.Success>
                                                )}
                                            </div>
                                            {missing.length > 0 && (
                                                <div css={tw`mt-1.5 flex flex-wrap gap-1.5`}>
                                                    {missing.map((d) => {
                                                        const chip = (
                                                            <>
                                                                {d.info!.iconUrl ? (
                                                                    <img
                                                                        src={d.info!.iconUrl}
                                                                        alt={''}
                                                                        css={tw`w-3.5 h-3.5 rounded-sm`}
                                                                    />
                                                                ) : (
                                                                    <FaPuzzlePiece style={{ fontSize: '10px' }} />
                                                                )}
                                                                {d.info!.title}
                                                                <span
                                                                    style={{
                                                                        color: d.required ? '#fbbf24' : '#6b7280',
                                                                        fontSize: '10px',
                                                                    }}
                                                                >
                                                                    {d.required ? t('dep_required') : t('dep_optional')}
                                                                </span>
                                                            </>
                                                        );

                                                        return d.required ? (
                                                            <span
                                                                key={d.projectId}
                                                                css={tw`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-gray-800 border border-gray-700 text-gray-300`}
                                                            >
                                                                {chip}
                                                            </span>
                                                        ) : (
                                                            <button
                                                                key={d.projectId}
                                                                type={'button'}
                                                                title={t('dep_install_optional') ?? ''}
                                                                disabled={!!busy}
                                                                onClick={() => installDep(d)}
                                                                css={tw`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-gray-800 border border-gray-700 text-gray-300 hover:border-reviactyl hover:text-gray-100 transition-colors disabled:opacity-50`}
                                                            >
                                                                {busy === `dep:${d.projectId}` ? (
                                                                    <Spinner size={'small'} />
                                                                ) : (
                                                                    chip
                                                                )}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                )}
            </Modal>

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
                <>
                    {untracked.length > 0 && (
                        <div css={tw`mb-4`}>
                            <p css={tw`text-xs text-gray-400 mb-2`}>{t('untracked_title')}</p>
                            <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                                {untracked.map((jar) => (
                                    <Card key={jar.file_name}>
                                        <PluginIcon url={null} />
                                        <div css={tw`flex-1 min-w-0`}>
                                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                                <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                    {jar.title}
                                                </h3>
                                                <Badge $variant={'manual'}>{t('manual_badge')}</Badge>
                                            </div>
                                            <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>
                                                {jar.file_name}
                                            </p>
                                            <div css={tw`flex gap-2 mt-3`}>
                                                <Button
                                                    size={Button.Sizes.Small}
                                                    variant={Button.Variants.Secondary}
                                                    disabled={!!busy}
                                                    onClick={() => setTrackJar(jar)}
                                                >
                                                    {busy === `track:${jar.file_name}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        t('track')
                                                    )}
                                                </Button>
                                            </div>
                                        </div>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    )}
                    {plugins.length === 0 ? (
                        <div css={tw`text-center py-16 text-gray-500`}>
                            <FaPuzzlePiece css={tw`mx-auto text-3xl mb-3 text-gray-600`} />
                            <p css={tw`text-sm`}>{t('no_plugins')}</p>
                        </div>
                    ) : (
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                            {plugins.map((plugin) => (
                                <Card key={plugin.id}>
                                    <PluginIcon url={plugin.iconUrl} />
                                    <div css={tw`flex-1 min-w-0`}>
                                        <div css={tw`flex items-center gap-2 flex-wrap`}>
                                            <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                {plugin.title}
                                            </h3>
                                            <Badge $variant={plugin.provider === 'manual' ? 'manual' : 'provider'}>
                                                {plugin.provider === 'manual' ? t('manual_badge') : plugin.provider}
                                            </Badge>
                                            {plugin.disabled && (
                                                <Badge $variant={'disabled'}>{t('disabled_badge')}</Badge>
                                            )}
                                        </div>
                                        <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>
                                            {plugin.fileName}
                                        </p>
                                        <p css={tw`text-xs text-gray-500 mt-1 flex items-center gap-1.5`}>
                                            <Badge $variant={'installed'}>
                                                <FaCheck style={{ fontSize: '9px' }} />
                                                <span css={tw`font-mono`}>{plugin.versionNumber}</span>
                                            </Badge>
                                        </p>
                                        <div css={tw`flex gap-2 mt-3 flex-wrap`}>
                                            {!plugin.disabled && plugin.provider !== 'manual' && (
                                                <Button
                                                    size={Button.Sizes.Small}
                                                    variant={Button.Variants.Secondary}
                                                    disabled={!!busy}
                                                    onClick={() => runUpdate(plugin)}
                                                >
                                                    {busy === `update:${plugin.id}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        t('update')
                                                    )}
                                                </Button>
                                            )}
                                            <Button
                                                size={Button.Sizes.Small}
                                                variant={Button.Variants.Secondary}
                                                disabled={!!busy}
                                                onClick={() =>
                                                    mutate(`toggle:${plugin.id}`, togglePlugin(uuid, plugin.id))
                                                }
                                            >
                                                {busy === `toggle:${plugin.id}` ? (
                                                    <Spinner size={'small'} />
                                                ) : plugin.disabled ? (
                                                    t('enable')
                                                ) : (
                                                    t('disable')
                                                )}
                                            </Button>
                                            <Button.Danger
                                                size={Button.Sizes.Small}
                                                variant={Button.Variants.Secondary}
                                                disabled={!!busy}
                                                onClick={() => setConfirmRemove(plugin)}
                                            >
                                                <FaTrash css={tw`inline mr-1 -mt-0.5`} />
                                                {t('remove')}
                                            </Button.Danger>
                                        </div>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}
                </>
            ) : (
                <>
                    <form
                        css={tw`flex flex-wrap gap-2 mb-4`}
                        onSubmit={(e) => {
                            e.preventDefault();
                            doSearch(0);
                        }}
                    >
                        <div css={tw`relative flex-1 flex items-center`} style={{ minWidth: '200px' }}>
                            <FaMagnifyingGlass
                                css={tw`absolute left-3 text-gray-500 text-sm pointer-events-none`}
                                style={{ top: '50%', transform: 'translateY(-50%)' }}
                            />
                            <input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder={t('search_placeholder') ?? ''}
                                css={tw`w-full bg-gray-900 border border-gray-700 rounded-ui pl-9 pr-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-reviactyl focus:outline-none transition-colors`}
                            />
                        </div>
                        <div css={tw`flex gap-2 w-full sm:w-auto`}>
                            <Select
                                value={provider}
                                onChange={(e) => setProvider(e.target.value as PluginProvider)}
                                css={tw`flex-1 sm:flex-none sm:w-32`}
                            >
                                <option value={'modrinth'}>Modrinth</option>
                                <option value={'hangar'}>Hangar</option>
                                <option value={'spiget'}>SpigotMC</option>
                            </Select>
                            <Select
                                value={sort}
                                onChange={(e) => setSort(e.target.value as PluginSort)}
                                css={tw`flex-1 sm:flex-none sm:w-32`}
                            >
                                <option value={'relevance'}>{t('sort_relevance')}</option>
                                <option value={'downloads'}>{t('sort_downloads')}</option>
                                <option value={'updated'}>{t('sort_updated')}</option>
                            </Select>
                            <Button type={'submit'} disabled={searching}>
                                {searching ? <Spinner size={'small'} /> : t('search')}
                            </Button>
                        </div>
                    </form>

                    {!searching && hits.length === 0 ? (
                        <div css={tw`text-center py-16 text-gray-500`}>
                            <FaMagnifyingGlass css={tw`mx-auto text-3xl mb-3 text-gray-600`} />
                            <p css={tw`text-sm`}>{t('no_results')}</p>
                        </div>
                    ) : (
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                            {hits.map((hit) => (
                                <Card key={hit.id}>
                                    <PluginIcon url={hit.iconUrl} />
                                    <div css={tw`flex-1 min-w-0`}>
                                        <div css={tw`flex items-center gap-2 flex-wrap`}>
                                            <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{hit.title}</h3>
                                            {hit.installedVersion && (
                                                <Badge $variant={'installed'} title={t('installed_badge') ?? ''}>
                                                    <FaCheck style={{ fontSize: '9px' }} />
                                                    <span css={tw`font-mono`}>{hit.installedVersion}</span>
                                                </Badge>
                                            )}
                                        </div>
                                        <p css={tw`text-xs text-gray-500 mt-0.5 flex items-center gap-2`}>
                                            {hit.author && <span>{t('by', { author: hit.author })}</span>}
                                            <span css={tw`inline-flex items-center gap-1`}>
                                                <FaDownload css={tw`text-[10px]`} />
                                                {hit.downloads.toLocaleString()}
                                            </span>
                                        </p>
                                        <p
                                            css={tw`text-xs text-gray-400 mt-1 overflow-hidden`}
                                            style={{
                                                display: '-webkit-box',
                                                WebkitLineClamp: 2,
                                                WebkitBoxOrient: 'vertical',
                                            }}
                                        >
                                            {hit.description}
                                        </p>
                                        <div css={tw`mt-3 flex gap-2`}>
                                            {!hit.installedVersion && (
                                                <Button.Success
                                                    size={Button.Sizes.Small}
                                                    disabled={!!busy}
                                                    onClick={() => install(hit)}
                                                >
                                                    {busy === `install:${hit.id}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        <>
                                                            <FaDownload css={tw`inline mr-1 -mt-0.5`} />
                                                            {t('install')}
                                                        </>
                                                    )}
                                                </Button.Success>
                                            )}
                                            <Button
                                                size={Button.Sizes.Small}
                                                variant={Button.Variants.Secondary}
                                                disabled={!!busy}
                                                onClick={() => openVersions(hit)}
                                            >
                                                <FaListUl css={tw`inline mr-1 -mt-0.5`} />
                                                {t('versions')}
                                            </Button>
                                        </div>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}

                    {hits.length < total && (
                        <div css={tw`mt-6 text-center`}>
                            <Button
                                variant={Button.Variants.Secondary}
                                disabled={searching}
                                onClick={() => doSearch(hits.length)}
                            >
                                {searching ? <Spinner size={'small'} /> : t('load_more')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </ServerContentBlock>
    );
};

export default PluginsContainer;
