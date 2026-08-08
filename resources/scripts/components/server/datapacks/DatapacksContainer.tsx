import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled, { css } from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaCheck, FaCircle } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
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
    searchDatapacks,
    toggleDatapack,
    updateDatapack,
    getUntrackedDatapacks,
    registerDatapack,
    DatapackHit,
    ServerDatapack,
    DatapackProvider,
    DatapackSort,
    UntrackedZip,
} from '@/api/server/datapacks/datapacks';
import { ProgressBar } from './ProgressBar';
import { InstalledTab } from './InstalledTab';
import { BrowseTab } from './BrowseTab';
import { VersionPickerModal } from './VersionPickerModal';
import { useProgress } from './useProgress';

const TabsContainer = styled.div<{ $active: boolean }>`
    ${tw`flex items-center justify-between mb-4`};
    ${({ $active }) => $active && tw`mb-0`};
`;

const TabList = styled.div`
    ${tw`flex items-center gap-1 p-1 bg-gray-900/70 border border-gray-800 rounded-ui w-auto`}
`;

const TabButton = styled.button<{ $active: boolean }>`
    ${tw`px-3 py-1.5 rounded text-sm font-medium transition-colors duration-150`}

    ${({ $active }) =>
        $active
            ? tw`bg-gray-700 text-white shadow-sm`
            : tw`text-gray-400 hover:text-gray-200 hover:bg-gray-800/60`}
`;

