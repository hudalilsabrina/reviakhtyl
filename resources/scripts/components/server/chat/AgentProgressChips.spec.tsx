import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AgentProgressChips from '@/components/server/chat/AgentProgressChips';
import { AgentProgress } from '@/api/server/chat/types';

const agent = (overrides?: Partial<AgentProgress>): AgentProgress => ({
    uuid: 'msg-1',
    key: 'router',
    name: 'Router',
    status: 'running',
    summary: null,
    ...overrides,
});

describe('AgentProgressChips', () => {
    it('renders nothing for an empty list', () => {
        const { container } = render(<AgentProgressChips agents={[]} />);

        expect(container.innerHTML).toBe('');
    });

    it('shows a running agent with a spinner and no summary', () => {
        const { container } = render(<AgentProgressChips agents={[agent()]} />);

        const chip = screen.getByTitle('Router');
        expect(chip.textContent).toBe('Router');
        // The spinner is the only child that is not an icon.
        expect(chip.querySelector('svg')).toBeNull();
        expect(chip.querySelector('div')).toBeTruthy();
        expect(container.innerHTML).not.toContain('summary');
    });

    it('shows a complete agent with a checkmark and its summary as muted text', () => {
        render(<AgentProgressChips agents={[agent({ status: 'complete', summary: 'Answered in 3 calls' })]} />);

        const chip = screen.getByTitle('Router');
        expect(chip.querySelector('svg')).toBeTruthy();
        expect(chip.textContent).toContain('Router');
        expect(chip.textContent).toContain('Answered in 3 calls');
    });

    it('shows a failed agent with an x', () => {
        render(<AgentProgressChips agents={[agent({ status: 'failed', summary: 'Timed out' })]} />);

        const chip = screen.getByTitle('Router');
        expect(chip.querySelector('svg')).toBeTruthy();
        expect(chip.textContent).toContain('Timed out');
    });

    it('renders one chip per agent', () => {
        render(<AgentProgressChips agents={[agent(), agent({ key: 'scout', name: 'Scout', status: 'complete' })]} />);

        expect(screen.getByTitle('Router')).toBeInTheDocument();
        expect(screen.getByTitle('Scout')).toBeInTheDocument();
    });
});
