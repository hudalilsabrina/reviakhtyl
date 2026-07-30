import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import { fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import ToolCallChip from '@/components/server/chat/ToolCallChip';
import { ChatToolCall } from '@/api/server/chat/types';

// Mock i18next so useTranslation returns the key as the translation.
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string) => key,
    }),
}));

const baseCall = (overrides?: Partial<ChatToolCall>): ChatToolCall => ({
    id: 'call_123',
    name: 'ListFiles',
    summary: 'List files in /config',
    status: 'executed',
    ok: true,
    destructive: false,
    arguments: { path: '/config', limit: 50 },
    result: { ok: true, entries: ['server.properties', 'ops.json'] },
    ...overrides,
});

/** The toggle is the only <button> inside ToolCallChip. */
const toggle = () => screen.getByRole('button');

describe('ToolCallChip', () => {
    it('renders chip with summary text and title attribute', () => {
        render(<ToolCallChip toolCall={baseCall()} />);
        const chip = screen.getByTitle('ListFiles');
        expect(chip.textContent).toBe('List files in /config');
    });

    it('starts collapsed — details body is not visible', () => {
        render(<ToolCallChip toolCall={baseCall()} />);
        expect(screen.queryByText('tool-call-params')).not.toBeInTheDocument();
        expect(screen.queryByText('tool-call-result')).not.toBeInTheDocument();
    });

    it('expands on toggle click and shows parameters and result', async () => {
        render(<ToolCallChip toolCall={baseCall()} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.getByText('tool-call-params')).toBeInTheDocument();

        const argsBlock = screen.getByText('tool-call-params').closest('div')?.nextElementSibling;
        expect(argsBlock?.textContent).toContain('/config');
        expect(argsBlock?.textContent).toContain('50');

        expect(screen.getByText('tool-call-result')).toBeInTheDocument();
        expect(screen.getByText('tool-call-result-success')).toBeInTheDocument();
    });

    it('collapses on second toggle click', async () => {
        render(<ToolCallChip toolCall={baseCall()} />);

        await act(async () => {
            fireEvent.click(toggle());
        });
        expect(screen.getByText('tool-call-params')).toBeInTheDocument();

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.queryByText('tool-call-params')).not.toBeInTheDocument();
    });

    it('shows (none) when arguments are empty', async () => {
        render(<ToolCallChip toolCall={baseCall({ arguments: {} })} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        // (none) is rendered inside the <pre> ArgsBlock when arguments is empty.
        expect(screen.getByText('(none)')).toBeInTheDocument();
    });

    it('shows failure message when result.ok is false', async () => {
        render(<ToolCallChip toolCall={baseCall({
            result: { ok: false, error: 'permission denied' },
        })} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.getByText('tool-call-result-failure')).toBeInTheDocument();
        expect(screen.getByText('permission denied')).toBeInTheDocument();
    });

    it('shows note instead of error when result.ok is false with note', async () => {
        render(<ToolCallChip toolCall={baseCall({
            result: { ok: false, note: 'Only 3 of 5 calls were run.' },
        })} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.getByText('tool-call-result-failure')).toBeInTheDocument();
        expect(screen.getByText('Only 3 of 5 calls were run.')).toBeInTheDocument();
    });

    it('shows (no additional data) when result.ok is true with no extra keys', async () => {
        render(<ToolCallChip toolCall={baseCall({
            result: { ok: true },
        })} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.getByText('tool-call-empty-result')).toBeInTheDocument();
    });

    it('shows not-yet-available message when result is null', async () => {
        render(<ToolCallChip toolCall={baseCall({ result: null })} />);

        await act(async () => {
            fireEvent.click(toggle());
        });

        expect(screen.getByText('tool-call-no-result')).toBeInTheDocument();
    });

    it('renders each status with the correct icon SVG', () => {
        const statuses: { status: ChatToolCall['status']; ok: ChatToolCall['ok'] }[] = [
            { status: 'pending', ok: null },
            { status: 'executed', ok: true },
            { status: 'failed', ok: false },
            { status: 'denied', ok: null },
        ];

        for (const { status, ok } of statuses) {
            const { container } = render(
                <ToolCallChip toolCall={baseCall({ status, ok })} />,
            );
            const chip = container.querySelector('span[title="ListFiles"]');
            expect(chip).toBeTruthy();
            expect(chip!.textContent).toBe('List files in /config');
        }
    });

    it('uses the tool name as the title attribute', () => {
        render(<ToolCallChip toolCall={baseCall({ name: 'MyCustomTool' })} />);
        const chip = screen.getByTitle('MyCustomTool');
        expect(chip.tagName).toBe('SPAN');
    });
});
