import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { FaMagnifyingGlass } from 'react-icons/fa6';
import Select from '@/reviactyl/elements/Select';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';
import { Badge } from './Badge';
import { DatapackIcon } from './DatapackIcon';
import { DatapackHit, DatapackProvider, DatapackSort } from './types';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

interface BrowseTabProps {
    provider: DatapackProvider;
    sort: DatapackSort;
    query: string;
    hits: DatapackHit[];
    searching: boolean;
    onProviderChange: (provider: DatapackProvider) => void;
    onSortChange: (sort: DatapackSort) => void;
    onQueryChange: (query: string) => void;
    onSearch: () => void;
    onOpenVersions: (hit: DatapackHit) => void;
}

export const BrowseTab = ({
    provider,
    sort,
    query,
    hits,
    searching,
    onProviderChange,
    onSortChange,
    onQueryChange,
    onSearch,
    onOpenVersions,
}: BrowseTabProps) => {
    const { t } = useTranslation('server/datapacks');

    return (
        <>
            <div css={tw`flex flex-col sm:flex-row gap-2 mb-4`}>
                <div css={tw`flex-1 flex gap-2`}>
                    <div css={tw`flex-1`}>
                        <Select
                            value={provider}
                            onChange={(e) => onProviderChange(e.target.value as DatapackProvider)}
                        >
                            <option value="modrinth">Modrinth</option>
                            <option value="curseforge">CurseForge</option>
                        </Select>
                    </div>
                    <div css={tw`w-40`}>
                        <Select value={sort} onChange={(e) => onSortChange(e.target.value as DatapackSort)}>
                            <option value="relevance">{t('sort_relevance')}</option>
                            <option value="downloads">{t('sort_downloads')}</option>
                            <option value="updated">{t('sort_updated')}</option>
                        </Select>
                    </div>
                </div>
                <div css={tw`flex gap-2`}>
                    <input
                        type="text"
                        css={tw`flex-1 bg-gray-900 border border-gray-800 rounded-ui px-3 py-1.5 text-sm text-gray-100 outline-none focus:border-gray-600`}
                        value={query}
                        onChange={(e) => onQueryChange(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && onSearch()}
                        placeholder={t('search_placeholder')}
                    />
                    <Button onClick={onSearch} isLoading={searching}>
                        {searching ? <Spinner size={'small'} /> : <FaMagnifyingGlass />}
                    </Button>
                </div>
            </div>

            {searching && hits.length === 0 ? (
                <Spinner centered />
            ) : hits.length === 0 ? (
                <p css={tw`text-sm text-gray-500 text-center py-6`}>{t('no_results')}</p>
            ) : (
                <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                    {hits.map((hit) => (
                        <Card key={hit.id}>
                            <DatapackIcon url={hit.iconUrl} />
                            <div css={tw`flex-1 min-w-0`}>
                                <div css={tw`flex items-center gap-2 flex-wrap`}>
                                    <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{hit.title}</h3>
                                    {hit.installedVersion && (
                                        <Badge $variant="provider">{t('installed_badge', { version: hit.installedVersion })}</Badge>
                                    )}
                                </div>
                                <p css={tw`text-xs text-gray-500 mt-0.5`}>{hit.description}</p>
                            </div>
                            <div css={tw`flex flex-col gap-1 flex-shrink-0`}>
                                <Button size={Button.Sizes.Small} onClick={() => onOpenVersions(hit)}>
                                    {t('install_button')}
                                </Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </>
    );
};
