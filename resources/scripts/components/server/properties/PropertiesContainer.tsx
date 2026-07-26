import { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaArrowsRotate, FaMagnifyingGlass, FaScroll } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Card from '@/reviactyl/ui/Card';
import Spinner from '@/reviactyl/elements/Spinner';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import Input from '@/reviactyl/elements/Input';
import { Button } from '@/reviactyl/elements/button/index';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import { SocketRequest } from '@/components/server/events';
import {
    acceptEula,
    getProperties,
    updateProperties,
    updateRawProperties,
    PropertyDefinition,
    ServerProperties,
} from '@/api/server/properties/properties';
import PropertyField from './PropertyField';
import RawEditorTab from './RawEditorTab';
import Banner from './Banner';

const FLASH_KEY = 'server:properties';

const Tab = styled.button<{ $active: boolean }>`
    ${tw`px-4 py-2 text-sm font-medium rounded-ui border transition-colors duration-150`};
    ${({ $active }) =>
        $active
            ? tw`bg-cyan-500/20 text-cyan-300 border-cyan-500/50`
            : tw`bg-gray-800/60 text-gray-400 border-gray-700 hover:border-gray-500 hover:text-gray-200`};
`;

/**
 * The value a property has on disk, falling back to the Minecraft default for
 * keys the file does not list. Minecraft behaves the same way, and it means
 * resetting a field back to its default correctly counts as "not changed".
 */
const baselineFor = (data: ServerProperties): Record<string, string> => {
    const baseline: Record<string, string> = { ...data.values };

    data.groups.forEach((group) =>
        group.properties.forEach((definition) => {
            if (baseline[definition.key] === undefined) {
                baseline[definition.key] = definition.default;
            }
        })
    );

    return baseline;
};

const matches = (definition: PropertyDefinition, query: string): boolean => {
    if (!query) return true;

    const needle = query.toLowerCase();

    return (
        definition.key.toLowerCase().includes(needle) ||
        definition.label.toLowerCase().includes(needle) ||
        (definition.description || '').toLowerCase().includes(needle)
    );
};

