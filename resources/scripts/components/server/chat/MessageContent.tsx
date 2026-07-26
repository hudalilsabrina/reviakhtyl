import React from 'react';
import styled from 'styled-components';
import tw from 'twin.macro';
import Md2React from '@/reviactyl/ui/Md2React';

const CodeBlock = styled.pre`
    ${tw`font-mono text-xs bg-gray-950/70 border border-gray-800 rounded-ui px-3 py-2 my-2 overflow-x-auto`};
    white-space: pre;
`;

const InlineCode = styled.code`
    ${tw`font-mono text-xs bg-gray-950/70 border border-gray-800 rounded-ui px-1 py-0.5`};
`;

const Paragraph = styled.div`
    ${tw`whitespace-pre-wrap break-words`};
`;

/**
 * Renders the inline portion of a message. `@/reviactyl/ui/Md2React` is the only markdown
 * renderer bundled with the panel — it handles bold and links — so backtick spans are split
 * out here before handing the remainder over to it.
 */
const renderInline = (text: string, keyPrefix: string): React.ReactNode[] =>
    text
        .split(/`([^`\n]+)`/g)
        .map((part, index) =>
            index % 2 === 1 ? (
                <InlineCode key={`${keyPrefix}-code-${index}`}>{part}</InlineCode>
            ) : (
                <Md2React key={`${keyPrefix}-text-${index}`} markdown={part} />
            )
        );

interface Props {
    content: string;
}

const MessageContent = ({ content }: Props) => (
    <>
        {content.split(/```/g).map((segment, index) => {
            if (index % 2 === 1) {
                // Drop the language hint that normally follows the opening fence.
                const body = segment.replace(/^[a-zA-Z0-9_-]*\n/, '').replace(/\n$/, '');

                return <CodeBlock key={`fence-${index}`}>{body}</CodeBlock>;
            }

            if (segment.length === 0) return null;

            return <Paragraph key={`para-${index}`}>{renderInline(segment, `para-${index}`)}</Paragraph>;
        })}
    </>
);

export default MessageContent;
