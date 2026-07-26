import { useEffect, useMemo, useRef, useState } from 'react';
import { FaComments, FaRobot } from 'react-icons/fa6';
import styled, { keyframes } from 'styled-components';
import tw from 'twin.macro';
import confirmToolCalls from '@/api/server/chat/confirmToolCalls';
import createConversation from '@/api/server/chat/createConversation';
import deleteConversation from '@/api/server/chat/deleteConversation';
import getConversation from '@/api/server/chat/getConversation';
import sendMessage from '@/api/server/chat/sendMessage';
import { ChatMessage, ChatTool } from '@/api/server/chat/types';
import getServerChatConfig from '@/api/swr/getServerChatConfig';
import getServerChatConversations from '@/api/swr/getServerChatConversations';
import { httpErrorToHuman } from '@/api/http';
import ChatMessageRow from '@/components/server/chat/ChatMessage';
import ConversationList from '@/components/server/chat/ConversationList';
import MessageComposer from '@/components/server/chat/MessageComposer';
import PendingApproval from '@/components/server/chat/PendingApproval';
import { useFlashKey } from '@/plugins/useFlash';
import { Button } from '@/reviactyl/elements/button/index';
import { ServerError } from '@/reviactyl/elements/ScreenBlock';
import ServerContentBlock from '@/reviactyl/elements/ServerContentBlock';
import Spinner from '@/reviactyl/elements/Spinner';
import Card from '@/reviactyl/ui/Card';
import { ServerContext } from '@/state/server';

const Thread = styled.div`
    ${tw`overflow-y-auto px-1 py-2 space-y-4`};
    min-height: 16rem;
    max-height: 60vh;
`;

const blink = keyframes`
    0%, 80%, 100% { opacity: 0.2; }
    40% { opacity: 1; }
`;

const Dot = styled.span<{ $delay: string }>`
    ${tw`inline-block w-1.5 h-1.5 rounded-full bg-gray-300`};
    animation: ${blink} 1.4s infinite ease-in-out both;
    animation-delay: ${({ $delay }) => $delay};
`;

const ExamplePrompt = styled.button`
    ${tw`text-left text-sm text-gray-200 bg-gray-800/50 border border-gray-800 rounded-ui px-3 py-2 transition-colors duration-150`};

    &:hover {
        ${tw`border-gray-600 text-gray-50`};
    }
`;

// Suggested prompts are only offered when the matching tool group is actually available to
// this user on this server, so we never advertise something the assistant cannot do.
const EXAMPLE_PROMPTS: { group: string | null; prompt: string }[] = [
    { group: 'power', prompt: 'Is my server running?' },
    { group: 'files', prompt: 'Show me the last 50 lines of the latest log' },
    { group: 'power', prompt: 'Restart the server' },
    { group: 'backups', prompt: 'Do I have a recent backup?' },
    { group: 'files', prompt: 'What is taking up the most space in my files?' },
    { group: 'databases', prompt: 'Which databases does this server have?' },
    { group: 'schedules', prompt: 'What schedules are set up on this server?' },
    { group: 'startup', prompt: 'Which startup variables can I change?' },
    { group: 'network', prompt: 'What address do players connect to?' },
    { group: null, prompt: 'What can you help me with on this server?' },
    { group: null, prompt: 'Give me a summary of how this server is doing' },
];

const mergeMessages = (existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] => {
    const merged = existing.slice();

    incoming.forEach((message) => {
        const index = merged.findIndex((m) => m.uuid === message.uuid);

        if (index === -1) {
            merged.push(message);
        } else {
            merged[index] = message;
        }
    });

    return merged.sort((a, b) => a.createdAt.getTime() - b.createdAt.getTime());
};

const buildExamples = (tools: ChatTool[]): string[] => {
    const groups = new Set(tools.map((tool) => tool.group));

    return EXAMPLE_PROMPTS.filter((example) => example.group === null || groups.has(example.group))
        .slice(0, 4)
        .map((example) => example.prompt);
};

const ChatContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlashKey('server:chat');

    const {
        data: config,
        error: configError,
        isValidating: configValidating,
        mutate: mutateConfig,
    } = getServerChatConfig(uuid);
    const enabled = config?.enabled === true;

    const {
        data: conversations,
        isValidating: conversationsValidating,
        mutate: mutateConversations,
    } = getServerChatConversations(uuid, enabled);

    const [active, setActive] = useState<string | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [loadingThread, setLoadingThread] = useState(false);
    const [busy, setBusy] = useState(false);
    const [creating, setCreating] = useState(false);
    const [listOpen, setListOpen] = useState(false);

    const threadRef = useRef<HTMLDivElement>(null);
    // Conversations we opened ourselves already have their (empty) state in memory; re-fetching
    // them would race with the response of the message that is being sent right now.
    const skipLoadRef = useRef<string | null>(null);

    const examples = useMemo(() => buildExamples(config?.tools ?? []), [config]);

    const lastMessage = messages.length > 0 ? messages[messages.length - 1] : undefined;
    const awaitingConfirmation =
        lastMessage && lastMessage.status === 'awaiting_confirmation' ? lastMessage : undefined;

    useEffect(() => {
        if (!conversations || active !== null) return;

        const newest = conversations.length > 0 ? conversations[0] : undefined;
        if (newest) setActive(newest.uuid);
    }, [conversations, active]);

    useEffect(() => {
        if (!active) {
            setMessages([]);
            return;
        }

        if (skipLoadRef.current === active) {
            skipLoadRef.current = null;
            return;
        }

        let cancelled = false;

        setLoadingThread(true);
        clearFlashes();
        getConversation(uuid, active)
            .then((conversation) => {
                if (!cancelled) setMessages(conversation.messages);
            })
            .catch((error) => {
                if (!cancelled) clearAndAddHttpError(error);
            })
            .finally(() => {
                if (!cancelled) setLoadingThread(false);
            });

        return () => {
            cancelled = true;
        };
    }, [uuid, active]);

    // Keep the newest message in view, including while we are waiting on a reply.
    useEffect(() => {
        const element = threadRef.current;
        if (!element) return;

        element.scrollTop = element.scrollHeight;
    }, [messages, busy, loadingThread]);

    const handleCreate = () => {
        setCreating(true);
        setListOpen(false);
        clearFlashes();

        createConversation(uuid)
            .then((conversation) => {
                skipLoadRef.current = conversation.uuid;
                setMessages([]);
                setActive(conversation.uuid);
                mutateConversations((data) => [conversation, ...(data || [])], false);
            })
            .catch((error) => clearAndAddHttpError(error))
            .finally(() => setCreating(false));
    };

    const handleSelect = (conversation: string) => {
        setListOpen(false);
        setActive(conversation);
    };

    const handleDelete = (conversation: string) => {
        clearFlashes();
        mutateConversations((data) => (data || []).filter((c) => c.uuid !== conversation), false);

        if (conversation === active) {
            setActive(null);
            setMessages([]);
        }

        deleteConversation(uuid, conversation).catch((error) => {
            clearAndAddHttpError(error);
            mutateConversations();
        });
    };

    const handleSend = async () => {
        const content = input.trim();
        if (content.length === 0 || busy || awaitingConfirmation) return;

        setBusy(true);
        setInput('');
        clearFlashes();

        try {
            let conversation = active;

            if (!conversation) {
                const created = await createConversation(uuid);

                conversation = created.uuid;
                skipLoadRef.current = created.uuid;
                setActive(created.uuid);
                mutateConversations((data) => [created, ...(data || [])], false);
            }

            const incoming = await sendMessage(uuid, conversation, content);
            setMessages((current) => mergeMessages(current, incoming));
            mutateConversations();
        } catch (error) {
            // Hand the message back so a failed request does not lose what was typed.
            setInput((current) => (current.length === 0 ? content : current));
            clearAndAddHttpError(error as Error);
        } finally {
            setBusy(false);
        }
    };

    const handleDecision = async (approved: boolean) => {
        if (!active || !awaitingConfirmation || busy) return;

        setBusy(true);
        clearFlashes();

        try {
            const incoming = await confirmToolCalls(uuid, active, awaitingConfirmation.uuid, approved);
            setMessages((current) => mergeMessages(current, incoming));
            mutateConversations();
        } catch (error) {
            clearAndAddHttpError(error as Error);
        } finally {
            setBusy(false);
        }
    };

    if (!config) {
        return configError && !configValidating ? (
            <ServerError title={'Oops!'} message={httpErrorToHuman(configError)} onRetry={() => mutateConfig()} />
        ) : (
            <Spinner centered size={Spinner.Size.LARGE} />
        );
    }

    if (!config.enabled) {
        return (
            <ServerContentBlock title={'Assistant'} showFlashKey={'server:chat'}>
                <Card css={tw`text-center py-10`}>
                    <FaRobot css={tw`w-10 h-10 mx-auto text-gray-600 mb-4`} />
                    <h2 css={tw`text-xl text-gray-100 mb-2`}>The assistant is not available</h2>
                    <p css={tw`text-sm text-gray-400 max-w-md mx-auto`}>
                        Your administrator has not set up the AI assistant for this panel yet. Once they configure it,
                        you will be able to ask questions about this server and have it take actions for you from here.
                    </p>
                </Card>
            </ServerContentBlock>
        );
    }

    const composerPlaceholder = awaitingConfirmation
        ? 'Approve or deny the request above to continue'
        : busy
        ? 'Waiting for the assistant…'
        : 'Ask about this server…';

    return (
        <ServerContentBlock title={'Assistant'} showFlashKey={'server:chat'}>
            <div css={tw`flex flex-col lg:flex-row gap-4`}>
                <div css={tw`lg:hidden`}>
                    <Button
                        type={'button'}
                        variant={Button.Variants.Secondary}
                        onClick={() => setListOpen((open) => !open)}
                        css={tw`w-full flex items-center justify-center gap-2`}
                    >
                        <FaComments css={tw`w-4 h-4`} />
                        {listOpen ? 'Hide conversations' : `Conversations (${conversations?.length ?? 0})`}
                    </Button>
                </div>

                <div css={[listOpen ? tw`block` : tw`hidden`, tw`lg:block lg:w-72 lg:flex-shrink-0`]}>
                    <Card>
                        <ConversationList
                            conversations={conversations ?? []}
                            activeUuid={active}
                            loading={!conversations && conversationsValidating}
                            creating={creating}
                            onSelect={handleSelect}
                            onCreate={handleCreate}
                            onDelete={handleDelete}
                        />
                    </Card>
                </div>

                <Card css={tw`flex-1 min-w-0 flex flex-col`}>
                    <Thread ref={threadRef}>
                        {loadingThread ? (
                            <Spinner size={'small'} centered />
                        ) : messages.length === 0 ? (
                            <div css={tw`py-6 text-center`}>
                                <FaRobot css={tw`w-8 h-8 mx-auto text-gray-600 mb-3`} />
                                <h2 css={tw`text-lg text-gray-100 mb-1`}>Ask me about this server</h2>
                                <p css={tw`text-sm text-gray-400 max-w-md mx-auto mb-5`}>
                                    I can look things up for you and, with your say-so, act on this server. Anything
                                    that changes the server is shown to you for approval first.
                                </p>
                                <div css={tw`grid gap-2 sm:grid-cols-2 max-w-xl mx-auto`}>
                                    {examples.map((example) => (
                                        <ExamplePrompt key={example} type={'button'} onClick={() => setInput(example)}>
                                            {example}
                                        </ExamplePrompt>
                                    ))}
                                </div>
                            </div>
                        ) : (
                            messages.map((message) => <ChatMessageRow key={message.uuid} message={message} />)
                        )}

                        {busy && (
                            <div css={tw`flex items-center gap-2 text-xs text-gray-400 px-1`}>
                                <Dot $delay={'0s'} />
                                <Dot $delay={'0.2s'} />
                                <Dot $delay={'0.4s'} />
                                <span>The assistant is working — this can take a moment.</span>
                            </div>
                        )}
                    </Thread>

                    {awaitingConfirmation && (
                        <div css={tw`mt-3`}>
                            <PendingApproval
                                message={awaitingConfirmation}
                                processing={busy}
                                onDecision={handleDecision}
                            />
                        </div>
                    )}

                    <div css={tw`mt-3 pt-3 border-t border-gray-800`}>
                        <MessageComposer
                            value={input}
                            disabled={busy || loadingThread || awaitingConfirmation !== undefined}
                            placeholder={composerPlaceholder}
                            onChange={setInput}
                            onSubmit={handleSend}
                        />
                        <p css={tw`text-2xs text-gray-500 mt-2`}>
                            Enter sends, Shift + Enter adds a new line.
                            {config.model ? ` Powered by ${config.model}.` : ''}
                        </p>
                    </div>
                </Card>
            </div>
        </ServerContentBlock>
    );
};

export default ChatContainer;
