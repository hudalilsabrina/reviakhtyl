import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { FaCheck, FaDownload, FaListUl, FaMagnifyingGlass } from 'react-icons/fa6';
import Select from '@/reviactyl/elements/Select';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';
import { Badge } from './Badge';
import { PluginIcon } from './PluginIcon';
import { PluginHit, PluginProvider, PluginSort, ServerPlugin } from './types';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

interface BrowseTabProps {
    provider: PluginProvider;
    sort: PluginSort;
    query: string;
    hits: PluginHit[];
    total: number;
    searching: boolean;
    busy: string | null;
    linkingPlugin: ServerPlugin | null;
    onProviderChange: (provider: PluginProvider) => void;
    onSortChange: (sort: PluginSort) => void;
    onQueryChange: (query: string) => void;
    onSearch: () => void;
    onLoadMore: () => void;
    onInstall: (hit: PluginHit) => void;
    onOpenVersions: (hit: PluginHit) => void;
    onCancelLink: () => void;
}

export const BrowseTab = ({
    provider,
    sort,
    query,
    hits,
    total,
    searching,
    busy,
    linkingPlugin,
    onProviderChange,
    onSortChange,
    onQueryChange,
    onSearch,
    onLoadMore,
    onInstall,
    onOpenVersions,
    onCancelLink,
}: BrowseTabProps) => {
    const { t } = useTranslation('server/plugins');

    return (
        <>
            {linkingPlugin && (
                <div
                    css={tw`mb-4 p-3 bg-blue-900/30 border border-blue-700/50 rounded-ui flex items-center justify-between`}
                >
                    <div>
                        <p css={tw`text-sm font-semibold text-blue-200`}>Linking: {linkingPlugin.title}</p>
                        <p css={tw`text-xs text-blue-300/80 mt-0.5`}>
                            Select a plugin below to link it to this manual plugin for updates
                        </p>
                    </div>
                    <Button size={Button.Sizes.Small} variant={Button.Variants.Secondary} onClick={onCancelLink}>
                        Cancel
                    </Button>
                </div>
            )}
            <form
                css={tw`flex flex-wrap gap-2 mb-4`}
                onSubmit={(e) => {
                    e.preventDefault();
                    onSearch();
                }}
            >
                <div css={tw`relative flex-1 flex items-center`} style={{ minWidth: '200px' }}>
                    <FaMagnifyingGlass
                        css={tw`absolute left-3 text-gray-500 text-sm pointer-events-none`}
                        style={{ top: '50%', transform: 'translateY(-50%)' }}
                    />
                    <input
                        value={query}
                        onChange={(e) => onQueryChange(e.target.value)}
                        placeholder={t('search_placeholder') ?? ''}
                        css={tw`w-full bg-gray-900 border border-gray-700 rounded-ui pl-9 pr-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-reviactyl focus:outline-none transition-colors`}
                    />
                </div>
                <div css={tw`flex gap-2 w-full sm:w-auto`}>
                    <Select
                        value={provider}
                        onChange={(e) => onProviderChange(e.target.value as PluginProvider)}
                        css={tw`flex-1 sm:flex-none sm:w-32`}
                    >
                        <option value={'modrinth'}>Modrinth</option>
                        <option value={'hangar'}>Hangar</option>
                        <option value={'spiget'}>SpigotMC</option>
                    </Select>
                    <Select
                        value={sort}
                        onChange={(e) => onSortChange(e.target.value as PluginSort)}
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
                                            onClick={() => onInstall(hit)}
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
                                        onClick={() => onOpenVersions(hit)}
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
                    <Button variant={Button.Variants.Secondary} disabled={searching} onClick={onLoadMore}>
                        {searching ? <Spinner size={'small'} /> : t('load_more')}
                    </Button>
                </div>
            )}
        </>
    );
};
