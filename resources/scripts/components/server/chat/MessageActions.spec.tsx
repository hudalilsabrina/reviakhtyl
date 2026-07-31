import '@testing-library/jest-dom';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MessageActions from '@/components/server/chat/MessageActions';
import { ChatMessage } from '@/api/server/chat/types';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/plugins/useFlash', () => ({
    useFlashKey: () => ({ addError: vi.fn() }),
}));

const msg = (overrides?: Partial<ChatMessage>): ChatMessage => ({
    uuid: 'msg-1',
    role: 'assistant',
    content: 'Hello!',
    reasoning: null,
    status: 'complete',
    toolCalls: [],
    createdAt: new Date('2026-07-26T12:00:00+00:00'),
    ...overrides,
});

describe('MessageActions', () => {
    it('returns nothing with no callbacks', () => {
        const { container } = render(<MessageActions message={msg()} />);
        expect(container.innerHTML).toBe('');
    });

    it('shows copy + regenerate for assistant, delete for user', () => {
        render(<MessageActions message={msg({ role: 'user', content: 'hi' })} onCopy={() => {}} onDelete={() => {}} />);
        expect(screen.getByTitle('copy-message')).toBeInTheDocument();
        expect(screen.getByTitle('delete-message')).toBeInTheDocument();
        expect(screen.queryByTitle('regenerate-message')).not.toBeInTheDocument();
    });

    it('hides copy when content is null', () => {
        render(<MessageActions message={msg({ content: null })} onCopy={() => {}} onRegenerate={() => {}} />);
        expect(screen.queryByTitle('copy-message')).not.toBeInTheDocument();
        expect(screen.getByTitle('regenerate-message')).toBeInTheDocument();
    });

    it('copies to clipboard and flips to checkmark', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(globalThis, 'navigator', { value: { clipboard: { writeText } } });

        render(<MessageActions message={msg({ content: 'copy me' })} onCopy={() => {}} />);

        await act(async () => { fireEvent.click(screen.getByTitle('copy-message')); });
        expect(writeText).toHaveBeenCalledWith('copy me');
        expect(screen.getByTitle('copy-message-success')).toBeInTheDocument();
    });

    it('resets copied state after timeout', async () => {
        vi.useFakeTimers();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(globalThis, 'navigator', { value: { clipboard: { writeText } } });

        render(<MessageActions message={msg({ content: 'x' })} onCopy={() => {}} />);

        await act(async () => { fireEvent.click(screen.getByTitle('copy-message')); });
        expect(screen.getByTitle('copy-message-success')).toBeInTheDocument();

        await act(async () => { vi.advanceTimersByTime(1600); });
        expect(screen.getByTitle('copy-message')).toBeInTheDocument();

        vi.useRealTimers();
    });

    it('fires onRegenerate callback', async () => {
        const onRegenerate = vi.fn();
        render(<MessageActions message={msg({ role: 'assistant', status: 'complete' })} onRegenerate={onRegenerate} />);

        await act(async () => { fireEvent.click(screen.getByTitle('regenerate-message')); });
        expect(onRegenerate).toHaveBeenCalledOnce();
    });

    it('fires onDelete callback', async () => {
        const onDelete = vi.fn();
        render(<MessageActions message={msg({ role: 'user' })} onDelete={onDelete} />);

        await act(async () => { fireEvent.click(screen.getByTitle('delete-message')); });
        expect(onDelete).toHaveBeenCalledOnce();
    });
});
