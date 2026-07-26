import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';

type Tone = 'warning' | 'info';

const Wrapper = styled.div<{ $tone: Tone }>`
    ${tw`flex items-start gap-3 p-4 rounded-ui border`};
    ${({ $tone }) =>
        $tone === 'warning' ? tw`bg-amber-500/10 border-amber-500/30` : tw`bg-cyan-500/10 border-cyan-500/30`};
`;

interface Props {
    tone: Tone;
    icon: React.ReactNode;
    title: string;
    children: React.ReactNode;
    action?: React.ReactNode;
}

const Banner = ({ tone, icon, title, children, action }: Props) => (
    <Wrapper $tone={tone}>
        <span css={[tw`mt-0.5 flex-none`, tone === 'warning' ? tw`text-amber-400` : tw`text-cyan-300`]}>{icon}</span>
        <div css={tw`flex-1 min-w-0`}>
            <p css={tw`text-sm text-gray-100`}>{title}</p>
            <div css={tw`text-xs text-gray-300 mt-1`}>{children}</div>
        </div>
        {action && <div css={tw`flex-none`}>{action}</div>}
    </Wrapper>
);

export default Banner;
