import { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Card from '@/reviactyl/ui/Card';
import Spinner from '@/reviactyl/elements/Spinner';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import Input from '@/reviactyl/elements/Input';
import Label from '@/reviactyl/elements/Label';
import Select from '@/reviactyl/elements/Select';
import { Button } from '@/reviactyl/elements/button/index';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import Modal from '@/reviactyl/elements/Modal';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import getSplits, { SplitChild, SplitsState } from '@/api/server/splits/getSplits';
import createSplit from '@/api/server/splits/createSplit';
import mergeSplit from '@/api/server/splits/mergeSplit';

const Stat = ({ label, value, unit }: { label: string; value: number | string; unit?: string }) => (
    <div>
        <p css={tw`text-xs uppercase tracking-wider text-gray-500`}>{label}</p>
        <p css={tw`text-lg font-semibold text-gray-100`}>
            {typeof value === 'number' ? value.toLocaleString() : value}
            {unit && <span css={tw`ml-1 text-sm font-normal text-gray-400`}>{unit}</span>}
        </p>
    </div>
);

const SplitterContainer = () => {
    const { t } = useTranslation('server/splitter');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [state, setState] = useState<SplitsState | null>(null);
    const [mergeTarget, setMergeTarget] = useState<SplitChild | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    const [name, setName] = useState('');
    const [splitCpu, setSplitCpu] = useState('');
    const [splitMemory, setSplitMemory] = useState('');
    const [splitDisk, setSplitDisk] = useState('');
    const [image, setImage] = useState('');
    const [envValues, setEnvValues] = useState<Record<string, string>>({});

    const openCreate = () => {
        if (state?.defaults) {
            setName(state.defaults.name);
            setImage(state.defaults.image);
            setEnvValues(Object.fromEntries(state.defaults.variables.map((v) => [v.envVariable, v.value])));
        }
        setCreateOpen(true);
    };

    const refresh = () =>
        getSplits(uuid)
            .then(setState)
            .catch((error) => addError({ key: 'server:splitter', message: httpErrorToHuman(error) }));

    useEffect(() => {
        clearFlashes('server:splitter');
        refresh().finally(() => setLoading(false));
    }, []);

    const numeric = useMemo(
        () => ({
            cpu: Number.parseInt(splitCpu, 10) || 0,
            memory: Number.parseInt(splitMemory, 10) || 0,
            disk: Number.parseInt(splitDisk, 10) || 0,
        }),
        [splitCpu, splitMemory, splitDisk]
    );

    const formError = useMemo(() => {
        if (!state) return null;
        if (numeric.cpu < 0 || numeric.memory < 0 || numeric.disk < 0) return t('create-error-positive');
        if (numeric.cpu === 0 && numeric.memory === 0 && numeric.disk === 0) return null;
        if (numeric.cpu < 1 || numeric.memory < 1 || numeric.disk < 1) return t('create-error-positive');
        if (
            numeric.cpu > state.remaining.cpu ||
            numeric.memory > state.remaining.memory ||
            numeric.disk > state.remaining.disk
        ) {
            return t('create-error-exceed');
        }

        return null;
    }, [numeric, state]);

    const canSubmit = !!state && name.trim().length > 0 && !formError && numeric.cpu > 0;

    const submit = () => {
        if (!canSubmit) return;
        setSubmitting(true);
        clearFlashes('server:splitter');
        createSplit(uuid, {
            name: name.trim(),
            ...numeric,
            image,
            environment: envValues,
        })
            .then(() => {
                addFlash({ type: 'success', key: 'server:splitter', message: t('created') });
                setName('');
                setSplitCpu('');
                setSplitMemory('');
                setSplitDisk('');
                setCreateOpen(false);
            })
            .catch((error) => addError({ key: 'server:splitter', message: httpErrorToHuman(error) }))
            .then(refresh)
            .finally(() => setSubmitting(false));
    };

    const merge = () => {
        if (!mergeTarget) return;
        setSubmitting(true);
        clearFlashes('server:splitter');
        mergeSplit(uuid, mergeTarget.uuid)
            .then(() => addFlash({ type: 'success', key: 'server:splitter', message: t('merged') }))
            .catch((error) => addError({ key: 'server:splitter', message: httpErrorToHuman(error) }))
            .then(refresh)
            .finally(() => {
                setSubmitting(false);
                setMergeTarget(null);
            });
    };

    const splittable = !!state && state.canSplit;
    const unavailableKey =
        state?.reason === 'child'
            ? 'unavailable-child'
            : state?.reason === 'max'
            ? 'unavailable-max'
            : 'unavailable-limit';

    return (
        <ServerContentBlock showFlashKey={'server:splitter'} title={t('title')}>
            {loading ? (
                <Spinner size={'large'} centered />
            ) : !state ? (
                <Card>
                    <p css={tw`text-center text-sm text-gray-400`}>{t('unavailable')}</p>
                </Card>
            ) : (
                <div css={tw`space-y-4`}>
                    <Card>
                        <p css={tw`text-sm text-gray-300 mb-4`}>{t('description')}</p>
                        <div css={tw`grid grid-cols-2 sm:grid-cols-4 gap-4`}>
                            <Stat label={t('cpu')} value={state.remaining.cpu} unit={'%'} />
                            <Stat label={t('memory')} value={state.remaining.memory} unit={'MB'} />
                            <Stat label={t('disk')} value={state.remaining.disk} unit={'MB'} />
                            <Stat label={t('children')} value={`${state.used} / ${state.splitLimit}`} />
                        </div>
                    </Card>

                    {splittable ? (
                        <>
                            <div css={tw`flex justify-end`}>
                                <Button onClick={openCreate}>{t('create')}</Button>
                            </div>

                            <Card css={tw`relative`}>
                                <SpinnerOverlay visible={submitting && !!mergeTarget} />
                                <p css={tw`text-sm font-semibold text-gray-100 mb-4`}>{t('children-title')}</p>
                                {state.children.length === 0 ? (
                                    <p css={tw`text-center text-sm text-gray-400`}>{t('no-children')}</p>
                                ) : (
                                    <div css={tw`divide-y divide-gray-700`}>
                                        {state.children.map((child) => (
                                            <div
                                                key={child.uuid}
                                                css={tw`py-3 flex items-center justify-between gap-4 flex-wrap`}
                                            >
                                                <div css={tw`min-w-0`}>
                                                    <p css={tw`text-sm font-medium text-gray-100 truncate`}>
                                                        {child.name}
                                                    </p>
                                                    <p css={tw`text-xs text-gray-500 font-mono`}>{child.identifier}</p>
                                                </div>
                                                <div css={tw`flex items-center gap-6`}>
                                                    <div css={tw`text-xs text-gray-400 text-right space-y-0.5`}>
                                                        <p>
                                                            {t('cpu')}: {child.cpu}%
                                                        </p>
                                                        <p>
                                                            {t('memory')}: {child.memory} MB
                                                        </p>
                                                        <p>
                                                            {t('disk')}: {child.disk} MB
                                                        </p>
                                                    </div>
                                                    <Button.Danger
                                                        className={'!px-3 !py-1.5 text-xs'}
                                                        onClick={() => setMergeTarget(child)}
                                                    >
                                                        {t('merge')}
                                                    </Button.Danger>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Card>

                            <Modal visible={createOpen} onDismissed={() => setCreateOpen(false)} size={'md'}>
                                <Card css={tw`relative`}>
                                    <SpinnerOverlay visible={submitting && createOpen} />
                                    <p css={tw`text-sm font-semibold text-gray-100 mb-4`}>{t('create-title')}</p>
                                    <div css={tw`grid grid-cols-1 sm:grid-cols-2 gap-4`}>
                                        <div css={tw`sm:col-span-2`}>
                                            <Label>{t('name-label')}</Label>
                                            <Input value={name} onChange={(e) => setName(e.currentTarget.value)} />
                                        </div>
                                        <div>
                                            <Label>{t('cpu-label')}</Label>
                                            <Input
                                                type={'number'}
                                                min={0}
                                                value={splitCpu}
                                                onChange={(e) => setSplitCpu(e.currentTarget.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label>{t('memory-label')}</Label>
                                            <Input
                                                type={'number'}
                                                min={0}
                                                value={splitMemory}
                                                onChange={(e) => setSplitMemory(e.currentTarget.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label>{t('disk-label')}</Label>
                                            <Input
                                                type={'number'}
                                                min={0}
                                                value={splitDisk}
                                                onChange={(e) => setSplitDisk(e.currentTarget.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label>{t('image-label')}</Label>
                                            <Select value={image} onChange={(e) => setImage(e.currentTarget.value)}>
                                                {(state.defaults?.dockerImages ?? []).map((img) => (
                                                    <option key={img} value={img}>
                                                        {img}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                        {(state.defaults?.variables ?? []).map((v) => (
                                            <div key={v.envVariable} title={v.description}>
                                                <Label>{v.name}</Label>
                                                <Input
                                                    value={envValues[v.envVariable] ?? ''}
                                                    disabled={!v.editable}
                                                    onChange={(e) =>
                                                        setEnvValues((prev) => ({
                                                            ...prev,
                                                            [v.envVariable]: e.currentTarget.value,
                                                        }))
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    {formError && <p css={tw`text-xs mt-3 text-red-400`}>{formError}</p>}
                                    <div css={tw`mt-6 flex justify-end gap-2`}>
                                        <Button.Text
                                            className={'!px-3 !py-1.5 text-xs'}
                                            onClick={() => setCreateOpen(false)}
                                        >
                                            {t('cancel')}
                                        </Button.Text>
                                        <Button disabled={!canSubmit || submitting} onClick={submit}>
                                            {t('create')}
                                        </Button>
                                    </div>
                                </Card>
                            </Modal>
                        </>
                    ) : (
                        <Card>
                            <p css={tw`text-center text-sm text-gray-400`}>{t(unavailableKey)}</p>
                        </Card>
                    )}

                    <ConfirmationModal
                        visible={!!mergeTarget}
                        title={t('merge-title')}
                        buttonText={t('merge-confirm')}
                        onConfirmed={merge}
                        onModalDismissed={() => setMergeTarget(null)}
                    >
                        {t('merge-message', { name: mergeTarget?.name ?? '' })}
                    </ConfirmationModal>
                </div>
            )}
        </ServerContentBlock>
    );
};

export default SplitterContainer;
