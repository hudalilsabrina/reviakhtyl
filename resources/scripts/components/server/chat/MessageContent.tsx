import React from 'react';
import styled from 'styled-components';
import tw from 'twin.macro';
import Md2React from '@/reviactyl/ui/Md2React';

const CodeBlock = styled.pre`
    ${tw`font-mono text-xs bg-gray-950/70 border border-gray-800 rounded-ui px-3 py-2 my-2`};
    white-space: pre-wrap;
    overflow-wrap: anywhere;
`;

const InlineCode = styled.code`
    ${tw`font-mono text-xs bg-gray-950/70 border border-gray-800 rounded-ui px-1 py-0.5`};
`;

const Paragraph = styled.div`
    ${tw`whitespace-pre-wrap break-words`};
`;

const Table = styled.table`
    ${tw`w-full my-2 border-collapse text-xs`};
`;

const TableCell = styled.td`
    ${tw`px-2.5 py-1.5 border border-gray-700/70 align-top`};
    ${tw`whitespace-pre-wrap break-words`};
`;

const TableHead = styled.th`
    ${tw`px-2.5 py-1.5 border border-gray-700/70 text-left font-semibold bg-gray-900/60`};
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

/** A `|`-delimited line that could open or continue a markdown table. */
const isPipeRow = (line: string): boolean => /^\s*\|.*\|\s*$/.test(line);

/** The separator row between header and body: `|---|---|` (colons optional). */
const isSeparatorRow = (line: string): boolean =>
    /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(line) && line.includes('-');

/** Splits a pipe row into trimmed cells, ignoring the outer pipes. */
const cellsOf = (line: string): string[] =>
    line
        .trim()
        .replace(/^\||\|$/g, '')
        .split('|')
        .map((cell) => cell.trim());

/**
 * Renders a markdown table found at `lines[start]`. Returns null when the lines
 * there are not a table; otherwise the table node and how many lines it consumed.
 */
const parseTable = (
    lines: string[],
    start: number,
    keyPrefix: string
): { node: React.ReactNode; lineCount: number } | null => {
    if (!isPipeRow(lines[start] ?? '') || !isSeparatorRow(lines[start + 1] ?? '')) {
        return null;
    }

    const header = cellsOf(lines[start]!);
    const rows: string[][] = [];

    for (let i = start + 2; i < lines.length && isPipeRow(lines[i]!); i++) {
        rows.push(cellsOf(lines[i]!));
    }

    // A header with no body rows is not a table worth turning into one.
    if (rows.length === 0) {
        return null;
    }

    return {
        node: (
            <Table key={`${keyPrefix}-table-${start}`}>
                <thead>
                    <tr>
                        {header.map((cell, index) => (
                            <TableHead key={`${keyPrefix}-th-${index}`}>
                                {renderInline(cell, `${keyPrefix}-th-${index}`)}
                            </TableHead>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, rowIndex) => (
                        <tr key={`${keyPrefix}-tr-${rowIndex}`}>
                            {row.map((cell, index) => (
                                <TableCell key={`${keyPrefix}-td-${rowIndex}-${index}`}>
                                    {renderInline(cell, `${keyPrefix}-td-${rowIndex}-${index}`)}
                                </TableCell>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </Table>
        ),
        lineCount: 2 + rows.length,
    };
};

/**
 * Splits one fenced-out segment into paragraphs and tables, walking the lines
 * so a table is never split across two paragraphs.
 */
const renderSegment = (text: string, keyPrefix: string): React.ReactNode[] => {
    const lines = text.split('\n');
    const nodes: React.ReactNode[] = [];
    let index = 0;

    while (index < lines.length) {
        const table = parseTable(lines, index, keyPrefix);

        if (table) {
            nodes.push(table.node);
            index += table.lineCount;
            continue;
        }

        const start = index;

        while (index < lines.length && !isPipeRow(lines[index]!)) {
            index++;
        }

        nodes.push(
            <Paragraph key={`${keyPrefix}-para-${start}`}>
                {renderInline(lines.slice(start, index).join('\n'), `${keyPrefix}-para-${start}`)}
            </Paragraph>
        );
    }

    return nodes;
};

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

            return renderSegment(segment, `segment-${index}`);
        })}
    </>
);

export default MessageContent;
