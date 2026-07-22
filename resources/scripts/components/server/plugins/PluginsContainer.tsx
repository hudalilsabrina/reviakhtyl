import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled, { css } from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaDownload, FaMagnifyingGlass, FaPuzzlePiece, FaTrash } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Select from '@/reviactyl/elements/Select';
import { Button } from '@/reviactyl/elements/button/index';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import {
    deletePlugin,
    getServerPlugins,
    installPlugin,
    PluginHit,
    PluginProvider,
    searchPlugins,
    ServerPlugin,
    togglePlugin,
    updatePlugin,
} from '@/api/server/plugins/plugins';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-4 flex gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

const Badge = styled.span<{ $variant: 'provider' | 'disabled' | 'installed' }>`
    ${tw`uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded`}
    font-size: 10px;

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
            ${tw`bg-success/20 text-success`};
        `}
`;

const PluginIcon = ({ url }: { url: string | null }) =>
    url ? (
        <img src={url} alt={''} css={tw`w-12 h-12 rounded-ui object-cover flex-shrink-0`} />
    ) : (
        <div
            css={tw`w-12 h-12 rounded-ui bg-gray-800 border border-gray-700 flex items-center justify-center flex-shrink-0`}
        >
            <FaPuzzlePiece css={tw`text-gray-500 text-lg`} />
        </div>
    );

const PluginsContainer = () => {
    const { t } = useTranslation('server/plugins');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, clearFlashes } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const [tab, setTab] = useState<'installed' | 'browse'>('browse');
    const [loading, setLoading] = useState(true);
    const [plugins, setPlugins] = useState<ServerPlugin[]>([]);
    const [gameVersion, setGameVersion] = useState<string | null>(null);
    const [loaders, setLoaders] = useState<string[]>([]);

    const [provider, setProvider] = useState<PluginProvider>('modrinth');
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<PluginHit[]>([]);
    const [total, setTotal] = useState(0);
    const [searching, setSearching] = useState(false);

    const [busy, setBusy] = useState<string | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<ServerPlugin | null>(null);
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
        searchPlugins(uuid, provider, term, offset)
            .then((data) => {
                if (id !== searchId.current) return;
                setHits(offset === 0 ? data.hits : (prev) => [...prev, ...data.hits]);
                setTotal(data.total);
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => id === searchId.current && setSearching(false));
    };

    useEffect(() => {
        if (tab === 'browse') {
            setHits([]);
            doSearch(0);
        }
    }, [tab, provider]);

    // Debounced live search while typing.
    useEffect(() => {
        if (tab !== 'browse') return;
        const timer = setTimeout(() => doSearch(0), 350);

        return () => clearTimeout(timer);
    }, [query]);

    const install = (hit: PluginHit) => {
        setBusy(`install:${hit.id}`);
        clearFlashes('server:plugins');
        installPlugin(uuid, provider, hit.id, hit.title, hit.iconUrl)
            .then((plugin) => {
                setPlugins((prev) => [...prev.filter((p) => p.id !== plugin.id), plugin]);
                setHits((prev) =>
                    prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: plugin.versionNumber } : h))
                );
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
    };

    const mutate = (key: string, action: Promise<ServerPlugin>) => {
        setBusy(key);
        clearFlashes('server:plugins');
        action
            .then((plugin) => setPlugins((prev) => prev.map((p) => (p.id === plugin.id ? plugin : p))))
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setBusy(null));
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

    return (
        <ServerContentBlock title={t('title')}>
            <FlashMessageRender byKey={'server:plugins'} css={tw`mb-4`} />
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
                plugins.length === 0 ? (
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
                                        <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{plugin.title}</h3>
                                        <Badge $variant={'provider'}>{plugin.provider}</Badge>
                                        {plugin.disabled && <Badge $variant={'disabled'}>{t('disabled_badge')}</Badge>}
                                    </div>
                                    <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>{plugin.fileName}</p>
                                    <p css={tw`text-xs text-gray-500 mt-0.5`}>
                                        {t('version', { version: plugin.versionNumber })}
                                    </p>
                                    <div css={tw`flex gap-2 mt-3 flex-wrap`}>
                                        {!plugin.disabled && (
                                            <Button
                                                size={Button.Sizes.Small}
                                                variant={Button.Variants.Secondary}
                                                disabled={!!busy}
                                                onClick={() =>
                                                    mutate(`update:${plugin.id}`, updatePlugin(uuid, plugin.id))
                                                }
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
                                            onClick={() => mutate(`toggle:${plugin.id}`, togglePlugin(uuid, plugin.id))}
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
                )
            ) : (
                <>
                    <form
                        css={tw`flex gap-2 mb-4`}
                        onSubmit={(e) => {
                            e.preventDefault();
                            doSearch(0);
                        }}
                    >
                        <Select
                            value={provider}
                            onChange={(e) => setProvider(e.target.value as PluginProvider)}
                            css={tw`w-36 flex-shrink-0`}
                        >
                            <option value={'modrinth'}>Modrinth</option>
                            <option value={'hangar'}>Hangar</option>
                            <option value={'spiget'}>SpigotMC</option>
                        </Select>
                        <div css={tw`relative flex-1 flex items-center`}>
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
                        <Button type={'submit'} disabled={searching}>
                            {searching ? <Spinner size={'small'} /> : t('search')}
                        </Button>
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
                                                <Badge $variant={'installed'}>
                                                    {t('installed_badge')} {hit.installedVersion}
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
                                        <div css={tw`mt-3`}>
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
