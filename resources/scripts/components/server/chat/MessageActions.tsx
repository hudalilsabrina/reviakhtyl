import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaArrowRotateLeft, FaCheck, FaCopy, FaTrash } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatMessage } from '@/api/server/chat/types';
import { useFlashKey } from '@/plugins/useFlash';

const Actions = styled.span`
    ${tw`flex items-center gap-0.5 ml-auto`};
`;

const Button = styled.button`
    ${tw`inline-flex items-center justify-center w-6 h-6 rounded-ui text-gray-600
         transition-all duration-150 hover:text-gray-200 hover:bg-gray-700/60
         focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary-400`};
`;

interface Props {
    message: ChatMessage;
    onCopy?: () => void;
    onRegenerate?: () => void;
    onDelete?: () => void;
}

const MessageActions = ({ message, onCopy, onRegenerate, onDelete }: Props) => {
    const { t } = useTranslation('server/chat');
    const { addError } = useFlashKey('server:chat');
    const [copied, setCopied] = useState(false);

    const isUser = message.role === 'user';
    const canRegenerate = !isUser && message.status === 'complete';
    const hasContent = !!message.content;

    const handleCopy = async () => {
        if (!hasContent || !onCopy) return;

        try {
            await navigator.clipboard.writeText(message.content ?? '');
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            addError(t('copy-message-failed'));
        }
    };

    if (!onCopy && !onRegenerate && !onDelete) {
        return null;
    }

    return (
        <Actions onClick={(e) => e.stopPropagation()}>
            {hasContent && onCopy && (
                <Button onClick={handleCopy} title={copied ? t('copy-message-success') : t('copy-message')}>
                    {copied ? <FaCheck css={tw`w-3 h-3 text-green-400`} /> : <FaCopy css={tw`w-3 h-3`} />}
                </Button>
            )}

            {canRegenerate && onRegenerate && (
                <Button onClick={onRegenerate} title={t('regenerate-message')}>
                    <FaArrowRotateLeft css={tw`w-3 h-3`} />
                </Button>
            )}

            {isUser && onDelete && (
                <Button onClick={onDelete} title={t('delete-message')}>
                    <FaTrash css={tw`w-3 h-3`} />
                </Button>
            )}
        </Actions>
    );
};

export default MessageActions;
