import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaComments, FaRobot } from 'react-icons/fa6';
import styled, { keyframes } from 'styled-components';
import tw from 'twin.macro';
import confirmToolCalls from '@/api/server/chat/confirmToolCalls';
import createConversation from '@/api/server/chat/createConversation';
import deleteConversation from '@/api/server/chat/deleteConversation';
import getConversation from '@/api/server/chat/getConversation';
import sendMessage from '@/api/server/chat/sendMessage';
import streamConfirmation from '@/api/server/chat/streamConfirmation';
import streamMessage from '@/api/server/chat/streamMessage';
import { ChatStreamHandlers, isStreamUnsupported } from '@/api/server/chat/streamRequest';
import { ChatMessage, ChatTool } from '@/api/server/chat/types';
import getServerChatConfig from '@/api/swr/getServerChatConfig';
import getServerChatConversations from '@/api/swr/getServerChatConversations';
import { httpErrorToHuman } from '@/api/http';
import ChatMessageRow from '@/components/server/chat/ChatMessage';
import ConversationList from '@/components/server/chat/ConversationList';
import MessageComposer from '@/components/server/chat/MessageComposer';
import PendingApproval from '@/components/server/chat/PendingApproval';
import { applyDelta, applyToolCall, mergeMessages, optimisticMessage } from '@/components/server/chat/thread';
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
 * What the thread is currently waiting on. Drives the indicator that has to stay on screen
 * from the click to the final word: a confirmed action goes through the same minutes-long
 * turn as a message, and the approval panel it came from disappears as soon as the decision
 * lands, so something has to say what is happening in its place.
 */
type Activity = 'idle' | 'sending' | 'approving' | 'denying';

/**
 * What a streamed turn told us about itself, filled in as its events arrive. A turn started by
 * a message and one resumed by a confirmation are read the same way once they close, so both
 * fill in the same record.
 */
interface TurnOutcome {
    /** The server has stored something for this turn, so the text is no longer ours to hand back. */
    acknowledged: boolean;
    /** `done` arrived, so what is on screen is authoritative and needs no re-read. */
    reconciled: boolean;
    /** The turn failed after it had started, reported once the stream closes rather than thrown mid-flight. */
    failure: Error | null;
}

const buildExamples = (tools: ChatTool[]): string[] => {
    const groups = new Set(tools.map((tool) => tool.group));

    return EXAMPLE_PROMPTS.filter((example) => example.group === null || groups.has(example.group))
        .slice(0, 4)
        .map((example) => example.prompt);
};

