import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import MessageContent from '@/components/server/chat/MessageContent';

describe('MessageContent', () => {
    it('renders a markdown table as an actual table', () => {
        render(
            <MessageContent
                content={
                    '| Resource | Usage |\n' + '|----------|-------|\n' + '| **CPU** | 2% |\n' + '| Disk | 24 MB |'
                }
            />
        );

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getAllByRole('row')).toHaveLength(3);
        expect(screen.getByRole('columnheader', { name: 'Resource' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Usage' })).toBeInTheDocument();
        expect(screen.getByRole('cell', { name: '2%' })).toBeInTheDocument();
        expect(screen.getByRole('cell', { name: '24 MB' })).toBeInTheDocument();
        // Bold inside a cell still renders through Md2React.
        expect(screen.getByRole('cell', { name: 'CPU' })).toBeInTheDocument();
    });

    it('leaves a pipe line without a separator row as plain text', () => {
        render(<MessageContent content={'This | is | not | a | table'} />);

        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.getByText('This | is | not | a | table')).toBeInTheDocument();
    });

    it('renders text around a table as paragraphs', () => {
        render(
            <MessageContent
                content={
                    'Here is the usage:\n' + '| CPU | Memory |\n' + '|-----|--------|\n' + '| 2%  | 1 GB  |\n' + 'Done.'
                }
            />
        );

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByText('Here is the usage:')).toBeInTheDocument();
        expect(screen.getByText('Done.')).toBeInTheDocument();
    });

    it('does not render tables inside code fences', () => {
        render(<MessageContent content={'```\n' + '| A | B |\n' + '|---|--|\n' + '| 1 | 2 |\n' + '```'} />);

        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.getByText(/\| A \| B \|/)).toBeInTheDocument();
    });
});
