import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    FaBan,
    FaCheck,
    FaChevronDown,
    FaHourglassHalf,
    FaXmark,
} from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatToolCall } from '@/api/server/chat/types';

type Tone = 'success' | 'danger' | 'muted' | 'pending';

const Chip = styled.span<{ $tone: Tone }>`
    ${tw`inline-flex items-center gap-1.5 px-2 py-1 text-xs font-medium rounded-ui border max-w-full`};

    ${({ $tone }) => $tone === 'success' && tw`bg-green-500/10 text-green-300 border-green-500/30`};
    ${({ $tone }) => $tone === 'danger' && tw`bg-red-500/10 text-red-300 border-red-500/30`};
    ${({ $tone }) => $tone === 'muted' && tw`bg-gray-800/60 text-gray-400 border-gray-700`};
    ${({ $tone }) => $tone === 'pending' && tw`bg-amber-500/10 text-amber-300 border-amber-500/30`};
`;

const toneFor = (toolCall: ChatToolCall): Tone => {
    if (toolCall.status === 'pending') return 'pending';
    if (toolCall.status === 'denied') return 'muted';
    if (toolCall.status === 'failed' || toolCall.ok === false) return 'danger';

    return 'success';
};

const Toggle = styled.button`
    ${tw`flex items-center gap-1.5 text-2xs text-gray-500 hover:text-gray-300 transition-colors duration-100 px-1 py-0.5 rounded-ui`};

    &:focus-visible {
        ${tw`outline-none ring-1 ring-primary-400`};
    }
`;

const Chevron = styled(FaChevronDown)<{ $open: boolean }>`
    ${tw`w-2.5 h-2.5 transition-transform duration-150`};
    ${({ $open }) => $open && tw`rotate-180`};
`;

const ArgsBlock = styled.pre`
    ${tw`text-2xs bg-gray-900/60 border border-gray-700/60 rounded-ui p-2 max-h-48`};
    white-space: pre-wrap;
    overflow-wrap: anywhere;
`;

const ResultSuccess = styled.div`
    ${tw`border border-green-500/40 bg-green-500/5 rounded-ui p-2`};
`;

const ResultFailure = styled.div`
    ${tw`border border-red-500/40 bg-red-500/5 rounded-ui p-2`};
`;

const ResultPending = styled.div`
    ${tw`text-2xs text-gray-500 italic`};
`;

interface Props {
    toolCall: ChatToolCall;
}

const ToolCallChip = ({ toolCall }: Props) => {
    const { t } = useTranslation('server/chat');
    const [open, setOpen] = useState(false);
    const tone = toneFor(toolCall);

    const argsJson =
        Object.keys(toolCall.arguments).length === 0
            ? '(none)'
            : JSON.stringify(toolCall.arguments, null, 2);

    const renderResult = () => {
        if (toolCall.result == null) {
            return <ResultPending>{t('tool-call-no-result')}</ResultPending>;
        }

        if (toolCall.result.ok === true) {
            const { ok: _ok, ...rest } = toolCall.result as Record<string, unknown>;
            const entries = Object.entries(rest);
            if (entries.length === 0) {
                return (
                    <ResultSuccess>
                        {t('tool-call-empty-result')}
                    </ResultSuccess>
                );
            }

            return (
                <ResultSuccess>
                    <div css={tw`text-2xs text-green-300 font-medium mb-1`}>
                        {t('tool-call-result-success')}
                    </div>
                    <ArgsBlock>
                        {entries
                            .map(
                                ([key, value]) =>
                                    `${key}: ${typeof value === 'string' ? value : JSON.stringify(value, null, 2)}`,
                            )
                            .join('\n')}
                    </ArgsBlock>
                </ResultSuccess>
            );
        }

        const failedResult = toolCall.result as { error?: string; note?: string };
        const message = failedResult.error || failedResult.note || t('tool-call-result-failure');

        return (
            <ResultFailure>
                <div css={tw`text-2xs text-red-300 font-medium mb-1`}>
                    {t('tool-call-result-failure')}
                </div>
                <ArgsBlock>{message}</ArgsBlock>
            </ResultFailure>
        );
    };

    return (
        <span css={tw`inline-flex flex-col`}>
            <Chip $tone={tone} title={toolCall.name}>
                {tone === 'success' && <FaCheck css={tw`flex-shrink-0`} />}
                {tone === 'danger' && <FaXmark css={tw`flex-shrink-0`} />}
                {tone === 'muted' && <FaBan css={tw`flex-shrink-0`} />}
                {tone === 'pending' && <FaHourglassHalf css={tw`flex-shrink-0`} />}
                <span css={tw`truncate`}>{toolCall.summary}</span>
            </Chip>
            <div css={tw`mt-1.5 ml-1`}>
                <Toggle
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                >
                    <span>{open ? t('tool-call-hide-details') : t('tool-call-details')}</span>
                    <Chevron $open={open} />
                </Toggle>
                {open && (
                    <div css={tw`mt-1.5 ml-1 space-y-1.5`}>
                        <div>
                            <div css={tw`text-2xs text-gray-400 font-medium mb-1`}>
                                {t('tool-call-params')}
                            </div>
                            <ArgsBlock>{argsJson}</ArgsBlock>
                        </div>
                        <div>
                            <div css={tw`text-2xs text-gray-400 font-medium mb-1`}>
                                {t('tool-call-result')}
                            </div>
                            {renderResult()}
                        </div>
                    </div>
                )}
            </div>
        </span>
    );
};

export default ToolCallChip;
