import { format } from 'date-fns';
import { useTranslation } from 'react-i18next';
import { FaRobot, FaTriangleExclamation } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatMessage } from '@/api/server/chat/types';
import AgentProgressChips from '@/components/server/chat/AgentProgressChips';
import MessageActions from '@/components/server/chat/MessageActions';
import MessageContent from '@/components/server/chat/MessageContent';
import ReasoningDisclosure from '@/components/server/chat/ReasoningDisclosure';
import ToolCallChip from '@/components/server/chat/ToolCallChip';

const Bubble = styled.div<{ $role: ChatMessage['role']; $failed: boolean; $pending?: boolean }>`
    ${tw`rounded-ui border px-3 py-2 text-sm leading-relaxed max-w-full`};

    ${({ $role }) =>
        $role === 'user'
            ? tw`bg-primary-500/20 border-primary-500/30 text-gray-100`
            : tw`bg-gray-800/60 border-gray-700 text-gray-200`};

    ${({ $failed }) => $failed && tw`bg-red-500/10 border-red-500/40 text-red-200`};

    /* Sent, not yet acknowledged — visibly in flight rather than settled. */
    ${({ $pending }) => $pending && tw`opacity-60`};
`;

const Avatar = styled.div`
    ${tw`flex-shrink-0 w-7 h-7 rounded-full bg-gray-800 border border-gray-700 flex items-center justify-center text-gray-300`};
`;

interface Props {
    message: ChatMessage;
    streaming?: boolean;
    onRegenerate?: () => void;
    onDelete?: () => void;
}

const ChatMessageRow = ({ message, streaming = false, onRegenerate, onDelete }: Props) => {
    const { t } = useTranslation('server/chat');
    const isUser = message.role === 'user';
    const resolvedToolCalls = message.toolCalls.filter((toolCall) => toolCall.status !== 'pending');

    if (
        !message.content &&
        !message.reasoning &&
        resolvedToolCalls.length === 0 &&
        !message.agentRuns?.length &&
        message.status !== 'failed'
    ) {
        return null;
    }

    return (
        <div
            className="group"
            css={[tw`flex items-start gap-2 w-full`, isUser && tw`flex-row-reverse`]}
        >
            {!isUser && (
                <Avatar>
                    <FaRobot css={tw`w-3.5 h-3.5`} />
                </Avatar>
            )}
            <div css={[tw`flex flex-col gap-1.5 min-w-0 max-w-full sm:max-w-[80%]`, isUser && tw`items-end`]}>
                {message.reasoning && <ReasoningDisclosure reasoning={message.reasoning} />}
                {message.agentRuns && message.agentRuns.length > 0 && <AgentProgressChips agents={message.agentRuns} />}
                {resolvedToolCalls.length > 0 && (
                    <div css={tw`flex flex-wrap gap-1.5`}>
                        {resolvedToolCalls.map((toolCall) => (
                            <ToolCallChip key={toolCall.id} toolCall={toolCall} />
                        ))}
                    </div>
                )}

                <div css={tw`flex items-center gap-2`}>
                    <Bubble $role={message.role} $failed={message.status === 'failed'} $pending={streaming}>
                        <MessageContent content={message.content ?? ''} />
                        {message.status === 'failed' && (
                            <div css={tw`flex items-center gap-1.5 mt-1.5 text-xs text-red-300`}>
                                <FaTriangleExclamation css={tw`w-3 h-3`} />
                                <span>{t('response-failed')}</span>
                            </div>
                        )}
                    </Bubble>
                    <span css={tw`flex items-center gap-1 flex-shrink-0`}>
                        <span css={tw`text-2xs text-gray-500`}>
                            {message.pending ? t('sending') : format(message.createdAt, 'HH:mm')}
                        </span>
                        <MessageActions
                            message={message}
                            onRegenerate={onRegenerate}
                            onDelete={onDelete}
                        />
                    </span>
                </div>
            </div>
        </div>
    );
};

export default ChatMessageRow;
