import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { FaCheck, FaPuzzlePiece, FaTrash } from 'react-icons/fa6';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';
import { Badge } from './Badge';
import { PluginIcon } from './PluginIcon';
import { ServerPlugin, UntrackedJar } from './types';

const Card = styled.div`
    ${tw`bg-gray-900 border border-gray-800 rounded-ui p-3 sm:p-4 flex gap-3 sm:gap-4 transition-colors duration-150 hover:border-gray-700`}
`;

interface InstalledTabProps {
    plugins: ServerPlugin[];
    untracked: UntrackedJar[];
    busy: string | null;
    onUpdate: (plugin: ServerPlugin) => void;
    onToggle: (plugin: ServerPlugin) => void;
    onRemove: (plugin: ServerPlugin) => void;
    onLink: (plugin: ServerPlugin) => void;
    onTrack: (jar: UntrackedJar) => void;
}

export const InstalledTab = ({
    plugins,
    untracked,
    busy,
    onUpdate,
    onToggle,
    onRemove,
    onLink,
    onTrack,
}: InstalledTabProps) => {
    const { t } = useTranslation('server/plugins');

    return (
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
                                        <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{jar.title}</h3>
                                        <Badge $variant={'manual'}>{t('manual_badge')}</Badge>
                                    </div>
                                    <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>{jar.file_name}</p>
                                    <div css={tw`flex gap-2 mt-3`}>
                                        <Button
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={!!busy}
                                            onClick={() => onTrack(jar)}
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
                                    <h3 css={tw`text-sm font-semibold text-gray-100 truncate`}>{plugin.title}</h3>
                                    <Badge $variant={plugin.provider === 'manual' ? 'manual' : 'provider'}>
                                        {plugin.provider === 'manual' ? t('manual_badge') : plugin.provider}
                                    </Badge>
                                    {plugin.disabled && <Badge $variant={'disabled'}>{t('disabled_badge')}</Badge>}
                                </div>
                                <p css={tw`text-xs text-gray-400 mt-0.5 font-mono truncate`}>{plugin.fileName}</p>
                                <p css={tw`text-xs text-gray-500 mt-1 flex items-center gap-1.5`}>
                                    <Badge $variant={'installed'}>
                                        <FaCheck style={{ fontSize: '9px' }} />
                                        <span css={tw`font-mono`}>{plugin.versionNumber}</span>
                                    </Badge>
                                </p>
                                <div css={tw`flex gap-2 mt-3 flex-wrap`}>
                                    {plugin.provider === 'manual' && (
                                        <Button
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={!!busy}
                                            onClick={() => onLink(plugin)}
                                        >
                                            {t('link')}
                                        </Button>
                                    )}
                                    {!plugin.disabled && plugin.provider !== 'manual' && (
                                        <Button
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={!!busy}
                                            onClick={() => onUpdate(plugin)}
                                        >
                                            {busy === `update:${plugin.id}` ? <Spinner size={'small'} /> : t('update')}
                                        </Button>
                                    )}
                                    <Button
                                        size={Button.Sizes.Small}
                                        variant={Button.Variants.Secondary}
                                        disabled={!!busy}
                                        onClick={() => onToggle(plugin)}
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
                                        onClick={() => onRemove(plugin)}
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
    );
};