const DatapacksContainer = () => {
    const { t } = useTranslation('server/datapacks');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [tab, setTab] = useState<'installed' | 'browse'>('installed');
    const [loading, setLoading] = useState(true);
    const [datapacks, setDatapacks] = useState<ServerDatapack[]>([]);
    const [_gameVersion, setGameVersion] = useState<string | null>(null);
    const [untracked, setUntracked] = useState<UntrackedZip[]>([]);
    const [hits, setHits] = useState<DatapackHit[]>([]);
    const [_total, setTotal] = useState(0);
    const [provider, setProvider] = useState<DatapackProvider>('modrinth');
    const [sort, setSort] = useState<DatapackSort>('relevance');
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    // Install progress state
    const [installing, setInstalling] = useState<{ title: string; step: number; version?: string } | null>(null);
    const progressWidth = useProgress(!!installing && installing.step < 3);

    // Version picker state
    const [selectedHit, setSelectedHit] = useState<DatapackHit | null>(null);
    const [versions, setVersions] = useState<any[] | null>(null);
    const [_versionsLoading, setVersionsLoading] = useState(false);

    const loadedOnce = useRef(false);

    const loadAll = async () => {
        setLoading(true);
        try {
            const dp = await getServerDatapacks(uuid);

            setDatapacks(dp.datapacks);
            setGameVersion(dp.gameVersion);
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setLoading(false);
            loadedOnce.current = true;
        }
    };

    const loadUntracked = async () => {
        try {
            const untracked = await getUntrackedDatapacks(uuid);
            setUntracked(untracked);
        } catch {
            // Untracked fetch is best-effort; do not show errors
        }
    };

    useEffect(() => {
        if (loadedOnce.current) return;
        loadAll();
        loadUntracked();
    }, []);

    const doSearch = async () => {
        setSearching(true);
        try {
            const result = await searchDatapacks(uuid, provider, query, 0, sort);
            setHits(result.hits);
            setTotal(result.total);
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setSearching(false);
        }
    };

    const doInstall = async (hit: DatapackHit) => {
        setBusy(`install:${hit.id}`);
        setInstalling({ title: hit.title, step: 0 });
        clearFlashes('server:datapacks');

        try {
            // Fetch the latest version to know version_number
            const versionData = await getDatapackVersions(uuid, provider, hit.id);
            const latest = versionData.versions[0];

            const installed = await installDatapack(uuid, {
                provider,
                projectId: hit.id,
                title: hit.title,
                iconUrl: hit.iconUrl ?? undefined,
                versionId: latest.id,
                slug: hit.slug,
            });

            setDatapacks((prev) => [...prev, installed]);
            setHits((prev) =>
                prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: installed.versionNumber } : h))
            );
            setInstalling((prev) => (prev ? { ...prev, step: 3, version: installed.versionNumber } : prev));

            addFlash({
                type: 'success',
                key: 'server:datapacks',
                message: t('install_success', { title: installed.title, version: installed.versionNumber }) ?? '',
            });

            setTimeout(() => setInstalling(null), 1600);
        } catch (error) {
            if (!handleInstallError(error)) {
                addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
                setInstalling(null);
            }
        } finally {
            setBusy(null);
        }
    };

    const handleInstallError = (error: any): boolean => {
        if (error?.response?.status === 409) {
            const detail = error.response.data?.errors?.[0]?.detail ?? '';

            addFlash({
                type: 'error',
                key: 'server:datapacks',
                message: detail || 'A conflicting datapack is already installed.',
            });

            return true;
        }

        return false;
    };

    const doUpdate = async (datapack: ServerDatapack) => {
        setBusy(`update-${datapack.id}`);
        try {
            const updated = await updateDatapack(uuid, datapack.id);
            setDatapacks((prev) =>
                prev.map((dp) => (dp.id === updated.id ? updated : dp))
            );
            addFlash({
                type: 'success',
                key: 'server:datapacks',
                message: t('update_success', { title: updated.title, version: updated.versionNumber }) ?? '',
            });
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setBusy(null);
        }
    };

    const doToggle = async (datapack: ServerDatapack) => {
        setBusy(`toggle-${datapack.id}`);
        try {
            const toggled = await toggleDatapack(uuid, datapack.id);
            setDatapacks((prev) =>
                prev.map((dp) => (dp.id === toggled.id ? toggled : dp))
            );
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setBusy(null);
        }
    };

    const doRemove = async (datapack: ServerDatapack) => {
        if (!confirm(t('delete_confirm', { title: datapack.title }))) {
            return;
        }

        setBusy(`delete-${datapack.id}`);
        try {
            await deleteDatapack(uuid, datapack.id);
            setDatapacks((prev) => prev.filter((dp) => dp.id !== datapack.id));
            addFlash({
                type: 'success',
                key: 'server:datapacks',
                message: t('delete_success', { title: datapack.title }) ?? '',
            });
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setBusy(null);
        }
    };

    const doTrack = async (zip: UntrackedZip) => {
        setBusy(`track:${zip.file_name}`);
        try {
            const registered = await registerDatapack(uuid, {
                file_name: zip.file_name,
                title: zip.title,
                slug: zip.slug,
                version: zip.pack_format ? String(zip.pack_format) : 'unknown',
            });
            setDatapacks((prev) => [...prev, registered]);
            setUntracked((prev) => prev.filter((z) => z.file_name !== zip.file_name));
            addFlash({
                type: 'success',
                key: 'server:datapacks',
                message: t('track_success', { title: registered.title }) ?? '',
            });
        } catch (error) {
            addError({ key: 'server:datapacks', message: httpErrorToHuman(error) });
        } finally {
            setBusy(null);
        }
    };

    const openVersions = async (hit: DatapackHit) => {
        setSelectedHit(hit);
        setVersionsLoading(true);
        setVersions(null);
        try {
            const data = await getDatapackVersions(uuid, provider, hit.id);
            setVersions(data.versions);
        } catch {
            addError({ key: 'server:datapacks', message: t('versions_error') ?? '' });
        } finally {
            setVersionsLoading(false);
        }
    };

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:datapacks'} />

            {loading ? (
                <Spinner centered />
            ) : (<>
            <TabsContainer $active={tab === 'browse'}>
                <TabList>
                    <TabButton $active={tab === 'installed'} onClick={() => setTab('installed')}>
                        {t('tab_installed')}
                    </TabButton>
                    <TabButton $active={tab === 'browse'} onClick={() => setTab('browse')}>
                        {t('tab_browse')}
                    </TabButton>
                </TabList>
            </TabsContainer>

            {tab === 'installed' ? (
                <InstalledTab
                    datapacks={datapacks}
                    untracked={untracked}
                    busy={busy}
                    onUpdate={doUpdate}
                    onToggle={doToggle}
                    onRemove={doRemove}
                    onLink={(dp) => {
                        setSelectedHit({
                            id: dp.projectId,
                            slug: dp.projectId,
                            title: dp.title,
                            description: '',
                            author: '',
                            iconUrl: dp.iconUrl,
                            downloads: 0,
                            installedVersion: dp.versionNumber,
                        });
                    }}
                    onTrack={doTrack}
                />
            ) : (
                <BrowseTab
                    provider={provider}
                    sort={sort}
                    query={query}
                    hits={hits}
                    searching={searching}
                    onProviderChange={setProvider}
                    onSortChange={setSort}
                    onQueryChange={setQuery}
                    onSearch={doSearch}
                    onOpenVersions={openVersions}
                />
            )}

            {installing && (
                <Modal visible={!!installing} onDismissed={() => setInstalling(null)} dismissable={false} size={'sm'}>
                    <h2 css={tw`text-lg sm:text-xl font-semibold mb-1 truncate`}>
                        {installing.step >= 3
                            ? t('install_done', { title: installing.title })
                            : t('installing_title', { title: installing.title })}
                    </h2>
                    <div css={tw`space-y-2.5 my-4`}>
                        {[t('step_resolve'), t('step_download'), t('step_finish')].map((label, i) => {
                            const done = installing.step > i;
                            const current = installing.step === i;

                            return (
                                <div key={label} css={tw`flex items-center gap-2.5 text-sm`}>
                                    {done ? (
                                        <FaCheck style={{ color: '#4ade80', fontSize: '12px', flexShrink: 0 }} />
                                    ) : current ? (
                                        <Spinner size={'small'} />
                                    ) : (
                                        <FaCircle style={{ color: 'rgba(255,255,255,0.15)', fontSize: '12px', flexShrink: 0 }} />
                                    )}
                                    <span css={tw`flex-1 text-gray-300`}>{label}</span>
                                </div>
                            );
                        })}
                    </div>
                    <ProgressBar>
                        <div css={css`width: ${progressWidth}%;`} />
                    </ProgressBar>
                </Modal>
            )}

            <VersionPickerModal
                hit={selectedHit}
                versions={versions}
                installedRow={null}
                busy={busy}
                onInstall={async (_, version) => {
                    if (!selectedHit) return;
                    const hitWithVersion = {
                        ...selectedHit,
                        versionId: version.id,
                        versionNumber: version.version_number,
                    } as DatapackHit & { versionId: string; versionNumber: string };
                    await doInstall(hitWithVersion);
                    setSelectedHit(null);
                }}
                onDismiss={() => {
                    setSelectedHit(null);
                    setVersions(null);
                }}
            />
            </>)}
        </ServerContentBlock>
    );
};

export default DatapacksContainer;
