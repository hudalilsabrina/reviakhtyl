import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaChevronDown, FaTriangleExclamation } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatMessage } from '@/api/server/chat/types';
import { ChatDecision } from '@/api/server/chat/confirmToolCalls';
import { Button } from '@/reviactyl/elements/button/index';
import Spinner from '@/reviactyl/elements/Spinner';

const Panel = styled.div<{ $destructive: boolean }>`
    ${tw`rounded-ui border p-4`};
    ${({ $destructive }) =>
        $destructive ? tw`bg-red-500/10 border-red-500/50` : tw`bg-amber-500/10 border-amber-500/50`};
`;

const ActionRow = styled.li<{ $destructive: boolean; $denied: boolean }>`
    ${tw`flex flex-col gap-2 text-sm rounded-ui border px-3 py-2 bg-gray-900/60`};
    ${({ $destructive }) => ($destructive ? tw`border-red-500/40 text-red-100` : tw`border-gray-700 text-gray-200`)};
    ${({ $denied }) => $denied && tw`opacity-60`};
`;

const ArgsBlock = styled.pre`
    ${tw`text-2xs bg-gray-950/60 border border-gray-700/60 rounded-ui p-2 max-h-40`};
    white-space: pre-wrap;
    overflow-wrap: anywhere;
`;

const Chevron = styled(FaChevronDown)<{ $open: boolean }>`
    ${tw`w-2.5 h-2.5 transition-transform duration-150`};
    ${({ $open }) => $open && tw`rotate-180`};
`;

interface Props {
    message: ChatMessage;
    processing: boolean;
    onDecision: (decisions: ChatDecision[]) => void;
}

const PendingApproval = ({ message, processing, onDecision }: Props) => {
    const { t } = useTranslation('server/chat');
    const panelRef = useRef<HTMLDivElement>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);

    const pending = message.toolCalls.filter((toolCall) => toolCall.status === 'pending');
    // The thread is blocked on the message status, not on the individual call statuses. If the
    // two ever disagree, showing nothing here would leave the conversation stuck with a disabled
    // composer and no way to answer, so fall back to describing every call on the message.
    const actions = pending.length > 0 ? pending : message.toolCalls;
    const destructive = actions.some((toolCall) => toolCall.destructive);

    // Every action starts approved, matching the old one-click flow: the user
    // unchecks the ones they do not want.
    const [selection, setSelection] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(actions.map((toolCall) => [toolCall.id, true]))
    );
    const [openArgs, setOpenArgs] = useState<string | null>(null);

    const approvedCount = Object.values(selection).filter(Boolean).length;
    const allApproved = approvedCount === actions.length;
    const noneApproved = approvedCount === 0;

    const submit = useCallback(
        (decisions: ChatDecision[]) => {
            if (!processing) onDecision(decisions);
        },
        [onDecision, processing]
    );

    const submitSelection = () => submit(actions.map((toolCall) => ({ id: toolCall.id, approved: selection[toolCall.id] ?? false })));
    const denyAll = () => submit(actions.map((toolCall) => ({ id: toolCall.id, approved: false })));
    const approveAll = () => submit(actions.map((toolCall) => ({ id: toolCall.id, approved: true })));

    const handleKeyDown = useCallback(
        (event: React.KeyboardEvent) => {
            if (event.key !== 'Tab') return;

            const root = panelRef.current;
            if (!root) return;

            const focusable = root.querySelectorAll<HTMLElement>(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            );
            if (focusable.length === 0) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (!first || !last) return;

            if (event.shiftKey) {
                if (document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        },
        [],
    );

    // Whatever had focus in the composer is disabled the moment this appears, which drops focus
    // onto <body>. Save the previous target so we can restore it once the approval panel is gone.
    useEffect(() => {
        previousFocusRef.current = document.activeElement as HTMLElement | null;
        panelRef.current?.focus();

        return () => {
            previousFocusRef.current?.focus();
        };
    }, []);

    const decisionLabel = (toolCall: ChatMessage['toolCalls'][number]) =>
        selection[toolCall.id] ? t('approve-label') : t('deny-label');

    return (
        <Panel
            ref={panelRef}
            $destructive={destructive}
            tabIndex={-1}
            role='alertdialog'
            aria-label={t('approval-panel-aria')}
            aria-live='assertive'
            onKeyDown={handleKeyDown}
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
                    <ActionRow key={toolCall.id} $destructive={toolCall.destructive} $denied={!selection[toolCall.id]}>
                        <div css={tw`flex items-start gap-2 w-full`}>
                            <input
                                type='checkbox'
                                checked={selection[toolCall.id] ?? false}
                                disabled={processing}
                                onChange={(event) =>
                                    setSelection((current) => ({
                                        ...current,
                                        [toolCall.id]: event.target.checked,
                                    }))
                                }
                                aria-label={decisionLabel(toolCall)}
                                css={tw`mt-1 flex-shrink-0`}
                            />
                            <span css={tw`flex-1 min-w-0 break-words`}>{toolCall.summary}</span>
                            {toolCall.destructive && (
                                <span
                                    css={tw`flex-shrink-0 text-2xs uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded-ui bg-red-500/20 text-red-300 border border-red-500/40`}
                                >
                                    {t('destructive-badge')}
                                </span>
                            )}
                        </div>

                        {Object.keys(toolCall.arguments).length > 0 && (
                            <div css={tw`ml-6`}>
                                <button
                                    type='button'
                                    onClick={() => setOpenArgs(openArgs === toolCall.id ? null : toolCall.id)}
                                    aria-expanded={openArgs === toolCall.id}
                                    css={tw`flex items-center gap-1.5 text-2xs text-gray-500 hover:text-gray-300 transition-colors duration-100 px-0.5 py-0.5 rounded-ui`}
                                >
                                    <span>{openArgs === toolCall.id ? t('tool-call-hide-details') : t('tool-call-details')}</span>
                                    <Chevron $open={openArgs === toolCall.id} />
                                </button>
                                {openArgs === toolCall.id && (
                                    <div css={tw`mt-1.5`}>
                                        <div css={tw`text-2xs text-gray-400 font-medium mb-1`}>
                                            {t('tool-call-params')}
                                        </div>
                                        <ArgsBlock>{JSON.stringify(toolCall.arguments, null, 2)}</ArgsBlock>
                                    </div>
                                )}
                            </div>
                        )}
                    </ActionRow>
                ))}
            </ul>

            <div css={tw`flex flex-wrap items-center justify-between gap-2 mt-4`}>
                <div css={tw`flex gap-2`}>
                    <Button.Text disabled={processing || noneApproved} onClick={denyAll}>
                        {t('deny-all')}
                    </Button.Text>
                    <Button.Text disabled={processing || allApproved} onClick={approveAll}>
                        {t('approve-all')}
                    </Button.Text>
                </div>

                <div css={tw`flex items-center gap-2`}>
                    <span css={tw`text-2xs text-gray-400`}>
                        {t('selected-count', { count: approvedCount, total: actions.length })}
                    </span>
                    {destructive ? (
                        <Button.Danger disabled={processing || noneApproved} onClick={submitSelection}>
                            {processing ? <Spinner size={Spinner.Size.SMALL} /> : t('approve-selected')}
                        </Button.Danger>
                    ) : (
                        <Button.Success disabled={processing || noneApproved} onClick={submitSelection}>
                            {processing ? <Spinner size={Spinner.Size.SMALL} /> : t('approve-selected')}
                        </Button.Success>
                    )}
                </div>
            </div>
        </Panel>
    );
};

export default PendingApproval;
