import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled, { css } from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import {
    FaBoxOpen,
    FaCheck,
    FaCircle,
    FaDownload,
    FaListUl,
    FaMagnifyingGlass,
    FaTrash,
    FaPause,
    FaPlay,
    FaLink,
    FaArrowRotateRight,
} from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Select from '@/reviactyl/elements/Select';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import Modal from '@/reviactyl/elements/Modal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import {
    deleteDatapack,
    getDatapackVersions,
    getServerDatapacks,
    installDatapack,
    linkDatapack,
    DatapackHit,
    DatapackProvider,
    DatapackSort,
    DatapackVersion,
    searchDatapacks,
    ServerDatapack,
    toggleDatapack,
    updateDatapack,
    getUntrackedZips,
    registerZip,
    UntrackedZip,
    bulkUpdateDatapacks,
    bulkDeleteDatapacks,
} from '@/api/server/datapacks/datapacks';

/* ---------------------------------------------------------------------------
 * Minecraft-themed primitives
 * ------------------------------------------------------------------------- */

const GRASS = ['#5d8a3c', '#5d8a3c', '#76a24e', '#5d8a3c', '#4a7432', '#76a24e', '#5d8a3c', '#6b9a47'];
const StonePanel = styled.div`
    ${tw`bg-[#1a1d22] border border-black/70 shadow-[inset_0_1px_0_rgba(255,255,255,0.05),0_10px_30px_-12px_rgba(0,0,0,0.8)] rounded-[4px]`};
`;

const MinecraftButton = styled.button<{ $tone?: 'primary' | 'danger' | 'success' }>`
    ${tw`relative inline-flex items-center justify-center gap-1.5 text-sm font-semibold text-white select-none`}
    ${tw`px-4 h-8 border-2`}
    image-rendering: pixelated;
    transition: filter 120ms ease, transform 60ms ease;
    border-color: #1a1d22;
    background-color: #4c6f3f;
    background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.25), rgba(255, 255, 255, 0) 45%),
        linear-gradient(180deg, transparent 92%, rgba(0, 0, 0, 0.4));
    box-shadow: inset 0 2px 0 rgba(255, 255, 255, 0.22), inset 0 -2px 0 rgba(0, 0, 0, 0.28), 0 2px 0 rgba(0, 0, 0, 0.35);
    border-radius: 2px;

    &:hover:not(:disabled) {
        filter: brightness(1.12);
    }

    &:active:not(:disabled) {
        transform: translateY(1px);
        box-shadow: inset 0 2px 0 rgba(255, 255, 255, 0.12), inset 0 -1px 0 rgba(0, 0, 0, 0.3);
    }

    &:disabled {
        ${tw`cursor-not-allowed opacity-60`}
        filter: grayscale(0.4);
    }

    ${(props) =>
        props.$tone === 'danger' &&
        css`
            background-color: #8f3a3a;
            background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0) 45%),
                linear-gradient(180deg, transparent 92%, rgba(0, 0, 0, 0.4));
        `}
    ${(props) =>
        props.$tone === 'success' &&
        css`
            background-color: #3d6b37;
            background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0) 45%),
                linear-gradient(180deg, transparent 92%, rgba(0, 0, 0, 0.4));
        `}
`;

const MinecraftPill = styled.span<{ $tone?: 'green' | 'gold' | 'stone' }>`
    ${tw`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-white px-2 py-0.5 border-2 select-none`}
    border-radius: 2px;
    image-rendering: pixelated;
    border-color: #1a1d22;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2), inset 0 -1px 0 rgba(0, 0, 0, 0.3);

    ${(props) =>
        props.$tone === 'green' &&
        css`
            background-color: #3d6b37;
            color: #d7f5c6;
        `}
    ${(props) =>
        props.$tone === 'gold' &&
        css`
            background-color: #8a6b2f;
            color: #ffe9a8;
        `}
    ${(props) =>
        props.$tone === 'stone' &&
        css`
            background-color: #5a5f66;
            color: #e5e7eb;
        `}
