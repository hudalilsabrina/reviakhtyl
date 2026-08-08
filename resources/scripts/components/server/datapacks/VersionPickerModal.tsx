import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaCube } from 'react-icons/fa6';
import Modal from '@/reviactyl/elements/Modal';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';

interface VersionPickerModalProps {
    hit: {
        id: string;
        title: string;
        iconUrl: string | null;
    } | null;
    versions: any[] | null;
    installedRow: string | null;
    busy: string | null;
    onInstall: (hit: { id: string; title: string; iconUrl: string | null }, version: any) => void;
    onDismiss: () => void;
}

export const VersionPickerModal = ({
    hit,
    versions,
    installedRow,
    busy,
    onInstall,
    onDismiss,
}: VersionPickerModalProps) => {
    const { t } = useTranslation('server/datapacks');

    return (
        <Modal visible={!!hit} onDismissed={onDismiss} size={'lg'}>
            {hit && (
                <>
                    <h2 css={tw`text-xl sm:text-2xl mb-1 truncate`}>{hit.title}</h2>
                    <p css={tw`text-sm text-gray-400 mb-4 sm:mb-6`}>{t('pick_version')}</p>
                    {!versions ? (
                        <Spinner centered />
                    ) : versions.length === 0 ? (
                        <p css={tw`text-sm text-gray-500 text-center py-6`}>{t('no_results')}</p>
                    ) : (
                        <div css={tw`overflow-y-auto max-h-96 divide-y divide-gray-800`}>
                            {versions.map((version) => {
                                const current = version.id === installedRow;

                                return (
                                    <div
                                        key={version.id}
                                        className={
                                            'flex items-center gap-3 p-3 transition-colors duration-150 ' +
                                            (current ? 'bg-gray-800/80' : 'hover:bg-gray-800/40')
                                        }
                                    >
                                        <div css={tw`flex-1 min-w-0`}>
                                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                                <span css={tw`text-sm font-medium text-gray-100`}>{version.name}</span>
                                                <span css={tw`text-xs text-gray-500`}>{version.version_number}</span>
                                            </div>
                                            <div css={tw`flex items-center gap-2 mt-1 flex-wrap`}>
                                                {current && (
                                                    <span css={tw`inline-flex items-center text-xs text-green-400`}>
                                                        <FaCube style={{ fontSize: '10px', marginRight: '4px' }} />
                                                        {t('installed_label')}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <Button
                                            size={Button.Sizes.Small}
                                            onClick={() => onInstall(hit, version)}
                                            disabled={busy === version.id}
                                        >
                                            {current ? t('update_button') : t('install_button')}
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </>
            )}
        </Modal>
    );
};
