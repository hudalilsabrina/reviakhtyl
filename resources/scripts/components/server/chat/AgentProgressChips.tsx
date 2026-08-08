import { FaCheck, FaXmark } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { AgentProgress } from '@/api/server/chat/types';
import Spinner from '@/reviactyl/elements/Spinner';

type Tone = 'success' | 'danger' | 'running';

const Chip = styled.span<{ $tone: Tone }>`
    ${tw`inline-flex items-center gap-1.5 px-2 py-1 text-xs font-medium rounded-ui border max-w-full`};

    ${({ $tone }) => $tone === 'success' && tw`bg-green-500/10 text-green-300 border-green-500/30`};
    ${({ $tone }) => $tone === 'danger' && tw`bg-red-500/10 text-red-300 border-red-500/30`};
    ${({ $tone }) => $tone === 'running' && tw`bg-gray-800/60 text-gray-300 border-gray-700`};
`;

const toneFor = (agent: AgentProgress): Tone =>
    agent.status === 'failed' ? 'danger' : agent.status === 'complete' ? 'success' : 'running';

interface Props {
    agents: AgentProgress[];
}

const AgentProgressChips = ({ agents }: Props) => {
    if (agents.length === 0) return null;

    return (
        <div css={tw`flex flex-wrap gap-1.5`}>
            {agents.map((agent) => (
                <Chip key={agent.key} $tone={toneFor(agent)} title={agent.name}>
                    {agent.status === 'running' && <Spinner size={Spinner.Size.SMALL} />}
                    {agent.status === 'complete' && <FaCheck css={tw`flex-shrink-0`} />}
                    {agent.status === 'failed' && <FaXmark css={tw`flex-shrink-0`} />}
                    <span css={tw`truncate`}>{agent.name}</span>
                    {agent.summary && <span css={tw`text-2xs text-gray-400 truncate`}>{agent.summary}</span>}
                </Chip>
            ))}
        </div>
    );
};

export default AgentProgressChips;
