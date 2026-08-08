import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';
import { Badge } from './Badge';
import { DatapackIcon } from './DatapackIcon';
import { ServerDatapack, UntrackedZip } from './types';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

interface InstalledTabProps {
    datapacks: ServerDatapack[];
    untracked: UntrackedZip[];
    busy: string | null;
    onUpdate: (datapack: ServerDatapack) => void;
    onToggle: (datapack: ServerDatapack) => void;
    onRemove: (datapack: ServerDatapack) => void;
    onLink: (datapack: ServerDatapack) => void;
    onTrack: (zip: UntrackedZip) => void;
}

export const InstalledTab = ({
    datapacks,
    untracked,
    busy,
    onUpdate,
    onToggle,
    onRemove,
    onLink,
    onTrack,
}: InstalledTabProps) => {
    const { t } = useTranslation('server/datapacks');

    return (
        <>
            {untracked.length > 0 && (
                <div css={tw`mb-4`}>
                    <p css={tw`text-xs text-gray-400 mb-2`}>{t('untracked_title')}</p>
                    <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                        {untracked.map((zip) => (
                            <Card key={zip.file_name}>
                                <DatapackIcon url={null} />
                                <div css={tw`flex-1 min-w-0`}>
                                    <div css={tw`flex items-center gap-2 flex-wrap`}>
                                        <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{zip.title}</h3>
                                        <Badge $variant="manual">{t('manual_badge')}</Badge>
                                        {zip.pack_format && (
                                            <Badge $variant="disabled">
                                                {t('pack_format', { format: zip.pack_format })}
                                            </Badge>
                                        )}
                                    </div>
                                    <p css={tw`text-xs text-gray-500 mt-0.5`}>{zip.file_name}</p>
                                </div>
                                <div css={tw`flex-shrink-0`}>
                                    <Button size={Button.Sizes.Small} onClick={() => onTrack(zip)}>
                                        {t('track_button')}
                                    </Button>
                                </div>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            {datapacks.length === 0 ? (
                <p css={tw`text-sm text-gray-500 text-center py-6`}>{t('empty_installed')}</p>
            ) : (
                <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-3`}>
                    {datapacks.map((dp) => (
                        <Card key={dp.id}>
                            <DatapackIcon url={dp.iconUrl} />
                            <div css={tw`flex-1 min-w-0`}>
                                <div css={tw`flex items-center gap-2 flex-wrap`}>
                                    <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{dp.title}</h3>
                                    <Badge $variant={dp.disabled ? 'disabled' : 'provider'}>
                                        {dp.disabled ? t('disabled_badge') : dp.provider}
                                    </Badge>
                                </div>
                                <p css={tw`text-xs text-gray-500 mt-0.5`}>
                                    v{dp.versionNumber}
                                </p>
                                <p css={tw`text-xs text-gray-600 truncate`}>{dp.fileName}</p>
                            </div>
                            <div css={tw`flex flex-col gap-1 flex-shrink-0`}>
                                {dp.provider !== 'manual' && !dp.disabled && (
                                    <Button size={Button.Sizes.Small} variant={Button.Variants.Secondary} disabled={!!busy} onClick={() => onUpdate(dp)}>
                                        {busy === `update-${dp.id}` ? <Spinner size={'small'} /> : t('update_button')}
                                    </Button>
                                )}
                                {dp.provider === 'manual' && (
                                    <Button size={Button.Sizes.Small} variant={Button.Variants.Secondary} disabled={!!busy} onClick={() => onLink(dp)}>
                                        {busy === `link-${dp.id}` ? <Spinner size={'small'} /> : t('link_button')}
                                    </Button>
                                )}
                                <Button size={Button.Sizes.Small} variant={Button.Variants.Secondary} disabled={!!busy} onClick={() => onToggle(dp)}>
                                    {busy === `toggle-${dp.id}` ? <Spinner size={'small'} /> : dp.disabled ? t('enable_button') : t('disable_button')}
                                </Button>
                                <Button size={Button.Sizes.Small} variant={Button.Variants.Secondary} disabled={!!busy} onClick={() => onRemove(dp)}>
                                    {busy === `delete-${dp.id}` ? <Spinner size={'small'} /> : t('delete_button')}
                                </Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </>
    );
};
