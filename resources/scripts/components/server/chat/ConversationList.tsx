import { useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { FaPlus, FaTrash } from 'react-icons/fa6';
import styled from 'styled-components';
import tw from 'twin.macro';
import { ChatConversation } from '@/api/server/chat/types';
import { Dialog } from '@/reviactyl/elements/dialog';
import { Button } from '@/reviactyl/elements/button/index';
import Spinner from '@/reviactyl/elements/Spinner';

const Row = styled.div<{ $active: boolean }>`
    ${tw`flex items-center gap-2 w-full rounded-ui border px-3 py-2 transition-colors duration-150`};
    ${({ $active }) =>
        $active
            ? tw`bg-primary-500/20 border-primary-500/40`
            : tw`bg-gray-800/40 border-gray-800 hover:border-gray-600`};
`;

interface Props {
    conversations: ChatConversation[];
    activeUuid: string | null;
    loading: boolean;
    creating: boolean;
    onSelect: (uuid: string) => void;
    onCreate: () => void;
    onDelete: (uuid: string) => void;
}

const ConversationList = ({ conversations, activeUuid, loading, creating, onSelect, onCreate, onDelete }: Props) => {
    const [pendingDelete, setPendingDelete] = useState<ChatConversation | null>(null);

    return (
        <div css={tw`flex flex-col gap-3`}>
            <Dialog.Confirm
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                title={'Delete conversation'}
                confirm={'Delete'}
                onConfirmed={() => {
                    if (pendingDelete) onDelete(pendingDelete.uuid);
                    setPendingDelete(null);
                }}
            >
                This will permanently remove &quot;{pendingDelete?.title || 'New conversation'}&quot; and everything
                said in it. Anything the assistant already did to your server stays done.
            </Dialog.Confirm>

            <Button disabled={creating} onClick={onCreate} css={tw`w-full flex items-center justify-center gap-2`}>
                <FaPlus css={tw`w-3 h-3`} /> New chat
            </Button>

            {loading ? (
                <Spinner size={'small'} centered />
            ) : conversations.length === 0 ? (
                <p css={tw`text-xs text-gray-400 px-1`}>No conversations yet.</p>
            ) : (
                <div css={tw`flex flex-col gap-2 max-h-72 lg:max-h-[60vh] overflow-y-auto`}>
                    {conversations.map((conversation) => (
                        <Row key={conversation.uuid} $active={conversation.uuid === activeUuid}>
                            <button
                                type={'button'}
                                onClick={() => onSelect(conversation.uuid)}
                                css={tw`flex-1 min-w-0 text-left`}
                            >
                                <span css={tw`block text-sm text-gray-100 truncate`}>
                                    {conversation.title || 'New conversation'}
                                </span>
                                <span css={tw`block text-2xs text-gray-400`}>
                                    {formatDistanceToNow(conversation.lastMessageAt ?? conversation.createdAt, {
                                        addSuffix: true,
                                    })}
                                </span>
                            </button>
                            <button
                                type={'button'}
                                title={'Delete conversation'}
                                onClick={() => setPendingDelete(conversation)}
                                css={tw`flex-shrink-0 p-1.5 rounded-ui text-gray-500 hover:text-red-400 transition-colors duration-150`}
                            >
                                <FaTrash css={tw`w-3 h-3`} />
                            </button>
                        </Row>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ConversationList;
