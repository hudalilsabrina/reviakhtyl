import { useEffect, useState } from 'react';
import ContentBox from '@/reviactyl/elements/ContentBox';
import SpinnerOverlay from '@/reviactyl/elements/SpinnerOverlay';
import FlashMessageRender from '@/components/FlashMessageRender';
import PageContentBlock from '@/reviactyl/elements/PageContentBlock';
import tw from 'twin.macro';
import { Dialog } from '@/reviactyl/elements/dialog';
import { useFlashKey } from '@/plugins/useFlash';
import http from '@/api/http';
import Button from '@/reviactyl/elements/Button';
import { FaTelegram, FaLink, FaLinkSlash } from 'react-icons/fa6';

interface TelegramStatus {
    linked: boolean;
    telegram_id: string | null;
}

interface TelegramLinkResponse {
    token: string;
    link: string;
    expires_at: string;
}

export default () => {
    const [status, setStatus] = useState<TelegramStatus | null>(null);
    const [loading, setLoading] = useState(true);
    const [unlinkOpen, setUnlinkOpen] = useState(false);
    const [linkData, setLinkData] = useState<TelegramLinkResponse | null>(null);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('account:telegram');

    const loadStatus = () => {
        clearFlashes();
        setLoading(true);
        http.get('/api/client/account/telegram')
            .then(({ data }) => setStatus(data))
            .catch((error) => clearAndAddHttpError(error))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        loadStatus();
    }, []);

    const generateLink = () => {
        setLoading(true);
        clearFlashes();
        http.post('/api/client/account/telegram/generate-token')
            .then(({ data }) => {
                setLinkData(data);
            })
            .catch((error) => clearAndAddHttpError(error))
            .finally(() => setLoading(false));
    };

    const doUnlink = () => {
        setLoading(true);
        clearFlashes();
        http.post('/api/client/account/telegram/unlink')
            .then(() => {
                setStatus({ linked: false, telegram_id: null });
                setUnlinkOpen(false);
                loadStatus();
            })
            .catch((error) => clearAndAddHttpError(error))
            .finally(() => setLoading(false));
    };

    return (
        <PageContentBlock title={'Telegram'}>
            <FlashMessageRender byKey={'account:telegram'} />
            <div css={tw`w-full`}>
                <ContentBox title={'Telegram Integration'} css={tw`w-full`}>
                    <SpinnerOverlay visible={loading} />
                    <Dialog.Confirm
                        title={'Unlink Telegram'}
                        confirm={'Unlink'}
                        open={unlinkOpen}
                        onClose={() => setUnlinkOpen(false)}
                        onConfirmed={doUnlink}
                    >
                        Are you sure you want to unlink your Telegram account?
                    </Dialog.Confirm>

                    {!status ? (
                        <p css={tw`text-center text-sm`}>Loading...</p>
                    ) : status.linked ? (
                        <div>
                            <div css={tw`flex items-center justify-between mb-4`}>
                                <div css={tw`flex items-center`}>
                                    <FaTelegram css={tw`text-blue-400 text-3xl mr-3`} />
                                    <div>
                                        <p css={tw`text-sm font-medium`}>Telegram Connected</p>
                                        <p css={tw`text-xs text-gray-400`}>ID: {status.telegram_id}</p>
                                    </div>
                                </div>
                            </div>
                            <Button color='red' css={tw`w-full`} onClick={() => setUnlinkOpen(true)}>
                                <FaLinkSlash css={tw`mr-2 inline`} />
                                Unlink Telegram
                            </Button>
                        </div>
                    ) : linkData ? (
                        <div>
                            <p css={tw`text-sm mb-4`}>Click the button below to open Telegram and link your account:</p>
                            <a href={linkData.link} target='_blank' rel='noopener noreferrer'>
                                <Button color='green' css={tw`w-full`}>
                                    <FaTelegram css={tw`mr-2 inline`} />
                                    Open Telegram Bot
                                </Button>
                            </a>
                            <p css={tw`text-xs text-gray-400 mt-3 text-center`}>
                                Link expires at {new Date(linkData.expires_at).toLocaleString()}
                            </p>
                        </div>
                    ) : (
                        <div>
                            <p css={tw`text-sm mb-4`}>
                                Link your Telegram account to receive notifications and control your servers via Telegram.
                            </p>
                            <Button css={tw`w-full`} onClick={generateLink}>
                                <FaLink css={tw`mr-2 inline`} />
                                Link Telegram Account
                            </Button>
                        </div>
                    )}
                </ContentBox>
            </div>
        </PageContentBlock>
    );
};
