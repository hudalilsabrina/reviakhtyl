import { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Card from '@/reviactyl/ui/Card';
import Spinner from '@/reviactyl/elements/Spinner';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import Input from '@/reviactyl/elements/Input';
import Label from '@/reviactyl/elements/Label';
import CopyOnClick from '@/reviactyl/elements/CopyOnClick';
import { Button } from '@/reviactyl/elements/button/index';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import getServerSubdomain, { ServerSubdomain, SubdomainDomain } from '@/api/server/subdomain/getServerSubdomain';
import setServerSubdomain from '@/api/server/subdomain/setServerSubdomain';
import deleteServerSubdomain from '@/api/server/subdomain/deleteServerSubdomain';
import getSubdomainStatus from '@/api/server/subdomain/getSubdomainStatus';
import { FaCheckCircle, FaClock, FaGlobe } from 'react-icons/fa';

const HeroCard = styled(Card)`
    ${tw`relative overflow-hidden`};
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.12) 0%, rgba(17, 24, 39, 1) 60%);
    border-color: rgba(6, 182, 212, 0.25);
`;

const StatusBadge = styled.span<{ $active: boolean }>`
    ${tw`inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-ui border`};
    ${({ $active }) =>
        $active
            ? tw`bg-success/20 text-green-300 border-success/30`
            : tw`bg-amber-500/20 text-amber-300 border-amber-500/30`};
`;

const DomainChip = styled.button<{ $selected: boolean }>`
    ${tw`px-3 py-1.5 text-xs font-medium rounded-ui border transition-colors duration-150`};
    ${({ $selected }) =>
        $selected
            ? tw`bg-cyan-500/20 text-cyan-300 border-cyan-500/50`
            : tw`bg-gray-800/60 text-gray-400 border-gray-700 hover:border-gray-500 hover:text-gray-200`};
`;

const slugify = (value: string): string =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);

