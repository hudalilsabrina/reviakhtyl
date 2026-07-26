import { format } from 'date-fns';
import { FaRobot, FaTriangleExclamation } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatMessage } from '@/api/server/chat/types';
import MessageContent from '@/components/server/chat/MessageContent';
import ReasoningDisclosure from '@/components/server/chat/ReasoningDisclosure';
import ToolCallChip from '@/components/server/chat/ToolCallChip';

const Bubble = styled.div<{ $role: ChatMessage['role']; $failed: boolean }>`
    ${tw`rounded-ui border px-3 py-2 text-sm leading-relaxed max-w-full`};

    ${({ $role }) =>
        $role === 'user'
            ? tw`bg-primary-500/20 border-primary-500/30 text-gray-100`
            : tw`bg-gray-800/60 border-gray-700 text-gray-200`};

    ${({ $failed }) => $failed && tw`bg-red-500/10 border-red-500/40 text-red-200`};
`;

const Avatar = styled.div`
    ${tw`flex-shrink-0 w-7 h-7 rounded-full bg-gray-800 border border-gray-700 flex items-center justify-center text-gray-300`};
`;

interface Props {
    message: ChatMessage;
}

const ChatMessageRow = ({ message }: Props) => {
    const isUser = message.role === 'user';
    // Pending calls are surfaced by the approval panel instead, so they are skipped here to
    // avoid rendering the same tool call twice.
    const resolvedToolCalls = message.toolCalls.filter((toolCall) => toolCall.status !== 'pending');

    return (
        <div css={[tw`flex items-start gap-2 w-full`, isUser && tw`flex-row-reverse`]}>
            {!isUser && (
                <Avatar>
                    <FaRobot css={tw`w-3.5 h-3.5`} />
                </Avatar>
            )}
            <div css={[tw`flex flex-col gap-1.5 min-w-0 max-w-full sm:max-w-[80%]`, isUser && tw`items-end`]}>
                {message.reasoning && <ReasoningDisclosure reasoning={message.reasoning} />}
                {resolvedToolCalls.length > 0 && (
                    <div css={tw`flex flex-wrap gap-1.5`}>
                        {resolvedToolCalls.map((toolCall) => (
                            <ToolCallChip key={toolCall.id} toolCall={toolCall} />
                        ))}
                    </div>
                )}
                {message.content && (
                    <Bubble $role={message.role} $failed={message.status === 'failed'}>
                        {message.status === 'failed' && (
                            <div css={tw`flex items-center gap-1.5 text-xs font-semibold mb-1`}>
                                <FaTriangleExclamation />
                                <span>The assistant could not finish this response.</span>
                            </div>
                        )}
                        <MessageContent content={message.content} />
                    </Bubble>
                )}
                {!message.content && message.status === 'failed' && (
                    <Bubble $role={message.role} $failed>
                        <div css={tw`flex items-center gap-1.5 text-xs font-semibold`}>
                            <FaTriangleExclamation />
                            <span>The assistant could not finish this response.</span>
                        </div>
                    </Bubble>
                )}
                <span css={tw`text-2xs text-gray-500 px-1`}>{format(message.createdAt, 'HH:mm')}</span>
            </div>
        </div>
    );
};

export default ChatMessageRow;