`;

const ProgressBar = styled.div`
    height: 6px;
    border-radius: 2px;
    background-color: rgba(0, 0, 0, 0.5);
    border: 1px solid #000;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.5);
    overflow: hidden;

    & > div {
        height: 100%;
        border-radius: 1px;
        background-color: #4caf50;
        background-image: linear-gradient(90deg, rgba(255, 255, 255, 0.25), rgba(255, 255, 255, 0));
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

/* ---------------------------------------------------------------------------
 * Icon: pixelated grass-block placeholder + real icon thumbnail
 * ------------------------------------------------------------------------- */

const GrassBlock = ({ size = 40 }: { size?: number }) => (
    <div
        css={tw`relative overflow-hidden border-2 border-black/80 rounded-[3px] flex-shrink-0 shadow-[inset_0_0_0_1px_rgba(255,255,255,0.08)]`}
        style={{
            width: size,
            height: size,
            backgroundImage: `linear-gradient(180deg, ${GRASS[1]} 0 35%, #8a5a32 35% 100%)`,
            imageRendering: 'pixelated',
        }}
    >
        {/* randomized dirt speckles */}
        {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => (
            <span
                key={i}
                css={tw`absolute block`}
                style={{
                    width: 3,
                    height: 3,
                    left: `${((i * 37) % 10) * 9}%`,
                    top: `${38 + ((i * 53) % 45)}%`,
                    backgroundColor: i % 3 === 0 ? '#6b4626' : '#754d29',
                    imageRendering: 'pixelated',
                }}
            />
        ))}
    </div>
);

const DatapackIcon = ({ url }: { url: string | null }) =>
    url ? (
        <img src={url} alt={''} css={tw`w-10 h-10 rounded-[3px] object-cover flex-shrink-0 border-2 border-black/60`} />
    ) : (
        <GrassBlock />
    );

const EmptyState = ({ icon, title }: { icon: React.ReactNode; title: string }) => (
    <StonePanel css={tw`text-center py-14`}>
        <div css={tw`text-3xl text-gray-600 mb-3 flex justify-center`}>{icon}</div>
        <p css={tw`text-sm text-gray-500`}>{title}</p>
    </StonePanel>
);

