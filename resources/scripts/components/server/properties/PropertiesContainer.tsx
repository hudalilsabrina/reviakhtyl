import { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import { FaArrowsRotate, FaFileCirclePlus, FaMagnifyingGlass, FaScroll } from 'react-icons/fa6';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Card from '@/reviactyl/ui/Card';
import Spinner from '@/reviactyl/elements/Spinner';
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
import PropertyGroupCard from './PropertyGroupCard';
import RawEditorTab from './RawEditorTab';
import Banner from './Banner';
import { validateProperty } from './validation';

const FLASH_KEY = 'server:properties';

const Tab = styled.button<{ $active: boolean }>`
    ${tw`px-4 py-2 text-sm font-medium rounded-ui border transition-colors duration-150`};
    ${({ $active }) =>
        $active
            ? tw`bg-cyan-500/20 text-cyan-300 border-cyan-500/50`
            : tw`bg-gray-800/60 text-gray-400 border-gray-700 hover:border-gray-500 hover:text-gray-200`};
    &:disabled {
        ${tw`opacity-50 cursor-not-allowed hover:border-gray-700 hover:text-gray-400`};
    }
`;

const SaveBar = styled(Card)`
    ${tw`flex items-center justify-end gap-3 flex-wrap shadow-lg border-cyan-500/40 bg-gray-900`};
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
    const connected = ServerContext.useStoreState((state) => state.socket.connected);
    const instance = ServerContext.useStoreState((state) => state.socket.instance);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [tab, setTab] = useState<'form' | 'raw'>('form');
    const [query, setQuery] = useState('');
    const [expandedIds, setExpandedIds] = useState<string[]>([]);
    const [data, setData] = useState<ServerProperties | null>(null);
    const [values, setValues] = useState<Record<string, string>>({});
    const [saveError, setSaveError] = useState<string | null>(null);
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

    const invalid = useMemo(
        () =>
            (data?.groups || []).some((group) =>
                group.properties.some(
                    (definition) => validateProperty(definition, values[definition.key] ?? definition.default) !== null
                )
            ),
        [data, values]
    );

    // Losing a page of edits to a stray refresh is the one failure this form
    // cannot undo, so warn on unload while anything is pending.
    useEffect(() => {
        if (changeCount === 0) return;

        const listener = (e: BeforeUnloadEvent) => e.preventDefault();

        window.addEventListener('beforeunload', listener);

        return () => window.removeEventListener('beforeunload', listener);
    }, [changeCount]);

    const searching = query.trim().length > 0;

    const groups = useMemo(
        () =>
            (data?.groups || [])
                .map((group) => ({ ...group, properties: group.properties.filter((p) => matches(p, query)) }))
                .filter((group) => group.properties.length > 0),
        [data, query]
    );

    const allExpanded = groups.length > 0 && groups.every((group) => expandedIds.includes(group.id));

    const toggleGroup = (id: string) =>
        setExpandedIds((current) =>
            current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
        );

    /** Pull hidden edits back into view: drop the search, open every group holding one. */
    const revealChanges = () => {
        setQuery('');
        setExpandedIds(
            (data?.groups || [])
                .filter((group) => group.properties.some((p) => changes[p.key] !== undefined))
                .map((group) => group.id)
        );
    };

    const save = () => {
        if (changeCount === 0 || invalid) return;

        setSaving(true);
        setSaveError(null);
        clearFlashes(FLASH_KEY);
        updateProperties(uuid, changes)
            .then((state) => {
                load(state);
                setNeedsRestart(true);
                addFlash({ type: 'success', key: FLASH_KEY, message: t('saved') });
            })
            .catch((error) => setSaveError(httpErrorToHuman(error)))
            .finally(() => setSaving(false));
    };

    const saveRaw = (content: string) => {
        setSaving(true);
        setSaveError(null);
        clearFlashes(FLASH_KEY);
        updateRawProperties(uuid, content)
            .then((state) => {
                load(state);
                setNeedsRestart(true);
                addFlash({ type: 'success', key: FLASH_KEY, message: t('saved') });
            })
            .catch((error) => setSaveError(httpErrorToHuman(error)))
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

    const offline = status === 'offline' || status === null;

    const restart = () => {
        if (!instance) return;

        instance.send(SocketRequest.SET_STATE, offline ? 'start' : 'restart');
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

                    {!data.exists && (
                        <Banner tone={'warning'} icon={<FaFileCirclePlus />} title={t('missing_title')}>
                            {t('missing_file')}
                        </Banner>
                    )}

                    {needsRestart && (
                        <Banner
                            tone={'info'}
                            icon={<FaArrowsRotate />}
                            title={t('restart_title')}
                            action={
                                <Button
                                    size={Button.Sizes.Small}
                                    disabled={!connected || !instance}
                                    title={!connected ? t('restart_disconnected') : undefined}
                                    onClick={restart}
                                >
                                    {offline ? t('start_action') : t('restart_action')}
                                </Button>
                            }
                        >
                            {offline ? t('restart_offline') : t('restart_body')}
                        </Banner>
                    )}

                    <div role={'tablist'} css={tw`flex items-center gap-2`}>
                        <Tab
                            type={'button'}
                            role={'tab'}
                            aria-selected={tab === 'form'}
                            $active={tab === 'form'}
                            onClick={() => setTab('form')}
                        >
                            {t('tab_form')}
                        </Tab>
                        <Tab
                            type={'button'}
                            role={'tab'}
                            aria-selected={tab === 'raw'}
                            $active={tab === 'raw'}
                            // The raw editor renders the saved file, so switching with pending
                            // form edits would quietly write them away.
                            disabled={changeCount > 0}
                            title={changeCount > 0 ? t('raw_blocked') : undefined}
                            onClick={() => setTab('raw')}
                        >
                            {t('tab_raw')}
                        </Tab>
                    </div>

                    {tab === 'raw' ? (
                        <>
                            {saveError && <p css={tw`text-sm text-red-400`}>{saveError}</p>}
                            <RawEditorTab content={data.raw} saving={saving} onSave={saveRaw} />
                        </>
                    ) : (
                        <>
                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                <div css={tw`relative flex-1 min-w-[12rem]`}>
                                    <FaMagnifyingGlass
                                        css={tw`absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm`}
                                        aria-hidden={'true'}
                                    />
                                    <Input
                                        value={query}
                                        onChange={(e) => setQuery(e.currentTarget.value)}
                                        placeholder={t('search_placeholder')}
                                        aria-label={t('search_label')}
                                        css={tw`pl-9`}
                                    />
                                </div>
                                <Button.Text
                                    type={'button'}
                                    onClick={() => setExpandedIds(allExpanded ? [] : groups.map((g) => g.id))}
                                >
                                    {allExpanded ? t('collapse_all') : t('expand_all')}
                                </Button.Text>
                            </div>

                            {groups.length === 0 ? (
                                <Card>
                                    <p css={tw`text-sm text-gray-400`}>{t('no_results')}</p>
                                </Card>
                            ) : (
                                groups.map((group) => (
                                    <PropertyGroupCard
                                        key={group.id}
                                        group={group}
                                        values={values}
                                        changed={changes}
                                        expanded={searching || expandedIds.includes(group.id)}
                                        onToggle={toggleGroup}
                                        onChange={(key, value) =>
                                            setValues((current) => ({ ...current, [key]: value }))
                                        }
                                    />
                                ))
                            )}

                            {changeCount > 0 && (
                                <div css={tw`sticky bottom-4 z-10`}>
                                    <SaveBar>
                                        {saveError && (
                                            <span css={tw`flex-1 min-w-full sm:min-w-0 text-xs text-red-400`}>
                                                {saveError}
                                            </span>
                                        )}
                                        {invalid && !saveError && (
                                            <span css={tw`flex-1 min-w-full sm:min-w-0 text-xs text-red-400`}>
                                                {t('invalid')}
                                            </span>
                                        )}
                                        <Button.Text type={'button'} onClick={revealChanges}>
                                            {t('unsaved', { count: changeCount })}
                                        </Button.Text>
                                        <Button.Text
                                            type={'button'}
                                            disabled={saving}
                                            onClick={() => {
                                                setValues(baseline);
                                                setSaveError(null);
                                            }}
                                        >
                                            {t('discard')}
                                        </Button.Text>
                                        <Button disabled={saving || invalid} onClick={save}>
                                            {saving ? t('saving') : t('save')}
                                        </Button>
                                    </SaveBar>
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}
        </ServerContentBlock>
    );
};

export default PropertiesContainer;
