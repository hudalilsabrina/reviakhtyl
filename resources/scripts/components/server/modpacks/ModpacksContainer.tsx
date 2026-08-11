import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaCheck, FaDownload, FaMagnifyingGlass, FaLink, FaCube } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Select from '@/reviactyl/elements/Select';
import { Button } from '@/reviactyl/elements/button/index';
import Modal from '@/reviactyl/elements/Modal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import type { ModProvider, ModSort } from '@/api/server/mods/mods';
import {
    installModpack,
    ModpackHit,
    ModpackInstallResult,
    ModpackPreview,
    previewModpack,
    searchModpacks,
} from '@/api/server/modpacks/modpacks';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

const Badge = styled.span<{ $variant: 'provider' }>`
    ${tw`uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-gray-700/70 text-gray-300`}
    font-size: 10px;
`;

const ModpackIcon = ({ url }: { url: string | null }) =>
    url ? (
        <img src={url} alt={''} css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui object-cover flex-shrink-0`} />
    ) : (
        <div
            css={tw`w-10 h-10 sm:w-12 sm:h-12 rounded-ui bg-gray-800 border border-gray-700 flex items-center justify-center flex-shrink-0`}
        >
            <FaCube css={tw`text-gray-500 text-lg`} />
        </div>
    );

const ModpacksContainer = () => {
    const { t } = useTranslation('server/modpacks');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [provider, setProvider] = useState<ModProvider>('modrinth');
    const [sort, setSort] = useState<ModSort>('relevance');
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<ModpackHit[]>([]);
    const [total, setTotal] = useState(0);
    const [searching, setSearching] = useState(false);

    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState<ModpackPreview | null>(null);
    const [installUrl, setInstallUrl] = useState('');
    const [showUrlModal, setShowUrlModal] = useState(false);
    const [installing, setInstalling] = useState(false);
    const [result, setResult] = useState<ModpackInstallResult | null>(null);

    const searchId = useRef(0);

    const doSearch = (offset = 0, term = query) => {
        const id = ++searchId.current;
        setSearching(true);
        searchModpacks(uuid, provider, term, offset, sort)
            .then((data) => {
                if (id !== searchId.current) return;
                setHits(offset === 0 ? data.hits : (prev) => [...prev, ...data.hits]);
                setTotal(data.total);
            })
            .catch((error) => addError({ key: 'server:modpacks', message: httpErrorToHuman(error) }))
            .finally(() => id === searchId.current && setSearching(false));
    };

    // Drop stale hits immediately on a registry change: their install buttons
    // would otherwise act on results from the previously selected provider.
    useEffect(() => setHits([]), [provider, sort]);

    // Debounced search on any input change; also fires once on mount.
    useEffect(() => {
        setSearching(true);
        const timer = setTimeout(() => doSearch(0), 350);

        return () => clearTimeout(timer);
    }, [provider, sort, query]);

    const startInstallFromHit = (hit: ModpackHit) => {
        setBusy(true);
        clearFlashes('server:modpacks');
        previewModpack(uuid, provider, hit.id)
            .then((data) => {
                setPreview(data);
                setInstallUrl(data.download_url);
                setShowUrlModal(true);
                setResult(null);
            })
            .catch((error) => addError({ key: 'server:modpacks', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(false));
    };

    const doInstall = () => {
        if (!installUrl.trim()) return;
        setInstalling(true);
        setResult(null);
        clearFlashes('server:modpacks');
        installModpack(uuid, installUrl.trim())
            .then((res) => {
                setResult(res);
                if (res.success.length > 0) {
                    addFlash({
                        type: 'success',
                        key: 'server:modpacks',
                        message: t('modpack_success', { name: res.name, count: res.success.length }) ?? '',
                    });
                }
                if (res.failed.length > 0) {
                    addFlash({
                        type: 'error',
                        key: 'server:modpacks',
                        message: t('modpack_failed', { count: res.failed.length }) ?? '',
                    });
                }
            })
            .catch((error) => addError({ key: 'server:modpacks', message: httpErrorToHuman(error) }))
            .finally(() => setInstalling(false));
    };

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:modpacks'} css={tw`mb-4`} />

            <Modal visible={showUrlModal} onDismissed={() => setShowUrlModal(false)} size={'md'}>
                <h2 css={tw`text-xl sm:text-2xl mb-1`}>{preview ? preview.name : t('install_from_url')}</h2>
                {preview && (
                    <p css={tw`text-sm text-gray-400 mb-4 sm:mb-6`}>
                        <Badge $variant={'provider'}>{preview.format}</Badge>{' '}
                        {t('packed_mods', { count: preview.mods.length })}
                    </p>
                )}
                {!preview && <p css={tw`text-sm text-gray-400 mb-4 sm:mb-6`}>{t('url_hint')}</p>}
                {result ? (
                    <div css={tw`space-y-3`}>
                        <div css={tw`flex items-center gap-2`}>
                            <Badge $variant={'provider'}>{result.format}</Badge>
                            <span css={tw`text-sm text-gray-200`}>{result.name}</span>
                        </div>
                        {result.success.length > 0 && (
                            <div>
                                <p css={tw`text-xs font-semibold text-green-400 mb-1.5`}>
                                    {t('installed', { count: result.success.length })}
                                </p>
                                <div css={tw`max-h-40 overflow-y-auto space-y-1`}>
                                    {result.success.map((m) => (
                                        <div
                                            key={m.project_id}
                                            css={tw`flex items-center gap-1.5 text-xs text-gray-300`}
                                        >
                                            <FaCheck style={{ color: '#4ade80', fontSize: '10px', flexShrink: 0 }} />
                                            <span css={tw`truncate`}>{m.title}</span>
                                            <span css={tw`text-gray-500 font-mono flex-shrink-0`}>{m.version}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        {result.failed.length > 0 && (
                            <div>
                                <p css={tw`text-xs font-semibold text-red-400 mb-1.5`}>
                                    {t('failed', { count: result.failed.length })}
                                </p>
                                <div css={tw`max-h-40 overflow-y-auto space-y-1`}>
                                    {result.failed.map((m, i) => (
                                        <div key={i} css={tw`text-xs text-red-300/80`}>
                                            {m.project_id ?? 'unknown'}: {m.error}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        <Button
                            variant={Button.Variants.Secondary}
                            onClick={() => setShowUrlModal(false)}
                            css={tw`w-full mt-2`}
                        >
                            {t('close')}
                        </Button>
                    </div>
                ) : (
                    <>
                        <input
                            value={installUrl}
                            onChange={(e) => setInstallUrl(e.target.value)}
                            placeholder={'https://example.com/modpack.mrpack'}
                            css={tw`w-full bg-gray-900 border border-gray-700 rounded-ui px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-reviactyl focus:outline-none transition-colors mb-4`}
                            onKeyDown={(e) => e.key === 'Enter' && doInstall()}
                        />
                        <Button onClick={doInstall} disabled={installing || !installUrl.trim()} css={tw`w-full`}>
                            {installing ? <Spinner size={'small'} /> : t('install')}
                        </Button>
                    </>
                )}
            </Modal>

            <div css={tw`flex flex-wrap gap-2 mb-4 items-end justify-between`}>
                <form
                    css={tw`flex flex-wrap gap-2 flex-1`}
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
                            onChange={(e) => setProvider(e.target.value as ModProvider)}
                            css={tw`flex-1 sm:flex-none sm:w-40`}
                        >
                            <option value={'modrinth'}>Modrinth</option>
                            <option value={'curseforge'}>CurseForge</option>
                        </Select>
                        <Select
                            value={sort}
                            onChange={(e) => setSort(e.target.value as ModSort)}
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
                <Button
                    size={Button.Sizes.Small}
                    variant={Button.Variants.Secondary}
                    onClick={() => {
                        setShowUrlModal(true);
                        setResult(null);
                        setPreview(null);
                        setInstallUrl('');
                    }}
                >
                    <FaLink css={tw`inline mr-1 -mt-0.5`} />
                    {t('install_from_url')}
                </Button>
            </div>
            <p css={tw`text-xs text-gray-500 mb-4`}>{t('restart_notice')}</p>

            {searching && hits.length === 0 ? (
                <div css={tw`py-16`}>
                    <Spinner centered />
                </div>
            ) : hits.length === 0 ? (
                <div css={tw`text-center py-16 text-gray-500`}>
                    <FaMagnifyingGlass css={tw`mx-auto text-3xl mb-3 text-gray-600`} />
                    <p css={tw`text-sm`}>{t('no_results')}</p>
                </div>
            ) : (
                <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                    {hits.map((hit) => (
                        <Card key={hit.id}>
                            <ModpackIcon url={hit.iconUrl} />
                            <div css={tw`flex-1 min-w-0`}>
                                <div css={tw`flex items-center gap-2 flex-wrap`}>
                                    <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{hit.title}</h3>
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
                                    <Button.Success
                                        size={Button.Sizes.Small}
                                        disabled={busy || searching}
                                        onClick={() => startInstallFromHit(hit)}
                                    >
                                        {busy ? (
                                            <Spinner size={'small'} />
                                        ) : (
                                            <>
                                                <FaDownload css={tw`inline mr-1 -mt-0.5`} />
                                                {t('install')}
                                            </>
                                        )}
                                    </Button.Success>
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
        </ServerContentBlock>
    );
};

export default ModpacksContainer;
