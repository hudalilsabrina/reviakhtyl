import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { FaCheck, FaCircle } from 'react-icons/fa6';
import Modal from '@/reviactyl/elements/Modal';
import Spinner from '@/reviactyl/elements/Spinner';
import { ProgressBar } from './ProgressBar';
import { useProgress } from './useProgress';
import { InstallProgress } from './types';

interface InstallProgressModalProps {
    installing: InstallProgress | null;
    onDismissed: () => void;
}

export const InstallProgressModal = ({ installing, onDismissed }: InstallProgressModalProps) => {
    const { t } = useTranslation('server/plugins');
    const progressWidth = useProgress(!!installing && installing.step < 3);

    const installSteps = [t('step_resolve'), t('step_download'), t('step_finish')];

    return (
        <Modal visible={!!installing} onDismissed={onDismissed} dismissable={false} size={'sm'}>
            {installing && (
                <>
                    <h2 css={tw`text-lg sm:text-xl font-semibold mb-1 truncate`}>
                        {installing.step >= 3
                            ? t('install_done', { title: installing.title })
                            : t('installing_title', { title: installing.title })}
                    </h2>
                    <div css={tw`space-y-2.5 my-4`}>
                        {installSteps.map((label, i) => {
                            const done = installing.step > i;
                            const current = installing.step === i;
                            const isLast = i === installSteps.length - 1;
                            return (
                                <div key={label} css={tw`flex items-center gap-2.5 text-sm`}>
                                    {done ? (
                                        <FaCheck style={{ color: '#4ade80', fontSize: '12px', flexShrink: 0 }} />
                                    ) : current ? (
                                        <Spinner size={'small'} />
                                    ) : (
                                        <FaCircle style={{ color: '#374151', fontSize: '8px', flexShrink: 0 }} />
                                    )}
                                    <span
                                        css={done || current ? tw`text-gray-200` : tw`text-gray-500`}
                                        style={done ? { color: '#9ca3af' } : undefined}
                                    >
                                        {isLast && done && installing.version
                                            ? t('step_finish_done', { version: installing.version })
                                            : label}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                    <ProgressBar>
                        <div style={{ width: `${installing.step >= 3 ? 100 : progressWidth}%` }} />
                    </ProgressBar>
                </>
            )}
        </Modal>
    );
};