const SubdomainContainer = () => {
    const { t } = useTranslation('server/subdomain');
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const getServer = ServerContext.useStoreActions((actions) => actions.server.getServer);
    const { addError, addFlash, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [domains, setDomains] = useState<SubdomainDomain[]>([]);
    const [domainId, setDomainId] = useState<number>(0);
    const [value, setValue] = useState('');
    const [current, setCurrent] = useState<ServerSubdomain | null>(null);
    const [propagated, setPropagated] = useState<boolean | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        clearFlashes('server:subdomain');
        getServerSubdomain(uuid)
            .then((state) => {
                setDomains(state.domains);
                setCurrent(state.subdomain);
                setValue(state.subdomain?.subdomain ?? state.suggested);
                setDomainId(state.subdomain?.cloudflareDomainId ?? state.domains[0]?.id ?? 0);
            })
            .catch((error) => addError({ key: 'server:subdomain', message: httpErrorToHuman(error) }))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (!current) {
            setPropagated(null);
            return;
        }

        const check = () =>
            getSubdomainStatus(uuid)
                .then(setPropagated)
                .catch(() => setPropagated(false));

        check();
        pollRef.current = setInterval(check, 30000);

        return () => {
            if (pollRef.current) clearInterval(pollRef.current);
        };
    }, [current?.id, current?.fqdn]);

    const selectedDomain = useMemo(() => domains.find((d) => d.id === domainId), [domains, domainId]);

    const preview = useMemo(() => {
        const slug = slugify(value);
        return slug && selectedDomain ? `${slug}.${selectedDomain.domain}` : null;
    }, [value, selectedDomain]);

    const validationHint = useMemo(() => {
        const raw = value.trim();
        if (!raw) return t('hint-empty');
        const slug = slugify(raw);
        if (!slug) return t('hint-invalid');
        if (raw !== slug) return null; // slugified — preview shows result
        return null;
    }, [value]);

    const dirty = slugify(value) !== (current?.subdomain ?? '') || domainId !== current?.cloudflareDomainId;

    const submit = () => {
        setSubmitting(true);
        clearFlashes('server:subdomain');
        setServerSubdomain(uuid, slugify(value), domainId)
            .then((subdomain) => {
                setCurrent(subdomain);
                setValue(subdomain.subdomain);
                addFlash({ type: 'success', key: 'server:subdomain', message: t('saved') });
                getServer(uuid);
            })
            .catch((error) => addError({ key: 'server:subdomain', message: httpErrorToHuman(error) }))
            .finally(() => setSubmitting(false));
    };

    const remove = () => {
        setSubmitting(true);
        clearFlashes('server:subdomain');
        deleteServerSubdomain(uuid)
            .then(() => {
                setCurrent(null);
                setValue('');
                addFlash({ type: 'success', key: 'server:subdomain', message: t('deleted') });
                getServer(uuid);
            })
            .catch((error) => addError({ key: 'server:subdomain', message: httpErrorToHuman(error) }))
            .finally(() => {
                setSubmitting(false);
                setConfirmDelete(false);
            });
    };

    return (
        <ServerContentBlock showFlashKey={'server:subdomain'} title={t('title')}>
            {loading ? (
                <Spinner size={'large'} centered />
            ) : (
                <div css={tw`space-y-4`}>
                    {current && (
                        <HeroCard>
                            <div css={tw`flex items-center justify-between gap-4 flex-wrap`}>
                                <div css={tw`min-w-0`}>
                                    <div
                                        css={tw`flex items-center gap-2 text-xs uppercase tracking-wider text-cyan-300/80 mb-1`}
                                    >
                                        <FaGlobe />
                                        <span>{t('your-address')}</span>
                                    </div>
                                    <CopyOnClick text={current.fqdn}>
                                        <span
                                            css={tw`font-mono text-2xl sm:text-3xl text-gray-50 break-all cursor-pointer hover:text-cyan-300 transition-colors`}
                                        >
                                            {current.fqdn}
                                        </span>
                                    </CopyOnClick>
                                </div>
                                <StatusBadge $active={propagated === true}>
                                    {propagated === true ? (
                                        <>
                                            <FaCheckCircle /> {t('status-active')}
                                        </>
                                    ) : (
                                        <>
                                            <FaClock /> {t('status-pending')}
                                        </>
                                    )}
                                </StatusBadge>
                            </div>
                            {propagated === false && (
                                <p css={tw`text-xs text-amber-400/80 mt-3`}>{t('propagation-note')}</p>
                            )}
                        </HeroCard>
                    )}

                    <Card css={tw`relative`}>
                        <SpinnerOverlay visible={submitting} />
                        <p css={tw`text-sm text-gray-300 mb-4`}>{t('description')}</p>

                        <Label>{t('label')}</Label>
                        <Input
                            value={value}
                            onChange={(e) => setValue(e.currentTarget.value)}
                            placeholder={'my-server'}
                            maxLength={63}
                        />

                        {preview && (
                            <p css={tw`text-xs mt-2 text-gray-400`}>
                                {t('preview')} <span css={tw`font-mono text-cyan-300`}>{preview}</span>
                            </p>
                        )}
                        {validationHint && <p css={tw`text-xs mt-2 text-red-400`}>{validationHint}</p>}

                        <Label css={tw`mt-5`}>{t('domain-label')}</Label>
                        <div css={tw`flex flex-wrap gap-2`}>
                            {domains.map((d) => (
                                <DomainChip
                                    key={d.id}
                                    type={'button'}
                                    $selected={d.id === domainId}
                                    onClick={() => setDomainId(d.id)}
                                >
                                    .{d.domain}
                                </DomainChip>
                            ))}
                        </div>

                        <div css={tw`mt-6 flex justify-end gap-2`}>
                            {current && (
                                <>
                                    <ConfirmationModal
                                        visible={confirmDelete}
                                        title={t('delete-title')}
                                        buttonText={t('delete-confirm')}
                                        onConfirmed={remove}
                                        onModalDismissed={() => setConfirmDelete(false)}
                                    >
                                        {t('delete-message', { fqdn: current.fqdn })}
                                    </ConfirmationModal>
                                    <Button.Danger onClick={() => setConfirmDelete(true)}>{t('remove')}</Button.Danger>
                                </>
                            )}
                            <Button disabled={!preview || !dirty} onClick={submit}>
                                {current ? t('update') : t('create')}
                            </Button>
                        </div>
                    </Card>
                </div>
            )}
        </ServerContentBlock>
    );
};

export default SubdomainContainer;