const PropertiesContainer = () => {
    const { t } = useTranslation('server/properties');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const status = ServerContext.useStoreState((state) => state.status.value);
    const instance = ServerContext.useStoreState((state) => state.socket.instance);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [tab, setTab] = useState<'form' | 'raw'>('form');
    const [query, setQuery] = useState('');
    const [data, setData] = useState<ServerProperties | null>(null);
    const [values, setValues] = useState<Record<string, string>>({});
    const [needsRestart, setNeedsRestart] = useState(false);

    const load = (state: ServerProperties) => {
        setData(state);
        setValues(baselineFor(state));
    };

    useEffect(() => {
        clearFlashes(FLASH_KEY);
        getProperties(uuid)
            .then(load)
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    }, []);

    const baseline = useMemo(() => (data ? baselineFor(data) : {}), [data]);

    const changes = useMemo(() => {
        const changed: Record<string, string> = {};

        Object.keys(values).forEach((key) => {
            if (values[key] !== baseline[key]) {
                changed[key] = values[key] as string;
            }
        });

        return changed;
    }, [values, baseline]);

    const changeCount = Object.keys(changes).length;

    const groups = useMemo(
        () =>
            (data?.groups || [])
                .map((group) => ({ ...group, properties: group.properties.filter((p) => matches(p, query)) }))
                .filter((group) => group.properties.length > 0),
        [data, query]
    );

    const save = () => {
        if (changeCount === 0) return;

        setSaving(true);
        clearFlashes(FLASH_KEY);
        updateProperties(uuid, changes)
            .then((state) => {
                load(state);
                setNeedsRestart(true);
                addFlash({ type: 'success', key: FLASH_KEY, message: t('saved') });
            })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .finally(() => setSaving(false));
    };

    const saveRaw = (content: string) => {
        setSaving(true);
        clearFlashes(FLASH_KEY);
        updateRawProperties(uuid, content)
            .then((state) => {
                load(state);
                setNeedsRestart(true);
                addFlash({ type: 'success', key: FLASH_KEY, message: t('saved') });
            })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .finally(() => setSaving(false));
    };

    const onAcceptEula = () => {
        setSaving(true);
        clearFlashes(FLASH_KEY);
        acceptEula(uuid)
            .then(() => {
                setData((current) => (current ? { ...current, eulaAccepted: true } : current));
                addFlash({ type: 'success', key: FLASH_KEY, message: t('eula_accepted') });
            })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .finally(() => setSaving(false));
    };

    const restart = () => {
        instance?.send(SocketRequest.SET_STATE, status === 'offline' ? 'start' : 'restart');
        setNeedsRestart(false);
    };

    return (
        <ServerContentBlock showFlashKey={FLASH_KEY} title={t('title')}>
            {loading || !data ? (
                <Spinner size={'large'} centered />
            ) : (
                <div css={tw`space-y-4`}>
                    <p css={tw`text-sm text-gray-400`}>{t('subtitle')}</p>

                    {!data.eulaAccepted && (
                        <Banner
                            tone={'warning'}
                            icon={<FaScroll />}
                            title={t('eula_title')}
                            action={
                                <Button size={Button.Sizes.Small} disabled={saving} onClick={onAcceptEula}>
                                    {t('eula_action')}
                                </Button>
                            }
                        >
                            {t('eula_body')}{' '}
                            <a
                                href={'https://www.minecraft.net/eula'}
                                target={'_blank'}
                                rel={'noreferrer noopener'}
                                css={tw`underline hover:text-gray-100`}
                            >
                                {t('eula_link')}
                            </a>
                        </Banner>
                    )}

                    {needsRestart && (
                        <Banner
                            tone={'info'}
                            icon={<FaArrowsRotate />}
                            title={t('restart_title')}
                            action={
                                <Button size={Button.Sizes.Small} onClick={restart}>
                                    {t('restart_action')}
                                </Button>
                            }
                        >
                            {t('restart_body')}
                        </Banner>
                    )}

                    {!data.exists && <p css={tw`text-xs text-gray-400`}>{t('missing_file')}</p>}

                    <div css={tw`flex items-center gap-2`}>
                        <Tab type={'button'} $active={tab === 'form'} onClick={() => setTab('form')}>
                            {t('tab_form')}
                        </Tab>
                        <Tab type={'button'} $active={tab === 'raw'} onClick={() => setTab('raw')}>
                            {t('tab_raw')}
                        </Tab>
                    </div>

                    {tab === 'raw' ? (
                        <RawEditorTab content={data.raw} saving={saving} onSave={saveRaw} />
                    ) : (
                        <>
                            <div css={tw`relative`}>
                                <FaMagnifyingGlass
                                    css={tw`absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm`}
                                />
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.currentTarget.value)}
                                    placeholder={t('search_placeholder')}
                                    css={tw`pl-9`}
                                />
                            </div>

                            {groups.length === 0 ? (
                                <Card>
                                    <p css={tw`text-sm text-gray-400`}>{t('no_results')}</p>
                                </Card>
                            ) : (
                                groups.map((group) => (
                                    <Card key={group.id} css={tw`relative`}>
                                        <SpinnerOverlay visible={saving} />
                                        <h2 css={tw`text-sm font-semibold uppercase tracking-wider text-gray-300`}>
                                            {group.label}
                                        </h2>
                                        <div css={tw`divide-y divide-gray-800`}>
                                            {group.properties.map((definition) => (
                                                <PropertyField
                                                    key={definition.key}
                                                    definition={definition}
                                                    value={values[definition.key] ?? definition.default}
                                                    onChange={(key, value) =>
                                                        setValues((current) => ({ ...current, [key]: value }))
                                                    }
                                                />
                                            ))}
                                        </div>
                                    </Card>
                                ))
                            )}

                            <div css={tw`flex items-center justify-end gap-3`}>
                                {changeCount > 0 && (
                                    <>
                                        <span css={tw`text-xs text-gray-400`}>
                                            {t('unsaved', { count: changeCount })}
                                        </span>
                                        <Button.Text type={'button'} onClick={() => setValues(baseline)}>
                                            {t('discard')}
                                        </Button.Text>
                                    </>
                                )}
                                <Button disabled={changeCount === 0 || saving} onClick={save}>
                                    {saving ? t('saving') : t('save')}
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            )}
        </ServerContentBlock>
    );
};

export default PropertiesContainer;
