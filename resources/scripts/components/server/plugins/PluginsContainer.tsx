import { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Input from '@/reviactyl/elements/Input';
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

const PluginsContainer = () => {
    const { t } = useTranslation('server/plugins');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [tab, setTab] = useState<'installed' | 'browse'>('installed');
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

    const refresh = () => {
        clearFlashes('server:plugins');
        return getServerPlugins(uuid)
            .then((data) => {
                setPlugins(data.plugins);
                setGameVersion(data.gameVersion);
                setLoaders(data.loaders);
            })
            .catch((error) => addError({ key: 'server:plugins', message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        refresh();
    }, []);

    const doSearch = (offset = 0, nextQuery = query, nextProvider = provider) => {
        const id = ++searchId.current;
        setSearching(true);
        searchPlugins(uuid, nextProvider, nextQuery, offset)
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

    const install = (hit: PluginHit) => {
        setBusy(`install:${hit.id}`);
        clearFlashes('server:plugins');
        installPlugin(uuid, provider, hit.id, hit.title, hit.iconUrl)
            .then((plugin) => {
                setPlugins((prev) => [...prev.filter((p) => p.id !== plugin.id), plugin]);
                setHits((prev) =>
                    prev.map((h) => (h.id === hit.id ? { ...h, installedVersion: plugin.versionNumber } : h))
                );
                addFlash({ key: 'server:plugins', type: 'success', message: t('installed_badge') });
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

            <div css={tw`flex items-center justify-between mb-4 flex-wrap gap-2`}>
                <div css={tw`flex gap-2`}>
                    <Button
                        variant={tab !== 'installed' ? Button.Variants.Secondary : undefined}
                        size={Button.Sizes.Small}
                        onClick={() => setTab('installed')}
                    >
                        {t('installed')} ({plugins.length})
                    </Button>
                    <Button
                        variant={tab !== 'browse' ? Button.Variants.Secondary : undefined}
                        size={Button.Sizes.Small}
                        onClick={() => setTab('browse')}
                    >
                        {t('browse')}
                    </Button>
                </div>
                {gameVersion && (
                    <p css={tw`text-xs text-neutral-400`}>
                        {t('detected', { version: gameVersion, loader: loaders[0] ?? '' })}
                    </p>
                )}
            </div>
            <p css={tw`text-xs text-neutral-400 mb-4`}>{t('restart_notice')}</p>

            {loading ? (
                <Spinner centered />
            ) : tab === 'installed' ? (
                plugins.length === 0 ? (
                    <p css={tw`text-sm text-neutral-400 text-center py-8`}>{t('no_plugins')}</p>
                ) : (
                    <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-4`}>
                        {plugins.map((plugin) => (
                            <div key={plugin.id} css={tw`bg-neutral-800/60 rounded-ui p-4 flex gap-3`}>
                                {plugin.iconUrl ? (
                                    <img src={plugin.iconUrl} alt={''} css={tw`w-12 h-12 rounded`} />
                                ) : (
                                    <div css={tw`w-12 h-12 rounded bg-neutral-700`} />
                                )}
                                <div css={tw`flex-1 min-w-0`}>
                                    <div css={tw`flex items-center gap-2`}>
                                        <h3 css={tw`text-sm font-semibold truncate`}>{plugin.title}</h3>
                                        {plugin.disabled && (
                                            <span
                                                css={tw`text-2xs px-1.5 py-0.5 rounded bg-yellow-600/40 text-yellow-200`}
                                            >
                                                {t('disabled_badge')}
                                            </span>
                                        )}
                                    </div>
                                    <p css={tw`text-xs text-neutral-400`}>
                                        {plugin.provider} &middot; {t('version', { version: plugin.versionNumber })}
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
                                                {t('update')}
                                            </Button>
                                        )}
                                        <Button
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={!!busy}
                                            onClick={() => mutate(`toggle:${plugin.id}`, togglePlugin(uuid, plugin.id))}
                                        >
                                            {plugin.disabled ? t('enable') : t('disable')}
                                        </Button>
                                        <Button.Danger
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={!!busy}
                                            onClick={() => setConfirmRemove(plugin)}
                                        >
                                            {t('remove')}
                                        </Button.Danger>
                                    </div>
                                </div>
                            </div>
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
                            css={tw`w-40`}
                        >
                            <option value={'modrinth'}>Modrinth</option>
                            <option value={'hangar'}>Hangar</option>
                            <option value={'spiget'}>SpigotMC</option>
                        </Select>
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('search_placeholder') ?? ''}
                        />
                        <Button type={'submit'} disabled={searching}>
                            {t('search')}
                        </Button>
                    </form>

                    {!searching && hits.length === 0 ? (
                        <p css={tw`text-sm text-neutral-400 text-center py-8`}>{t('no_results')}</p>
                    ) : (
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-4`}>
                            {hits.map((hit) => (
                                <div key={hit.id} css={tw`bg-neutral-800/60 rounded-ui p-4 flex gap-3`}>
                                    {hit.iconUrl ? (
                                        <img src={hit.iconUrl} alt={''} css={tw`w-12 h-12 rounded`} />
                                    ) : (
                                        <div css={tw`w-12 h-12 rounded bg-neutral-700`} />
                                    )}
                                    <div css={tw`flex-1 min-w-0`}>
                                        <h3 css={tw`text-sm font-semibold truncate`}>{hit.title}</h3>
                                        <p css={tw`text-xs text-neutral-400`}>
                                            {hit.author && t('by', { author: hit.author })} &middot;{' '}
                                            {t('downloads', { count: hit.downloads })}
                                        </p>
                                        <p
                                            css={tw`text-xs text-neutral-300 mt-1 overflow-hidden`}
                                            style={{
                                                display: '-webkit-box',
                                                WebkitLineClamp: 2,
                                                WebkitBoxOrient: 'vertical',
                                            }}
                                        >
                                            {hit.description}
                                        </p>
                                        <div css={tw`mt-3`}>
                                            {hit.installedVersion ? (
                                                <span css={tw`text-xs text-green-400`}>
                                                    {t('installed_badge')} &middot;{' '}
                                                    {t('version', { version: hit.installedVersion })}
                                                </span>
                                            ) : (
                                                <Button.Success
                                                    size={Button.Sizes.Small}
                                                    disabled={!!busy}
                                                    onClick={() => install(hit)}
                                                >
                                                    {t('install')}
                                                </Button.Success>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {hits.length < total && (
                        <div css={tw`mt-4 text-center`}>
                            <Button
                                variant={Button.Variants.Secondary}
                                disabled={searching}
                                onClick={() => doSearch(hits.length)}
                            >
                                {t('load_more')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </ServerContentBlock>
    );
};

export default PluginsContainer;
