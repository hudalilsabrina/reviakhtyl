import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaCheck, FaPuzzlePiece } from 'react-icons/fa6';
import Modal from '@/reviactyl/elements/Modal';
import Spinner from '@/reviactyl/elements/Spinner';
import { Button } from '@/reviactyl/elements/button/index';
import { Badge } from './Badge';
import { PluginHit, PluginVersion, PluginDependency } from './types';

interface MissingDep {
    projectId: string;
    required: boolean;
    info?: Omit<PluginDependency, 'required'>;
}

interface VersionPickerModalProps {
    hit: PluginHit | null;
    versions: PluginVersion[] | null;
    dependencies: Record<string, Omit<PluginDependency, 'required'>>;
    installedRow: string | null;
    busy: string | null;
    onInstall: (hit: PluginHit, version: PluginVersion, missing: MissingDep[]) => void;
    onInstallDep: (dep: MissingDep) => void;
    onDismiss: () => void;
}

export const VersionPickerModal = ({
    hit,
    versions,
    dependencies,
    installedRow,
    busy,
    onInstall,
    onInstallDep,
    onDismiss,
}: VersionPickerModalProps) => {
    const { t } = useTranslation('server/plugins');

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
                                const missing = version.dependencies
                                    .map((d) => ({ ...d, info: dependencies[d.projectId] }))
                                    .filter((d) => d.info && !d.info.installed);
                                const requiredCount = missing.filter((d) => d.required).length;

                                return (
                                    <div key={version.id} css={tw`py-2.5`}>
                                        <div css={tw`flex items-center gap-2`}>
                                            <div css={tw`flex-1 min-w-0`}>
                                                <p css={tw`text-sm font-semibold text-gray-100 truncate`}>
                                                    {version.versionNumber}
                                                </p>
                                                <p css={tw`text-xs text-gray-500 truncate`}>
                                                    {version.gameVersions.length > 0 && version.gameVersions.join(', ')}
                                                    {version.gameVersions.length > 0 &&
                                                        version.loaders.length > 0 &&
                                                        ' · '}
                                                    {version.loaders.join(', ')}
                                                </p>
                                            </div>
                                            {installedRow === version.id ? (
                                                <Badge $variant={'installed'}>
                                                    <FaCheck style={{ fontSize: '9px' }} />
                                                    <span css={tw`font-mono`}>{version.versionNumber}</span>
                                                </Badge>
                                            ) : (
                                                <Button.Success
                                                    size={Button.Sizes.Small}
                                                    disabled={!!busy}
                                                    onClick={() => onInstall(hit, version, missing)}
                                                >
                                                    {busy === `install:${hit.id}` ? (
                                                        <Spinner size={'small'} />
                                                    ) : requiredCount > 0 ? (
                                                        t('install_with_deps', { count: requiredCount })
                                                    ) : (
                                                        t('install')
                                                    )}
                                                </Button.Success>
                                            )}
                                        </div>
                                        {missing.length > 0 && (
                                            <div css={tw`mt-1.5 flex flex-wrap gap-1.5`}>
                                                {missing.map((d) => {
                                                    const chip = (
                                                        <>
                                                            {d.info!.iconUrl ? (
                                                                <img
                                                                    src={d.info!.iconUrl}
                                                                    alt={''}
                                                                    css={tw`w-3.5 h-3.5 rounded-sm`}
                                                                />
                                                            ) : (
                                                                <FaPuzzlePiece style={{ fontSize: '10px' }} />
                                                            )}
                                                            {d.info!.title}
                                                            <span
                                                                style={{
                                                                    color: d.required ? '#fbbf24' : '#6b7280',
                                                                    fontSize: '10px',
                                                                }}
                                                            >
                                                                {d.required ? t('dep_required') : t('dep_optional')}
                                                            </span>
                                                        </>
                                                    );

                                                    return d.required ? (
                                                        <span
                                                            key={d.projectId}
                                                            css={tw`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-gray-800 border border-gray-700 text-gray-300`}
                                                        >
                                                            {chip}
                                                        </span>
                                                    ) : (
                                                        <button
                                                            key={d.projectId}
                                                            type={'button'}
                                                            title={t('dep_install_optional') ?? ''}
                                                            disabled={!!busy}
                                                            onClick={() => onInstallDep(d)}
                                                            css={tw`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-gray-800 border border-gray-700 text-gray-300 hover:border-reviactyl hover:text-gray-100 transition-colors disabled:opacity-50`}
                                                        >
                                                            {busy === `dep:${d.projectId}` ? (
                                                                <Spinner size={'small'} />
                                                            ) : (
                                                                chip
                                                            )}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
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
