import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FaTriangleExclamation } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatMessage } from '@/api/server/chat/types';
import { Button } from '@/reviactyl/elements/button/index';

const Panel = styled.div<{ $destructive: boolean }>`
    ${tw`rounded-ui border p-4`};
    ${({ $destructive }) =>
        $destructive ? tw`bg-red-500/10 border-red-500/50` : tw`bg-amber-500/10 border-amber-500/50`};
`;

const ActionRow = styled.li<{ $destructive: boolean }>`
    ${tw`flex items-start gap-2 text-sm rounded-ui border px-3 py-2 bg-gray-900/60`};
    ${({ $destructive }) => ($destructive ? tw`border-red-500/40 text-red-100` : tw`border-gray-700 text-gray-200`)};
`;

interface Props {
    message: ChatMessage;
    processing: boolean;
    onDecision: (approved: boolean) => void;
}

const PendingApproval = ({ message, processing, onDecision }: Props) => {
    const { t } = useTranslation('server/chat');
    const panelRef = useRef<HTMLDivElement>(null);

    const pending = message.toolCalls.filter((toolCall) => toolCall.status === 'pending');
    // The thread is blocked on the message status, not on the individual call statuses. If the
    // two ever disagree, showing nothing here would leave the conversation stuck with a disabled
    // composer and no way to answer, so fall back to describing every call on the message.
    const actions = pending.length > 0 ? pending : message.toolCalls;
    const destructive = actions.some((toolCall) => toolCall.destructive);

    // Whatever had focus in the composer is disabled the moment this appears, which drops focus
    // onto <body>. Pull it here instead so keyboard and screen reader users are told a decision
    // is waiting. The container is focused rather than a button — a stray Enter must not approve.
    useEffect(() => {
        panelRef.current?.focus();
    }, []);

    return (
        <Panel
            ref={panelRef}
            $destructive={destructive}
            tabIndex={-1}
            role='group'
            aria-label={t('approval-panel-aria')}
            aria-live='assertive'
        >
            <div
                css={[
                    tw`flex items-center gap-2 font-semibold text-sm mb-1`,
                    destructive ? tw`text-red-300` : tw`text-amber-300`,
                ]}
            >
                <FaTriangleExclamation css={tw`flex-shrink-0`} />
                <span>
                    {destructive ? t('approval-heading-destructive') : t('approval-heading-permission')}
                </span>
            </div>
            <p css={tw`text-xs text-gray-300 mb-3`}>
                {destructive ? t('approval-body-destructive') : t('approval-body-permission')}
            </p>

            <ul css={tw`space-y-2`}>
                {actions.map((toolCall) => (
                    <ActionRow key={toolCall.id} $destructive={toolCall.destructive}>
                        <span css={tw`flex-1 min-w-0 break-words`}>{toolCall.summary}</span>
                        {toolCall.destructive && (
                            <span
                                css={tw`flex-shrink-0 text-2xs uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded-ui bg-red-500/20 text-red-300 border border-red-500/40`}
                            >
                                {t('destructive-badge')}
                            </span>
                        )}
                    </ActionRow>
                ))}
            </ul>

            <div css={tw`flex flex-wrap items-center justify-end gap-2 mt-4`}>
                <Button.Text disabled={processing} onClick={() => onDecision(false)}>
                    {t('deny')}
                </Button.Text>
                {destructive ? (
                    <Button.Danger disabled={processing} onClick={() => onDecision(true)}>
                        {t('approve-and-run')}
                    </Button.Danger>
                ) : (
                    <Button.Success disabled={processing} onClick={() => onDecision(true)}>
                        {t('approve-and-run')}
                    </Button.Success>
                )}
            </div>
        </Panel>
    );
};

export default PendingApproval;