const DatapacksContainer = () => {
    const { t } = useTranslation('server/datapacks');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [tab, setTab] = useState<'installed' | 'browse'>('browse');
    const [loading, setLoading] = useState(true);
    const [datapacks, setDatapacks] = useState<ServerDatapack[]>([]);
    const [gameVersion, setGameVersion] = useState<string | null>(null);

    const [provider, setProvider] = useState<DatapackProvider>('modrinth');
    const [sort, setSort] = useState<DatapackSort>('relevance');
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<DatapackHit[]>([]);
    const [total, setTotal] = useState(0);
    const [searching, setSearching] = useState(false);

    const [busy, setBusy] = useState<string | null>(null);
    const [installing, setInstalling] = useState<{ title: string; step: number; version?: string } | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<ServerDatapack | null>(null);
    const [versionsFor, setVersionsFor] = useState<DatapackHit | null>(null);
    const [linkingDatapack, setLinkingDatapack] = useState<ServerDatapack | null>(null);
    const [versions, setVersions] = useState<DatapackVersion[] | null>(null);
    const [installedRow, setInstalledRow] = useState<string | null>(null);
    const [untracked, setUntracked] = useState<UntrackedZip[]>([]);
    const [trackZip, setTrackZip] = useState<UntrackedZip | null>(null);
    const [selectedDatapacks, setSelectedDatapacks] = useState<Set<number>>(new Set());
    const [bulkOperation, setBulkOperation] = useState<{
        type: 'update' | 'delete';
        progress: number;
        total: number;
    } | null>(null);
    const searchId = useRef(0);
    const progressWidth = useProgress(!!installing && installing.step < 3);

    const disabledCount = datapacks.filter((d) => d.disabled).length;
    const untrackedCount = untracked.filter((z) => !datapacks.some((d) => d.fileName === z.file_name)).length;

    useEffect(() => {
        clearFlashes('server:datapacks');
        getServerDatapacks(uuid)
            .then((data) => {
                setDatapacks(data.datapacks);
                setGameVersion(data.gameVersion);
            })
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    }, []);

    const doSearch = (offset = 0, term = query) => {
        const id = ++searchId.current;
        setSearching(true);
        searchDatapacks(uuid, provider, term, offset, sort)
            .then((data) => {
                if (id !== searchId.current) return;
                setHits(offset === 0 ? data.hits : (prev) => [...prev, ...data.hits]);
                setTotal(data.total);
            })
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => id === searchId.current && setSearching(false));
    };

    // Drop stale hits immediately on a registry change: their install buttons
    // would otherwise install from the newly selected provider.
    useEffect(() => setHits([]), [provider, sort]);

    // One debounced effect for every search input.
    useEffect(() => {
        if (tab !== 'browse') return;
        setSearching(true);
        const timer = setTimeout(() => doSearch(0), 350);

        return () => clearTimeout(timer);
    }, [tab, provider, sort, query]);

    // Scan for manually-uploaded zips when opening the Installed tab.
    useEffect(() => {
        if (tab !== 'installed') return;
        getUntrackedZips(uuid)
            .then(setUntracked)
            .catch(() => setUntracked([]));
    }, [tab]);

    const track = (zip: UntrackedZip) => {
        setBusy(`track:${zip.file_name}`);
        clearFlashes('server:datapacks');
        registerZip(uuid, zip)
            .then((datapack) => {
                setDatapacks((prev) => [...prev, datapack]);
                setUntracked((prev) => prev.filter((j) => j.file_name !== zip.file_name));
                setTrackZip(null);
                addFlash({
                    type: 'success',
                    key: 'server:datapacks',
                    message: t('track_success', { title: datapack.title }) ?? '',
                });
            })
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    // On 409 (same datapack installed from another provider) ask the user to confirm replacement.
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

    const doInstall = (hit: DatapackHit, versionId?: string, step = 0, replace = false) => {
        // If linking a manual datapack, convert it instead of installing
        if (linkingDatapack) {
            setBusy(`link:${linkingDatapack.id}`);
            clearFlashes('server:datapacks');
            getDatapackVersions(uuid, provider, hit.id)
                .then(({ versions: vs }) => {
                    const version = versionId ? vs.find((v) => v.id === versionId) : vs[0];
                    if (!version) {
                        addError({ key: 'server:datapacks', message: 'No compatible version found.' });
                        return;
                    }
                    linkDatapack(
                        uuid,
                        linkingDatapack.id,
                        provider,
                        hit.id,
                        hit.title,
                        hit.iconUrl,
                        version.id,
                        version.versionNumber,
                        hit.slug
                    )
                        .then((datapack) => {
                            setDatapacks((prev) => prev.map((p) => (p.id === datapack.id ? datapack : p)));
                            setLinkingDatapack(null);
                            addFlash({
                                type: 'success',
                                key: 'server:datapacks',
                                message: t('link_success', { title: datapack.title }) ?? `Linked ${datapack.title}`,
                            });
                        })
                        .catch((error) => {
                            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                        })
                        .finally(() => setBusy(null));
                })
                .catch((error) => {
                    addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                    setBusy(null);
                });
            return;
        }

        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: versionId ? Math.max(step, 1) : step });
        clearFlashes('server:datapacks');
        installDatapack(uuid, provider, hit.id, hit.title, hit.iconUrl, versionId, hit.slug, replace)
            .then((datapack) => {
                if (versionId) setInstalledRow(versionId);
                setInstalling({ title: hit.title, step: 3, version: datapack.versionNumber });
                setDatapacks((prev) => [...prev.filter((p) => p.id !== datapack.id), datapack]);
                setHits((prev) =>
                    prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: datapack.versionNumber } : h))
                );
                addFlash({
                    type: 'success',
                    key: 'server:datapacks',
                    message:
                        t('install_success', {
                            title: datapack.title,
                            version: datapack.versionNumber,
                        }) ?? '',
                });
                setTimeout(() => setInstalling(null), 1600);
            })
            .catch((error) => {
                if (!handleInstallError(error, () => doInstall(hit, versionId, step, true))) {
                    addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                    setInstalling(null);
                }
            })
            .finally(() => setBusy(null));
    };

    // Quick install from a card: resolve the latest version, then install — no picker detour.
    const install = (hit: DatapackHit) => {
        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: 0 });
        clearFlashes('server:datapacks');
        getDatapackVersions(uuid, provider, hit.id)
            .then(({ versions: vs }) => {
                const latest = vs[0];
                if (!latest) {
                    addError({ key: 'server:datapacks', message: t('no_results') ?? '' });
                    setInstalling(null);
                    setBusy(null);
                    return;
                }
                doInstall(hit, latest.id, 1);
            })
            .catch((error) => {
                addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                setInstalling(null);
                setBusy(null);
            });
    };

    const openVersions = (hit: DatapackHit) => {
        setVersionsFor(hit);
        setVersions(null);
        setInstalledRow(null);
        getDatapackVersions(uuid, provider, hit.id)
            .then(({ versions: vs }) => setVersions(vs))
            .catch((error) => {
                addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                setVersionsFor(null);
            });
    };

    const mutate = (key: string, action: Promise<ServerDatapack>) => {
        setBusy(key);
        clearFlashes('server:datapacks');
        action
            .then((datapack) => setDatapacks((prev) => prev.map((p) => (p.id === datapack.id ? datapack : p))))
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    const runUpdate = (datapack: ServerDatapack) => {
        setBusy(`update:${datapack.id}`);
        setInstalling({ title: datapack.title, step: 0 });
        clearFlashes('server:datapacks');
        // Phase 1: resolve latest compatible version, then phase 2: download.
        getDatapackVersions(uuid, datapack.provider, datapack.projectId)
            .then(({ versions: vs }) => {
                if (!vs[0] || vs[0].id === datapack.versionId) {
                    addError({ key: 'server:datapacks', message: t('up_to_date') ?? '' });
                    setInstalling(null);
                    setBusy(null);
                    return;
                }
                setInstalling({ title: datapack.title, step: 1 });
                updateDatapack(uuid, datapack.id)
                    .then((p) => {
                        setInstalling({ title: datapack.title, step: 3, version: p.versionNumber });
                        setDatapacks((prev) => prev.map((x) => (x.id === p.id ? p : x)));
                        addFlash({
                            type: 'success',
                            key: 'server:datapacks',
                            message:
                                t('update_success', {
                                    title: p.title,
                                    version: p.versionNumber,
                                }) ?? '',
                        });
                        setTimeout(() => setInstalling(null), 1600);
                    })
                    .catch((error) => {
                        addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                        setInstalling(null);
                    })
                    .finally(() => setBusy(null));
            })
            .catch((error) => {
                addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                setInstalling(null);
                setBusy(null);
            });
    };

    const remove = (datapack: ServerDatapack) => {
        setBusy(`delete:${datapack.id}`);
        clearFlashes('server:datapacks');
        deleteDatapack(uuid, datapack.id)
            .then(() => setDatapacks((prev) => prev.filter((p) => p.id !== datapack.id)))
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => {
                setBusy(null);
                setConfirmRemove(null);
            });
    };

    const openLinkSearch = (datapack: ServerDatapack) => {
        setLinkingDatapack(datapack);
        setTab('browse');
        setQuery(datapack.title);
    };

    const toggleSelection = (datapackId: number) => {
        setSelectedDatapacks((prev) => {
            const next = new Set(prev);
            if (!next.delete(datapackId)) next.add(datapackId);
            return next;
        });
    };

    const selectAll = () => {
        setSelectedDatapacks(new Set(datapacks.filter((d) => d.provider !== 'manual').map((d) => d.id)));
    };

    const clearSelection = () => setSelectedDatapacks(new Set());

    const runBulkUpdate = () => {
        const ids = Array.from(selectedDatapacks);
        setBulkOperation({ type: 'update', progress: 0, total: ids.length });
        clearFlashes('server:datapacks');
        bulkUpdateDatapacks(uuid, ids)
            .then((result) => {
                result.success.forEach((item) => {
                    setDatapacks((prev) =>
                        prev.map((d) =>
                            d.id === item.id ? { ...d, versionNumber: item.version || d.versionNumber } : d
                        )
                    );
                });
                if (result.success.length > 0) {
                    addFlash({
                        type: 'success',
                        key: 'server:datapacks',
                        message:
                            t('bulk_update_success', { count: result.success.length }) ??
                            `Updated ${result.success.length} datapacks`,
                    });
                }
                if (result.failed.length > 0) {
                    addError({
                        key: 'server:datapacks',
                        message:
                            t('bulk_update_failed', { count: result.failed.length }) ??
                            `Failed to update ${result.failed.length} datapacks`,
                    });
                }
                clearSelection();
            })
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => setBulkOperation(null));
    };

    const runBulkDelete = () => {
        const ids = Array.from(selectedDatapacks);
        setBulkOperation({ type: 'delete', progress: 0, total: ids.length });
        clearFlashes('server:datapacks');
        bulkDeleteDatapacks(uuid, ids)
            .then((result) => {
                result.success.forEach((item) => {
                    setDatapacks((prev) => prev.filter((d) => d.id !== item.id));
                });
                if (result.success.length > 0) {
                    addFlash({
                        type: 'success',
                        key: 'server:datapacks',
                        message:
                            t('bulk_delete_success', { count: result.success.length }) ??
                            `Deleted ${result.success.length} datapacks`,
                    });
                }
                if (result.failed.length > 0) {
                    addError({
                        key: 'server:datapacks',
                        message:
                            t('bulk_delete_failed', { count: result.failed.length }) ??
                            `Failed to delete ${result.failed.length} datapacks`,
                    });
                }
                clearSelection();
            })
            .catch((error) => addError({ key: 'server:datapacks', message: httpErrorToHuman(error) }))
            .finally(() => setBulkOperation(null));
    };

    const installSteps = [t('step_resolve'), t('step_download'), t('step_finish')];

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:datapacks'} css={tw`mb-4`} />

            <Modal visible={!!installing} onDismissed={() => setInstalling(null)} dismissable={false} size={'sm'}>
                {installing && (
                    <StonePanel css={tw`p-5`}>
                        <h2 css={tw`text-lg sm:text-xl font-semibold mb-1 truncate text-gray-100`}>
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
                    </StonePanel>
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
                {confirmRemove && t('confirm_remove', { datapack: confirmRemove.title })}
            </ConfirmationModal>

            <ConfirmationModal
                visible={!!trackZip}
                title={t('track')}
                buttonText={t('track')}
                onConfirmed={() => trackZip && track(trackZip)}
                showSpinnerOverlay={!!busy}
                onModalDismissed={() => setTrackZip(null)}
            >
                {trackZip && t('track_confirm', { title: trackZip.title, version: trackZip.version })}
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
                    t('replace_body', {
                        title: replaceConflict.title,
                        provider: replaceConflict.provider,
                    })}
            </ConfirmationModal>

            <Modal visible={!!versionsFor} onDismissed={() => setVersionsFor(null)} size={'lg'}>
                {versionsFor && (
                    <StonePanel css={tw`p-6`}>
                        <h2 css={tw`text-xl sm:text-2xl mb-1 truncate text-gray-100`}>{versionsFor.title}</h2>
                        <p css={tw`text-sm text-gray-400 mb-4 sm:mb-6`}>{t('pick_version')}</p>
                        {!versions ? (
                            <Spinner centered />
                        ) : versions.length === 0 ? (
                            <p css={tw`text-sm text-gray-500 text-center py-6`}>{t('no_results')}</p>
                        ) : (
                            <div css={tw`overflow-y-auto max-h-96 divide-y divide-black/40`}>
                                {versions.map((version) => (
                                    <div key={version.id} css={tw`py-2.5`}>
                                        <div css={tw`flex items-center gap-2`}>
                                            <div css={tw`flex-1 min-w-0`}>
                                                <p css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                    {version.versionNumber}
                                                </p>
                                                <p css={tw`text-xs text-gray-500 truncate`}>
                                                    {version.gameVersions.length > 0 && version.gameVersions.join(', ')}
                                                </p>
                                            </div>
                                            {installedRow === version.id ? (
                                                <MinecraftPill $tone={'green'}>
                                                    <FaCheck style={{ fontSize: '9px' }} />
                                                    <span css={tw`font-mono`}>{version.versionNumber}</span>
                                                </MinecraftPill>
                                            ) : (
                                                <MinecraftButton
                                                    $tone={'success'}
                                                    onClick={() => doInstall(versionsFor, version.id, 1)}
                                                    disabled={!!busy}
                                                >
                                                    {busy === `install:${versionsFor.id}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        t('install')
                                                    )}
                                                </MinecraftButton>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </StonePanel>
                )}
            </Modal>

            {/* Header panel */}
            <StonePanel css={tw`mb-4 overflow-hidden`}>
                <div css={tw`flex items-center justify-between gap-3 px-4 py-4 sm:px-5 flex-wrap`}>
                    <div css={tw`flex items-center gap-3`}>
                        <div
                            css={tw`w-11 h-11 rounded-[3px] border-2 border-black/70 bg-[#2a2e34] flex items-center justify-center shadow-inner`}
                        >
                            <FaBoxOpen css={tw`text-[#8bc34a] text-xl`} />
                        </div>
                        <div>
                            <p css={tw`text-sm font-semibold text-gray-100 leading-tight`}>{t('title')}</p>
                            <p css={tw`text-xs text-gray-500`}>{t('restart_notice')}</p>
                        </div>
                    </div>
                    {gameVersion && (
                        <MinecraftPill $tone={'gold'}>⛏ {t('detected', { version: gameVersion })}</MinecraftPill>
                    )}
                </div>
                {datapacks.length > 0 && (
                    <div css={tw`flex items-center gap-2 px-4 sm:px-5 pb-4 flex-wrap`}>
                        <MinecraftPill $tone={'green'}>
                            {t('stats_installed', { count: datapacks.length })}
                        </MinecraftPill>
                        {disabledCount > 0 && (
                            <MinecraftPill $tone={'gold'}>
                                {t('stats_disabled', { count: disabledCount })}
                            </MinecraftPill>
                        )}
                        {untrackedCount > 0 && (
                            <MinecraftPill $tone={'stone'}>
                                {t('stats_untracked', { count: untrackedCount })}
                            </MinecraftPill>
                        )}
                    </div>
                )}
            </StonePanel>

            {/* Tabs + bulk toolbar */}
            <div css={tw`flex flex-wrap items-center gap-2 mb-4`}>
                <MinecraftButton
                    onClick={() => setTab('browse')}
                    $tone={tab === 'browse' ? 'primary' : undefined}
                    css={tab === 'browse' ? undefined : tw`opacity-80`}
                >
                    <FaMagnifyingGlass css={tw`text-[11px]`} />
                    {t('browse')}
                </MinecraftButton>
                <MinecraftButton
                    onClick={() => setTab('installed')}
                    $tone={tab === 'installed' ? 'primary' : undefined}
                    css={tab === 'installed' ? undefined : tw`opacity-80`}
                >
                    <FaBoxOpen css={tw`text-[11px]`} />
                    {t('installed')} ({datapacks.length})
                </MinecraftButton>

                {tab === 'installed' && datapacks.some((d) => d.provider !== 'manual') && (
                    <div css={tw`flex items-center gap-2 ml-auto`}>
                        <MinecraftButton onClick={selectAll} disabled={!!busy || !!bulkOperation}>
                            {t('select_all', { defaultValue: 'Select All' }) ?? 'Select All'}
                        </MinecraftButton>
                        <MinecraftButton onClick={clearSelection} disabled={!!busy || !!bulkOperation}>
                            {t('select_none', { defaultValue: 'Clear' }) ?? 'Clear'}
                        </MinecraftButton>
                        {selectedDatapacks.size > 0 && (
                            <>
                                <MinecraftButton
                                    $tone={'success'}
                                    onClick={runBulkUpdate}
                                    disabled={!!busy || !!bulkOperation}
                                >
                                    {bulkOperation?.type === 'update' ? (
                                        <Spinner size={'small'} />
                                    ) : (
                                        <>
                                            <FaArrowRotateRight css={tw`text-[11px]`} />
                                            {t('bulk_update_label', { defaultValue: 'Update' }) ?? 'Update'} (
                                            {selectedDatapacks.size})
                                        </>
                                    )}
                                </MinecraftButton>
                                <MinecraftButton
                                    $tone={'danger'}
                                    onClick={runBulkDelete}
                                    disabled={!!busy || !!bulkOperation}
                                >
                                    {bulkOperation?.type === 'delete' ? (
                                        <Spinner size={'small'} />
                                    ) : (
                                        <>
                                            <FaTrash css={tw`text-[11px]`} />
                                            {t('bulk_delete_label', { defaultValue: 'Delete' }) ?? 'Delete'} (
                                            {selectedDatapacks.size})
                                        </>
                                    )}
                                </MinecraftButton>
                            </>
                        )}
                    </div>
                )}
            </div>

            {loading ? (
                <Spinner centered />
            ) : tab === 'installed' ? (
                <>
                    {untracked.length > 0 && (
                        <div css={tw`mb-4`}>
                            <p css={tw`text-xs text-gray-400 mb-2 uppercase tracking-wider`}>{t('untracked_title')}</p>
                            <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                                {untracked.map((zip) => (
                                    <StonePanel key={zip.file_name} css={tw`p-3 sm:p-4 flex gap-3 sm:gap-4`}>
                                        <GrassBlock />
                                        <div css={tw`flex-1 min-w-0`}>
                                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                                <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                    {zip.title}
                                                </h3>
                                                <MinecraftPill $tone={'stone'}>{t('manual_badge')}</MinecraftPill>
                                            </div>
                                            <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>
                                                {zip.file_name}
                                            </p>
                                            <div css={tw`flex gap-2 mt-3`}>
                                                <MinecraftButton disabled={!!busy} onClick={() => setTrackZip(zip)}>
                                                    {busy === `track:${zip.file_name}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        <>
                                                            <FaLink css={tw`text-[11px]`} />
                                                            {t('track')}
                                                        </>
                                                    )}
                                                </MinecraftButton>
                                            </div>
                                        </div>
                                    </StonePanel>
                                ))}
                            </div>
                        </div>
                    )}
                    {datapacks.length === 0 ? (
                        <EmptyState icon={<FaBoxOpen />} title={t('no_datapacks')} />
                    ) : (
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                            {datapacks.map((datapack) => (
                                <StonePanel
                                    key={datapack.id}
                                    css={tw`p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-600/40`}
                                >
                                    {datapack.provider !== 'manual' && (
                                        <input
                                            type='checkbox'
                                            checked={selectedDatapacks.has(datapack.id)}
                                            onChange={() => toggleSelection(datapack.id)}
                                            disabled={!!busy || !!bulkOperation}
                                            css={tw`w-4 h-4 rounded border-gray-700 bg-gray-800 text-reviactyl focus:ring-reviactyl focus:ring-offset-gray-900 cursor-pointer disabled:opacity-50 flex-shrink-0`}
                                        />
                                    )}
                                    <DatapackIcon url={datapack.iconUrl} />
                                    <div css={tw`flex-1 min-w-0`}>
                                        <div css={tw`flex items-center gap-2 flex-wrap`}>
                                            <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                {datapack.title}
                                            </h3>
                                            <MinecraftPill $tone={datapack.provider === 'manual' ? 'stone' : 'stone'}>
                                                {datapack.provider === 'manual' ? t('manual_badge') : datapack.provider}
                                            </MinecraftPill>
                                            {datapack.disabled && (
                                                <MinecraftPill $tone={'gold'}>{t('disabled_badge')}</MinecraftPill>
                                            )}
                                        </div>
                                        <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>
                                            {datapack.fileName}
                                        </p>
                                        <div css={tw`mt-1 flex items-center gap-2`}>
                                            <MinecraftPill $tone={'green'}>
                                                <FaCheck style={{ fontSize: '9px' }} />
                                                <span css={tw`font-mono`}>{datapack.versionNumber}</span>
                                            </MinecraftPill>
                                        </div>
                                        <div css={tw`flex gap-2 mt-3 flex-wrap`}>
                                            {datapack.provider === 'manual' && (
                                                <MinecraftButton
                                                    disabled={!!busy}
                                                    onClick={() => openLinkSearch(datapack)}
                                                >
                                                    <FaLink css={tw`text-[11px]`} />
                                                    {t('link')}
                                                </MinecraftButton>
                                            )}
                                            {!datapack.disabled && datapack.provider !== 'manual' && (
                                                <MinecraftButton disabled={!!busy} onClick={() => runUpdate(datapack)}>
                                                    {busy === `update:${datapack.id}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : (
                                                        <>
                                                            <FaArrowRotateRight css={tw`text-[11px]`} />
                                                            {t('update')}
                                                        </>
                                                    )}
                                                </MinecraftButton>
                                            )}
                                            <MinecraftButton
                                                disabled={!!busy}
                                                onClick={() =>
                                                    mutate(`toggle:${datapack.id}`, toggleDatapack(uuid, datapack.id))
                                                }
                                            >
                                                {busy === `toggle:${datapack.id}` ? (
                                                    <Spinner size={'small'} />
                                                ) : datapack.disabled ? (
                                                    <>
                                                        <FaPlay css={tw`text-[11px]`} />
                                                        {t('enable')}
                                                    </>
                                                ) : (
                                                    <>
                                                        <FaPause css={tw`text-[11px]`} />
                                                        {t('disable')}
                                                    </>
                                                )}
                                            </MinecraftButton>
                                            <MinecraftButton
                                                $tone={'danger'}
                                                disabled={!!busy}
                                                onClick={() => setConfirmRemove(datapack)}
                                            >
                                                <FaTrash css={tw`text-[11px]`} />
                                                {t('remove')}
                                            </MinecraftButton>
                                        </div>
                                    </div>
                                </StonePanel>
                            ))}
                        </div>
                    )}
                </>
            ) : (
                <>
                    {linkingDatapack && (
                        <div
                            css={tw`mb-4 p-3 bg-blue-900/30 border border-blue-700/50 rounded-[4px] flex items-center justify-between`}
                        >
                            <div>
                                <p css={tw`text-sm font-semibold text-blue-200`}>Linking: {linkingDatapack.title}</p>
                                <p css={tw`text-xs text-blue-300/80 mt-0.5`}>
                                    Select a datapack below to link it to this manual datapack for updates
                                </p>
                            </div>
                            <MinecraftButton onClick={() => setLinkingDatapack(null)}>Cancel</MinecraftButton>
                        </div>
                    )}
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
                                css={tw`w-full bg-gray-900 border border-gray-700 rounded-[4px] pl-9 pr-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-reviactyl focus:outline-none transition-colors`}
                            />
                        </div>
                        <div css={tw`flex gap-2 w-full sm:w-auto`}>
                            <Select
                                value={provider}
                                onChange={(e) => setProvider(e.target.value as DatapackProvider)}
                                css={tw`flex-1 sm:flex-none sm:w-40`}
                            >
                                <option value={'modrinth'}>Modrinth</option>
                                <option value={'curseforge'}>CurseForge</option>
                            </Select>
                            <Select
                                value={sort}
                                onChange={(e) => setSort(e.target.value as DatapackSort)}
                                css={tw`flex-1 sm:flex-none sm:w-32`}
                            >
                                <option value={'relevance'}>{t('sort_relevance')}</option>
                                <option value={'downloads'}>{t('sort_downloads')}</option>
                                <option value={'updated'}>{t('sort_updated')}</option>
                            </Select>
                            <MinecraftButton $tone={'primary'} type={'submit'} disabled={searching}>
                                {searching ? <Spinner size={'small'} /> : t('search')}
                            </MinecraftButton>
                        </div>
                    </form>

                    {searching && hits.length === 0 ? (
                        <div css={tw`py-16`}>
                            <Spinner centered />
                        </div>
                    ) : hits.length === 0 ? (
                        <EmptyState icon={<FaMagnifyingGlass />} title={t('no_results')} />
                    ) : (
                        <>
                            <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                                {hits.map((hit) => (
                                    <StonePanel
                                        key={hit.id}
                                        css={tw`p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-600/40`}
                                    >
                                        <DatapackIcon url={hit.iconUrl} />
                                        <div css={tw`flex-1 min-w-0`}>
                                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                                <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                    {hit.title}
                                                </h3>
                                                {hit.installedVersion && (
                                                    <MinecraftPill $tone={'green'} title={t('installed_badge') ?? ''}>
                                                        <FaCheck style={{ fontSize: '9px' }} />
                                                        <span css={tw`font-mono`}>{hit.installedVersion}</span>
                                                    </MinecraftPill>
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
                                                    <MinecraftButton
                                                        $tone={'success'}
                                                        disabled={!!busy}
                                                        onClick={() => install(hit)}
                                                    >
                                                        {busy === `install:${hit.id}` ? (
                                                            <Spinner size={'small'} />
                                                        ) : (
                                                            <>
                                                                <FaDownload css={tw`text-[11px]`} />
                                                                {t('install')}
                                                            </>
                                                        )}
                                                    </MinecraftButton>
                                                )}
                                                <MinecraftButton disabled={!!busy} onClick={() => openVersions(hit)}>
                                                    <FaListUl css={tw`text-[11px]`} />
                                                    {t('versions')}
                                                </MinecraftButton>
                                            </div>
                                        </div>
                                    </StonePanel>
                                ))}
                            </div>

                            {hits.length < total && (
                                <div css={tw`mt-6 text-center`}>
                                    <MinecraftButton disabled={searching} onClick={() => doSearch(hits.length)}>
                                        {searching ? <Spinner size={'small'} /> : t('load_more')}
                                    </MinecraftButton>
                                </div>
                            )}
                        </>
                    )}
                </>
            )}
        </ServerContentBlock>
    );
};

export default DatapacksContainer;
