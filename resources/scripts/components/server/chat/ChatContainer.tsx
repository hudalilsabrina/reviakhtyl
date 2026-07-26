import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { FaComments, FaRobot } from 'react-icons/fa6';
import styled, { keyframes } from 'styled-components';
import tw from 'twin.macro';
import confirmToolCalls from '@/api/server/chat/confirmToolCalls';
import createConversation from '@/api/server/chat/createConversation';
import deleteConversation from '@/api/server/chat/deleteConversation';
import getConversation from '@/api/server/chat/getConversation';
import sendMessage from '@/api/server/chat/sendMessage';
import streamMessage, { ChatStreamHandlers, isStreamUnsupported } from '@/api/server/chat/streamMessage';
import { ChatMessage, ChatTool, ChatToolCall } from '@/api/server/chat/types';
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

/**
 * The local echo of a message the user has sent but the server has not yet
 * confirmed. Without it the composer clears and nothing appears until the whole
 * turn returns — which can take a minute — so it looks like nothing was sent.
 */
const optimisticMessage = (content: string): ChatMessage => ({
    uuid: `pending-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    pending: true,
    role: 'user',
    content,
    reasoning: null,
    status: 'complete',
    toolCalls: [],
    createdAt: new Date(),
});

const mergeMessages = (existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] => {
    // Local echoes are dropped wholesale: the server returns the real message
    // with its own uuid, so keeping them would show the text twice.
    const merged = existing.filter((message) => !message.pending);

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

/** Appends a streamed fragment to the message it belongs to. */
const applyDelta = (messages: ChatMessage[], uuid: string, fragment: string): ChatMessage[] =>
    messages.map((message) =>
        message.uuid === uuid ? { ...message, content: (message.content ?? '') + fragment } : message
    );

/** Upserts a tool call by id — a call is announced when proposed and again once it has run. */
const applyToolCall = (messages: ChatMessage[], uuid: string, call: ChatToolCall): ChatMessage[] =>
    messages.map((message) => {
        if (message.uuid !== uuid) return message;

        const index = message.toolCalls.findIndex((existing) => existing.id === call.id);

        return {
            ...message,
            toolCalls:
                index === -1
                    ? [...message.toolCalls, call]
                    : message.toolCalls.map((existing, position) => (position === index ? call : existing)),
        };
    });

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
        error: conversationsError,
        isValidating: conversationsValidating,
        mutate: mutateConversations,
    } = getServerChatConversations(uuid, enabled);

    const [active, setActive] = useState<string | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [loadingThread, setLoadingThread] = useState(false);
    const [busy, setBusy] = useState(false);
    const [listOpen, setListOpen] = useState(false);
    // The message currently receiving text, so its bubble can show a cursor.
    const [streamingUuid, setStreamingUuid] = useState<string | null>(null);
    // Incremented whenever a thread's messages are installed, to trigger the
    // scroll-to-newest exactly once per opened conversation.
    const [threadRevision, setThreadRevision] = useState(0);

    const threadRef = useRef<HTMLDivElement>(null);
    // Conversations we opened ourselves already have their (empty) state in memory; re-fetching
    // them would race with the response of the message that is being sent right now.
    const skipLoadRef = useRef<string | null>(null);
    // A request can take minutes, and the user is free to switch conversations while it runs.
    // Responses are matched against this before they are allowed to touch the thread.
    const activeRef = useRef<string | null>(null);
    // The in-flight response stream, so it can be torn down when the user walks away from it.
    const streamRef = useRef<AbortController | null>(null);

    useEffect(() => {
        activeRef.current = active;
    }, [active]);

    // Nothing is listening for the rest of the turn once this page is gone.
    useEffect(() => () => streamRef.current?.abort(), []);

    /**
     * Stops reading the response of a turn whose thread is no longer on screen. The turn
     * itself keeps running server-side; re-opening the conversation reads the result.
     */
    const abortStream = () => {
        streamRef.current?.abort();
        streamRef.current = null;
    };

    const examples = useMemo(() => buildExamples(config?.tools ?? []), [config]);

    const lastMessage = messages.length > 0 ? messages[messages.length - 1] : undefined;
    const awaitingConfirmation =
        lastMessage && lastMessage.status === 'awaiting_confirmation' ? lastMessage : undefined;

    // The page opens on a fresh chat rather than resuming the newest thread: arriving
    // here usually means having a new question, and landing mid-conversation makes the
    // assistant answer with stale context the user has forgotten about. Earlier threads
    // stay one click away in the list. Nothing is created until the first message is
    // sent, so opening the page repeatedly does not litter the list with empty threads.

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
                if (cancelled) return;

                setMessages(conversation.messages);
                // Signals "a thread's messages just landed", which is the only
                // moment we can measure where the bottom actually is.
                setThreadRevision((revision) => revision + 1);
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

    // A thread opens on its newest message: a conversation is read from the bottom, and
    // starting at the top shows the oldest exchange with no sign that anything follows.
    //
    // This is driven by the revision counter rather than by `active` changing, because
    // at the moment `active` changes the messages on screen still belong to the previous
    // conversation — measuring then scrolls to the bottom of the wrong content, and the
    // real messages arrive afterwards with nothing left to trigger a scroll. Layout
    // effect so the jump happens before the browser paints the thread at the top.
    useLayoutEffect(() => {
        const element = threadRef.current;
        if (!element) return;

        element.scrollTop = element.scrollHeight;
    }, [threadRevision]);

    // Afterwards, follow new messages only while the user is already at the bottom, so
    // scrolling up to re-read something is not yanked away.
    useEffect(() => {
        const element = threadRef.current;
        if (!element) return;

        const distanceFromBottom = element.scrollHeight - element.scrollTop - element.clientHeight;
        if (distanceFromBottom > 120) return;

        element.scrollTop = element.scrollHeight;
    }, [messages, busy]);

    /**
     * Returns to the fresh-chat state. No conversation is created here — the first
     * message does that — so clicking this repeatedly cannot leave a trail of empty
     * threads in the list.
     */
    const handleCreate = () => {
        abortStream();
        setListOpen(false);
        clearFlashes();
        setActive(null);
        setMessages([]);
    };

    const handleSelect = (conversation: string) => {
        if (conversation !== active) abortStream();

        setListOpen(false);
        setActive(conversation);
    };

    const handleDelete = (conversation: string) => {
        clearFlashes();
        mutateConversations((data) => (data || []).filter((c) => c.uuid !== conversation), false);

        if (conversation === active) {
            abortStream();
            setActive(null);
            setMessages([]);
        }

        deleteConversation(uuid, conversation).catch((error) => {
            clearAndAddHttpError(error);
            mutateConversations();
        });
    };

    /**
     * Pulls the thread back from the server after a request we could not observe the
     * result of. A send that times out client-side is still running server-side, so the
     * turn — including a pending approval — may have been committed without us seeing it.
     * Without this the thread silently desyncs and every retry fails.
     */
    const resyncThread = async (conversation: string) => {
        if (activeRef.current !== conversation) return;

        try {
            const fresh = await getConversation(uuid, conversation);

            if (activeRef.current === conversation) {
                setMessages(fresh.messages);
                setThreadRevision((revision) => revision + 1);
            }
        } catch {
            // The error from the original request is the one worth showing.
        }
    };

    const handleSend = async () => {
        const content = input.trim();
        if (content.length === 0 || busy || awaitingConfirmation || loadingThread) return;

        const echo = optimisticMessage(content);

        setBusy(true);
        setInput('');
        clearFlashes();
        setMessages((current) => [...current, echo]);

        const controller = new AbortController();
        abortStream();
        streamRef.current = controller;

        let conversation = active;
        // Set once the server has stored the message. After that, handing the text back to the
        // composer would show it twice — once in the thread, once in the input.
        let acknowledged = false;
        // Only the `done` event makes what is on screen authoritative. Anything short of it —
        // a dropped connection, a truncated final event — leaves the thread to be re-read.
        let reconciled = false;
        let failure: Error | null = null;

        try {
            if (!conversation) {
                const created = await createConversation(uuid);

                conversation = created.uuid;
                skipLoadRef.current = created.uuid;
                setActive(created.uuid);
                // The effect mirroring `active` into the ref does not run until the next
                // render, and the first events land well before that — without this the guard
                // below would reject the events of the conversation it has just opened.
                activeRef.current = created.uuid;
                mutateConversations((data) => [created, ...(data || [])], false);
            }

            const target = conversation;
            // The user is free to switch conversations while this runs; a late event must
            // never be merged into whichever thread happens to be open now.
            const update = (apply: (current: ChatMessage[]) => ChatMessage[]) => {
                if (activeRef.current === target) setMessages(apply);
            };

            const handlers: ChatStreamHandlers = {
                onMessage: (message) => {
                    acknowledged = true;
                    update((current) => mergeMessages(current, [message]));
                },
                onDelta: (messageUuid, fragment) => {
                    setStreamingUuid(messageUuid);
                    update((current) => applyDelta(current, messageUuid, fragment));
                },
                onTool: (messageUuid, call) => update((current) => applyToolCall(current, messageUuid, call)),
                onStatus: () => setStreamingUuid(null),
                onDone: (incoming) => {
                    reconciled = true;
                    setStreamingUuid(null);
                    update((current) => mergeMessages(current, incoming));
                },
                // The turn failed after it had started. The message is already in the thread,
                // so this is reported once the stream closes rather than thrown mid-flight.
                onError: (problem) => {
                    failure = new Error(problem);
                },
            };

            try {
                await streamMessage(uuid, target, content, handlers, controller.signal);
            } catch (error) {
                if (!isStreamUnsupported(error)) throw error;

                // An older backend, or a proxy that will not carry an event stream. The
                // blocking endpoint answers the same question, just all at once.
                const incoming = await sendMessage(uuid, target, content);

                acknowledged = true;
                reconciled = true;
                update((current) => mergeMessages(current, incoming));
            }

            if (failure) throw failure;
            if (!reconciled) await resyncThread(target);

            mutateConversations();
        } catch (error) {
            // Walking away from a turn is not a failure; the thread it belonged to is gone.
            if (controller.signal.aborted) return;

            // Take the echo away so the thread does not claim the message was sent, and hand
            // the text back so it is not lost — unless the server already stored it.
            setMessages((current) => current.filter((message) => message.uuid !== echo.uuid));
            if (!acknowledged) setInput((current) => (current.length === 0 ? content : current));
            clearAndAddHttpError(error as Error);

            if (conversation) await resyncThread(conversation);
        } finally {
            if (streamRef.current === controller) streamRef.current = null;

            setStreamingUuid(null);
            setBusy(false);
        }
    };

    const handleDecision = async (approved: boolean) => {
        if (!active || !awaitingConfirmation || busy) return;

        const conversation = active;

        setBusy(true);
        clearFlashes();

        try {
            const incoming = await confirmToolCalls(uuid, conversation, awaitingConfirmation.uuid, approved);

            if (activeRef.current === conversation) {
                setMessages((current) => mergeMessages(current, incoming));
            }

            mutateConversations();
        } catch (error) {
            clearAndAddHttpError(error as Error);

            // The decision may have been recorded even though we never saw the response.
            // Re-reading is the only way out of a thread stuck on a stale pending state.
            await resyncThread(conversation);
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
                        {/* Without this the list renders its "no conversations yet" state on a failed
                            request, telling the user their history is empty when it merely failed to load. */}
                        {conversationsError && !conversations ? (
                            <div css={tw`p-4 text-center`}>
                                <p css={tw`text-sm text-gray-300 mb-1`}>Could not load your conversations.</p>
                                <p css={tw`text-xs text-gray-500 mb-3`}>{httpErrorToHuman(conversationsError)}</p>
                                <Button.Text size={Button.Sizes.Small} onClick={() => mutateConversations()}>
                                    Try again
                                </Button.Text>
                            </div>
                        ) : (
                            <ConversationList
                                conversations={conversations ?? []}
                                activeUuid={active}
                                loading={!conversations && conversationsValidating}
                                creating={false}
                                onSelect={handleSelect}
                                onCreate={handleCreate}
                                onDelete={handleDelete}
                            />
                        )}
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
                                    I can look things up for you and act on this server.{' '}
                                    {config.requiresConfirmation
                                        ? 'Anything that changes the server is shown to you for approval first.'
                                        : 'This panel is configured to let me make changes without asking first, so be specific about what you want.'}
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
                            messages.map((message) => (
                                <ChatMessageRow
                                    key={message.uuid}
                                    message={message}
                                    streaming={message.uuid === streamingUuid}
                                />
                            ))
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
