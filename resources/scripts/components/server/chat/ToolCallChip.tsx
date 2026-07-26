import { FaBan, FaCheck, FaHourglassHalf, FaXmark } from 'react-icons/fa6';
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

interface Props {
    toolCall: ChatToolCall;
}

const ToolCallChip = ({ toolCall }: Props) => {
    const tone = toneFor(toolCall);

    return (
        <Chip $tone={tone} title={toolCall.name}>
            {tone === 'success' && <FaCheck css={tw`flex-shrink-0`} />}
            {tone === 'danger' && <FaXmark css={tw`flex-shrink-0`} />}
            {tone === 'muted' && <FaBan css={tw`flex-shrink-0`} />}
            {tone === 'pending' && <FaHourglassHalf css={tw`flex-shrink-0`} />}
            <span css={tw`truncate`}>{toolCall.summary}</span>
        </Chip>
    );
};

export default ToolCallChip;
