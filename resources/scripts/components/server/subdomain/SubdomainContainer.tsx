import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { useTranslation } from 'react-i18next';
import { Actions, useStoreActions } from 'easy-peasy';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import TitledGreyBox from '@/reviactyl/elements/TitledGreyBox';
import Spinner from '@/reviactyl/elements/Spinner';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import Input from '@/reviactyl/elements/Input';
import Label from '@/reviactyl/elements/Label';
import Select from '@/reviactyl/elements/Select';
import { Button } from '@/reviactyl/elements/button/index';
import ConfirmationModal from '@/reviactyl/elements/ConfirmationModal';
import { ServerContext } from '@/state/server';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import getServerSubdomain, { ServerSubdomain, SubdomainDomain } from '@/api/server/subdomain/getServerSubdomain';
import setServerSubdomain from '@/api/server/subdomain/setServerSubdomain';
import deleteServerSubdomain from '@/api/server/subdomain/deleteServerSubdomain';

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

    const submit = () => {
        setSubmitting(true);
        clearFlashes('server:subdomain');
        setServerSubdomain(uuid, value, domainId)
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

    const selectedDomain = domains.find((d) => d.id === domainId)?.domain;
    const dirty = value.trim() !== current?.subdomain || domainId !== current?.cloudflareDomainId;

    return (
        <ServerContentBlock showFlashKey={'server:subdomain'} title={t('title')}>
            {loading ? (
                <Spinner size={'large'} centered />
            ) : (
                <TitledGreyBox title={t('title')} css={tw`relative`}>
                    <SpinnerOverlay visible={submitting} />
                    <p css={tw`text-sm text-gray-300 mb-4`}>{t('description')}</p>

                    {current && (
                        <p css={tw`text-sm mb-4`}>
                            {t('current')}
                            <span css={tw`font-mono text-cyan-400`}>{current.fqdn}</span>
                        </p>
                    )}

                    <Label>{t('label')}</Label>
                    <div css={tw`flex items-center gap-2`}>
                        <Input
                            value={value}
                            onChange={(e) => setValue(e.currentTarget.value)}
                            placeholder={'my-server'}
                            maxLength={63}
                        />
                        <Select value={domainId} onChange={(e) => setDomainId(Number(e.currentTarget.value))}>
                            {domains.map((d) => (
                                <option key={d.id} value={d.id}>
                                    .{d.domain}
                                </option>
                            ))}
                        </Select>
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
                        <Button disabled={!value.trim() || !selectedDomain || !dirty} onClick={submit}>
                            {current ? t('update') : t('create')}
                        </Button>
                    </div>
                </TitledGreyBox>
            )}
        </ServerContentBlock>
    );
};

export default SubdomainContainer;