const ChatContainer = () => {
    const { t } = useTranslation('server/chat');
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
    const [activity, setActivity] = useState<Activity>('idle');
    const [listOpen, setListOpen] = useState(false);
    // The message currently receiving text, so its bubble can show a cursor.
    const [streamingUuid, setStreamingUuid] = useState<string | null>(null);
    // Incremented whenever a thread's messages are installed, to trigger the
    // scroll-to-newest exactly once per opened conversation.
    const [threadRevision, setThreadRevision] = useState(0);

    const busy = activity !== 'idle';

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

    /**
     * Applies a change to the thread it was computed for. The user is free to switch
     * conversations while a turn runs, and a late event must never be merged into whichever
     * thread happens to be open now.
     */
    const updateThread = (target: string, apply: (current: ChatMessage[]) => ChatMessage[]) => {
        if (activeRef.current === target) setMessages(apply);
    };

    /**
     * The handlers every streamed turn is read with. Sending and confirming produce the same
     * events in the same order — a confirmation merely opens with a message already on screen,
     * which upserts by uuid like any other — so they are read by the same code.
     */
    const streamHandlers = (target: string, outcome: TurnOutcome): ChatStreamHandlers => ({
        onMessage: (message) => {
            outcome.acknowledged = true;
            updateThread(target, (current) => mergeMessages(current, [message]));
        },
        onDelta: (messageUuid, fragment) => {
            setStreamingUuid(messageUuid);
            updateThread(target, (current) => applyDelta(current, messageUuid, fragment));
        },
        onTool: (messageUuid, call) => updateThread(target, (current) => applyToolCall(current, messageUuid, call)),
        onStatus: () => setStreamingUuid(null),
        onDone: (incoming) => {
            outcome.reconciled = true;
            setStreamingUuid(null);
            updateThread(target, (current) => mergeMessages(current, incoming));
        },
        onError: (problem) => {
            outcome.failure = new Error(problem);
        },
    });

    const handleSend = async () => {
        const content = input.trim();
        if (content.length === 0 || busy || awaitingConfirmation || loadingThread) return;

        const echo = optimisticMessage(content);

        setActivity('sending');
        setInput('');
        clearFlashes();
        setMessages((current) => [...current, echo]);

        const controller = new AbortController();
        abortStream();
        streamRef.current = controller;

        let conversation = active;
        // Only the `done` event makes what is on screen authoritative. Anything short of it —
        // a dropped connection, a truncated final event — leaves the thread to be re-read.
        const outcome: TurnOutcome = { acknowledged: false, reconciled: false, failure: null };

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

            try {
                await streamMessage(uuid, target, content, streamHandlers(target, outcome), controller.signal);
            } catch (error) {
                if (!isStreamUnsupported(error)) throw error;

                // An older backend, or a proxy that will not carry an event stream. The
                // blocking endpoint answers the same question, just all at once.
                const incoming = await sendMessage(uuid, target, content);

                outcome.acknowledged = true;
                outcome.reconciled = true;
                updateThread(target, (current) => mergeMessages(current, incoming));
            }

            if (outcome.failure) throw outcome.failure;
            if (!outcome.reconciled) await resyncThread(target);

            mutateConversations();
        } catch (error) {
            // Walking away from a turn is not a failure; the thread it belonged to is gone.
            if (controller.signal.aborted) return;

            // Take the echo away so the thread does not claim the message was sent, and hand
            // the text back so it is not lost — unless the server already stored it.
            setMessages((current) => current.filter((message) => message.uuid !== echo.uuid));
            if (!outcome.acknowledged) setInput((current) => (current.length === 0 ? content : current));
            clearAndAddHttpError(error as Error);

            if (conversation) await resyncThread(conversation);
        } finally {
            if (streamRef.current === controller) streamRef.current = null;

            setStreamingUuid(null);
            setActivity('idle');
        }
    };

    /**
     * Answers the pending confirmation. Approving re-enters the same loop a message does, and
     * takes just as long, so it is streamed for the same reason: the alternative is a click
     * followed by minutes of nothing.
     */
    const handleDecision = async (approved: boolean) => {
        if (!active || !awaitingConfirmation || busy) return;

        const conversation = active;
        const decided = awaitingConfirmation.uuid;

        setActivity(approved ? 'approving' : 'denying');
        clearFlashes();

        const controller = new AbortController();
        abortStream();
        streamRef.current = controller;

        const outcome: TurnOutcome = { acknowledged: false, reconciled: false, failure: null };

        try {
            try {
                await streamConfirmation(
                    uuid,
                    conversation,
                    decided,
                    approved,
                    streamHandlers(conversation, outcome),
                    controller.signal
                );
            } catch (error) {
                if (!isStreamUnsupported(error)) throw error;

                // An older backend, or a proxy that will not carry an event stream. The
                // blocking endpoint records the same decision, just all at once.
                const incoming = await confirmToolCalls(uuid, conversation, decided, approved);

                outcome.reconciled = true;
                updateThread(conversation, (current) => mergeMessages(current, incoming));
            }

            if (outcome.failure) throw outcome.failure;
            // Short of `done` the thread is still showing a decision we only half saw — and the
            // half we did not see may be the one that cleared the pending state.
            if (!outcome.reconciled) await resyncThread(conversation);

            mutateConversations();
        } catch (error) {
            // Walking away mid-turn is not a failure; the decision stands server-side either way.
            if (controller.signal.aborted) return;

            clearAndAddHttpError(error as Error);

            // The decision may have been recorded even though we never saw the response.
            // Re-reading is the only way out of a thread stuck on a stale pending state.
            await resyncThread(conversation);
        } finally {
            if (streamRef.current === controller) streamRef.current = null;

            setStreamingUuid(null);
            setActivity('idle');
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
                    <h2 css={tw`text-xl text-gray-100 mb-2`}>{t('not-available-heading')}</h2>
                    <p css={tw`text-sm text-gray-400 max-w-md mx-auto`}>{t('not-available-body')}</p>
                </Card>
            </ServerContentBlock>
        );
    }

    const composerPlaceholder = awaitingConfirmation
        ? t('composer-placeholder-waiting-confirmation')
        : busy
        ? t('composer-placeholder-busy')
        : t('composer-placeholder-idle');

    const ACTIVITY_LABEL: Record<Exclude<Activity, 'idle'>, string> = {
        sending: t('activity-sending'),
        approving: t('activity-approving'),
        denying: t('activity-denying'),
    };

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
                        {listOpen ? t('sidebar-toggle-hide') : t('sidebar-toggle-show', { count: conversations?.length ?? 0 })}
                    </Button>
                </div>

                <div css={[listOpen ? tw`block` : tw`hidden`, tw`lg:block lg:w-72 lg:flex-shrink-0`]}>
                    <Card>
                        {/* Without this the list renders its "no conversations yet" state on a failed
                            request, telling the user their history is empty when it merely failed to load. */}
                        {conversationsError && !conversations ? (
                            <div css={tw`p-4 text-center`}>
                                <p css={tw`text-sm text-gray-300 mb-1`}>{t('conversations-load-error')}</p>
                                <p css={tw`text-xs text-gray-500 mb-3`}>{httpErrorToHuman(conversationsError)}</p>
                                <Button.Text size={Button.Sizes.Small} onClick={() => mutateConversations()}>
                                    {t('retry')}
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
                                <h2 css={tw`text-lg text-gray-100 mb-1`}>{t('ask-about-server')}</h2>
                                <p css={tw`text-sm text-gray-400 max-w-md mx-auto mb-5`}>
                                    {t('intro-text')}{' '}
                                    {config.requiresConfirmation
                                        ? t('approval-first')
                                        : t('approval-immediate')}
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

                        {/* The one thing on screen for the whole of a turn: it is already showing
                            when the approval panel takes itself away on the decision landing, and
                            stays until the last word of the reply has streamed in. */}
                        {activity !== 'idle' && (
                            <div css={tw`flex items-center gap-2 text-xs text-gray-400 px-1`} role={'status'}>
                                <Dot $delay={'0s'} />
                                <Dot $delay={'0.2s'} />
                                <Dot $delay={'0.4s'} />
                                <span>{ACTIVITY_LABEL[activity]}</span>
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
                            {t('keyboard-hint')}
                            {config.model ? ` Powered by ${config.model}.` : ''}
                        </p>
                    </div>
                </Card>
            </div>
        </ServerContentBlock>
    );
};

export default ChatContainer;
