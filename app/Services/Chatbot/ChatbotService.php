<?php

namespace App\Services\Chatbot;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\Data\ToolCall;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the assistant: turns a user message into an answer, calling tools in a
 * loop until the model is done, has hit its iteration budget, or needs the user
 * to approve something.
 */
class ChatbotService
{
    /**
     * How many tool calls a single model response may have executed. Each call
     * is a request to the daemon, so an unbounded batch turns one chat message
     * into a flood — exactly what injected instructions would aim for.
     */
    private const MAX_CALLS_PER_TURN = 8;

    /**
     * Tool results longer than this are collapsed once the turn that produced
     * them is over. Short enough that a directory listing survives intact.
     */
    private const TOOL_RESULT_DIGEST_THRESHOLD = 400;

    /** Keeps the rolling summary from growing without bound. */
    private const MAX_SUMMARY_LENGTH = 2000;

    public function __construct(
        private ChatbotSettings $settings,
        private OpenAiClient $client,
        private ToolRegistry $registry,
        private ToolExecutor $executor,
        private SystemPromptBuilder $promptBuilder,
    ) {}

    public function startConversation(Server $server, User $user): ChatbotConversation
    {
        $this->assertEnabled();

        return ChatbotConversation::create([
            'uuid' => Str::uuid()->toString(),
            'server_id' => $server->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Handles a new user message and returns every message created in response,
     * in order — the stored user message followed by the assistant's turns.
     *
     * @return Collection<int, ChatbotMessage>
     */
    public function sendMessage(ChatbotConversation $conversation, string $content, ?callable $emit = null): Collection
    {
        $this->assertEnabled();

        return $this->withTurnLock($conversation, function () use ($conversation, $content, $emit) {
            if ($this->pendingConfirmation($conversation)) {
                throw new ChatbotException('The assistant is waiting for you to approve or deny its last proposed action.');
            }

            $user = $this->store($conversation, [
                'role' => ChatbotMessage::ROLE_USER,
                'content' => $content,
            ]);

            $created = collect([$user]);
            $emit && $emit('message', ['message' => $user]);

            if (! $conversation->title) {
                $conversation->update(['title' => $conversation->titleFrom($content)]);
            }

            return $created->concat($this->run($conversation, $emit));
        });
    }

    /**
     * Resolves a pending confirmation and continues the conversation.
     *
     * @return Collection<int, ChatbotMessage>
     */
    public function resolveConfirmation(
        ChatbotConversation $conversation,
        ChatbotMessage $message,
        bool $approved,
        ?callable $emit = null,
    ): Collection {
        $this->assertEnabled();

        return $this->withTurnLock($conversation, fn () => $this->runConfirmation($conversation, $message, $approved, $emit));
    }

    /**
     * @return Collection<int, ChatbotMessage>
     */
    private function runConfirmation(
        ChatbotConversation $conversation,
        ChatbotMessage $message,
        bool $approved,
        ?callable $emit = null,
    ): Collection {
        if ($message->conversation_id !== $conversation->id) {
            throw new ChatbotException('That action is no longer waiting for a decision.');
        }

        // Claim the decision with a conditional update rather than a read
        // followed by a write. Two approvals arriving together would otherwise
        // both pass the check and run the tools twice — deleting the same files
        // or restarting the server a second time.
        $claimed = ChatbotMessage::query()
            ->whereKey($message->getKey())
            ->where('status', ChatbotMessage::STATUS_AWAITING_CONFIRMATION)
            ->update(['status' => $approved ? ChatbotMessage::STATUS_COMPLETE : ChatbotMessage::STATUS_DENIED]);

        if ($claimed !== 1) {
            throw new ChatbotException('That action is no longer waiting for a decision.');
        }

        $message->refresh();

        // The decision itself is news: the client is still showing this message
        // as pending, and the tools below may take a while to run.
        $emit && $emit('message', ['message' => $message]);

        $context = $this->contextFor($conversation);
        /** @var Collection<int, ChatbotMessage> $created */
        $created = collect();
        $calls = $message->tool_calls ?? [];

        foreach ($calls as $index => $call) {
            $id = (string) ($call['id'] ?? '');
            $name = (string) ($call['name'] ?? '');
            $arguments = (array) ($call['arguments'] ?? []);

            if ($approved) {
                $result = $this->executor->execute($context, $name, $arguments);
                $calls[$index]['status'] = ($result['ok'] ?? false) ? 'executed' : 'failed';
                $calls[$index]['ok'] = (bool) ($result['ok'] ?? false);
            } else {
                $result = ['ok' => false, 'error' => 'The user denied this action. Do not attempt it again unless they ask.'];
                $calls[$index]['status'] = 'denied';
                $calls[$index]['ok'] = false;
            }

            $emit && $emit('tool', ['uuid' => $message->uuid, 'call' => $calls[$index]]);

            // Every tool call must be answered by a tool message, otherwise the
            // provider rejects the next request as an unresolved call.
            $created->push($this->store($conversation, [
                'role' => ChatbotMessage::ROLE_TOOL,
                'tool_call_id' => $id,
                'tool_name' => $name,
                'content' => $this->encode($result),
            ]));
        }

        // The status was already set when the decision was claimed above.
        $message->update(['tool_calls' => $calls]);

        return $created->concat($this->run($conversation, $emit));
    }

    /**
     * The message currently blocking the conversation, if any.
     */
    public function pendingConfirmation(ChatbotConversation $conversation): ?ChatbotMessage
    {
        // reorder() is required throughout: the messages() relation carries a
        // default ascending sort that would otherwise take precedence.
        return $conversation->messages()
            ->where('status', ChatbotMessage::STATUS_AWAITING_CONFIRMATION)
            ->reorder('id', 'desc')
            ->first();
    }

    /**
     * @return array<string, ChatbotTool>
     */
    public function toolsFor(Server $server, User $user): array
    {
        return $this->registry->availableFor(new ToolContext($server, $user));
    }

    public function settings(): ChatbotSettings
    {
        return $this->settings;
    }

    /**
     * The tool-calling loop. Each pass sends the whole conversation to the
     * provider and either finishes, runs tools and goes round again, or stops
     * to ask the user for approval.
     *
     * @return Collection<int, ChatbotMessage>
     */
    private function run(ChatbotConversation $conversation, ?callable $emit = null): Collection
    {
        $context = $this->contextFor($conversation);
        $tools = $this->registry->availableFor($context);
        $definitions = array_values(array_map(fn (ChatbotTool $tool) => $tool->definition(), $tools));

        /** @var Collection<int, ChatbotMessage> $created */
        $created = collect();

        for ($iteration = 0; $iteration < $this->settings->maxIterations(); $iteration++) {
            $providerMessages = $this->buildProviderMessages($conversation, $context, $tools);

            // Streaming needs somewhere to put the text before the turn is
            // finished, so the row is written first and filled in as it arrives.
            $placeholder = null;

            if ($emit) {
                $placeholder = $this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_ASSISTANT,
                    'content' => null,
                ]);

                $created->push($placeholder);
                $emit('message', ['message' => $placeholder]);
            }

            try {
                $completion = $emit
                    ? $this->client->stream(
                        $providerMessages,
                        $definitions,
                        fn (string $text) => $emit('delta', ['uuid' => $placeholder->uuid, 'content' => $text]),
                    )
                    : $this->client->chat($providerMessages, $definitions);
            } catch (ChatbotException $e) {
                $failed = $this->persistAssistant($conversation, $placeholder, [
                    'content' => $e->getMessage(),
                    'status' => ChatbotMessage::STATUS_FAILED,
                ]);

                $emit && $emit('message', ['message' => $failed]);

                return $placeholder ? $created : $created->push($failed);
            }

            if (! $completion->hasToolCalls()) {
                $answer = $this->persistAssistant($conversation, $placeholder, [
                    'content' => $completion->content ?? 'I could not produce a response to that. Please rephrase and try again.',
                    'reasoning' => $completion->reasoning,
                ]);

                $emit && $emit('message', ['message' => $answer]);

                return $placeholder ? $created : $created->push($answer);
            }

            // A single response can request any number of calls, and each one is a
            // request to the daemon. Cap the batch so one turn cannot be turned into
            // hundreds of file reads — a plausible outcome of injected instructions.
            $toolCalls = array_slice($completion->toolCalls, 0, self::MAX_CALLS_PER_TURN);
            $dropped = count($completion->toolCalls) - count($toolCalls);

            $needsApproval = $this->settings->requiresConfirmation()
                && $this->containsDestructiveCall($context, $toolCalls);

            $assistant = $this->persistAssistant($conversation, $placeholder, [
                'content' => $completion->content,
                'reasoning' => $completion->reasoning,
                'tool_calls' => $this->describeCalls($context, $toolCalls, $needsApproval ? 'pending' : null),
                'status' => $needsApproval ? ChatbotMessage::STATUS_AWAITING_CONFIRMATION : ChatbotMessage::STATUS_COMPLETE,
            ]);

            if (! $placeholder) {
                $created->push($assistant);
            }

            $emit && $emit('message', ['message' => $assistant]);

            // Hand control back to the user; resolveConfirmation() resumes here.
            if ($needsApproval) {
                return $created;
            }

            $calls = $assistant->tool_calls;

            foreach ($toolCalls as $index => $call) {
                $result = $this->executor->execute($context, $call->name, $call->arguments);

                if ($dropped > 0) {
                    // Tell the model why the rest went missing, so it narrows its
                    // next attempt instead of silently working from partial results.
                    $result['note'] = 'Only the first '.self::MAX_CALLS_PER_TURN.' tool calls of this turn were run; '
                        ."$dropped more were discarded. Ask for fewer things at once.";
                }

                $calls[$index]['status'] = ($result['ok'] ?? false) ? 'executed' : 'failed';
                $calls[$index]['ok'] = (bool) ($result['ok'] ?? false);

                $emit && $emit('tool', ['uuid' => $assistant->uuid, 'call' => $calls[$index]]);

                $created->push($this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_TOOL,
                    'tool_call_id' => $call->id,
                    'tool_name' => $call->name,
                    'content' => $this->encode($result),
                ]));
            }

            $assistant->update(['tool_calls' => $calls]);
        }

        $exhausted = $this->store($conversation, [
            'role' => ChatbotMessage::ROLE_ASSISTANT,
            'content' => 'I stopped after taking too many steps in a row without reaching an answer. Tell me what to focus on and I will try a narrower approach.',
        ]);

        $emit && $emit('message', ['message' => $exhausted]);

        return $created->push($exhausted);
    }

    /**
     * Writes the assistant's turn, filling in the row streaming already created
     * rather than adding a second one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistAssistant(
        ChatbotConversation $conversation,
        ?ChatbotMessage $placeholder,
        array $attributes,
    ): ChatbotMessage {
        if (! $placeholder) {
            return $this->store($conversation, ['role' => ChatbotMessage::ROLE_ASSISTANT] + $attributes);
        }

        $placeholder->update($attributes);

        return $placeholder;
    }

    /**
     * @param  ToolCall[]  $calls
     */
    private function containsDestructiveCall(ToolContext $context, array $calls): bool
    {
        foreach ($calls as $call) {
            $tool = $this->registry->resolveFor($context, $call->name);

            if ($tool?->isDestructive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores tool calls together with a human-readable summary, so the panel can
     * describe a pending action without re-deriving it from raw arguments.
     *
     * @param  ToolCall[]  $calls
     */
    private function describeCalls(ToolContext $context, array $calls, ?string $status): array
    {
        return array_map(function (ToolCall $call) use ($context, $status) {
            $tool = $this->registry->resolveFor($context, $call->name);

            return [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
                'summary' => $tool?->summarize($call->arguments) ?? $call->name,
                'destructive' => (bool) $tool?->isDestructive(),
                'status' => $status ?? 'pending',
                'ok' => null,
            ];
        }, $calls);
    }

    /**
     * Rebuilds the provider-shaped message list from stored history.
     *
     * @param  array<string, ChatbotTool>  $tools
     */
    private function buildProviderMessages(ChatbotConversation $conversation, ToolContext $context, array $tools): array
    {
        $systemPrompt = $this->promptBuilder->build($context, $tools);

        // The budget covers the whole request, so the instructions and any
        // existing summary are charged against it before the history is chosen.
        $reserved = $this->estimateTokens(['role' => 'system', 'content' => $systemPrompt])
            + $this->estimateTokens(['role' => 'system', 'content' => (string) $conversation->summary]);

        $history = $this->selectHistory($conversation, $reserved);

        $summary = $this->settings->compactionEnabled()
            ? $this->summarizeDropped($conversation, $history->first()?->id)
            : $conversation->summary;

        $messages = [[
            'role' => 'system',
            'content' => $systemPrompt,
        ]];

        if ($summary) {
            $messages[] = [
                'role' => 'system',
                'content' => "Summary of the earlier part of this conversation, which is no longer shown in full:\n".$summary,
            ];
        }

        // Full tool output is only useful while the model is still working on the
        // turn that produced it; older results are collapsed to a note so a single
        // file read cannot crowd out the rest of the conversation.
        $currentTurnStart = $this->currentTurnStartId($conversation);

        foreach ($history as $message) {
            $rendered = $this->renderForProvider($message, $message->id < $currentTurnStart);

            if ($rendered !== null) {
                $messages[] = $rendered;
            }
        }

        return $messages;
    }

    /**
     * Chooses the newest slice of the conversation that fits the token budget,
     * without splitting an assistant turn from its tool results.
     *
     * @return Collection<int, ChatbotMessage>
     */
    private function selectHistory(ChatbotConversation $conversation, int $reserved = 0): Collection
    {
        $candidates = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit($this->settings->historyLimit())
            ->get();

        $currentTurnStart = $this->currentTurnStartId($conversation);

        // Never let the reservation starve the history entirely: a verbose
        // system prompt should shrink the window, not empty it.
        $budget = max(1000, $this->settings->contextTokens() - $reserved);
        /** @var Collection<int, ChatbotMessage> $selected */
        $selected = collect();

        foreach ($candidates as $message) {
            $rendered = $this->renderForProvider($message, $message->id < $currentTurnStart);

            if ($rendered === null) {
                continue;
            }

            $budget -= $this->estimateTokens($rendered);

            if ($budget < 0 && $selected->isNotEmpty()) {
                break;
            }

            $selected->prepend($message);
        }

        // Trim from the front until the window starts on a user message: an
        // assistant turn separated from its tool results is invalid input.
        while ($selected->isNotEmpty() && $selected->first()->role !== ChatbotMessage::ROLE_USER) {
            $selected->shift();
        }

        // A single turn larger than the whole budget trims away to nothing.
        // Replay it in full rather than sending the model no idea what it was
        // asked to do; the provider's own limit is the only bound left.
        if ($selected->isEmpty()) {
            return $conversation->messages()
                ->when($currentTurnStart, fn ($query) => $query->where('id', '>=', $currentTurnStart))
                ->get()
                ->values();
        }

        return $selected->values();
    }

    /**
     * The id of the user message that began the turn in progress.
     */
    private function currentTurnStartId(ChatbotConversation $conversation): int
    {
        return (int) $conversation->messages()
            ->where('role', ChatbotMessage::ROLE_USER)
            ->reorder('id', 'desc')
            ->value('id');
    }

    /**
     * Shapes one stored message for the provider, or null if it should not be
     * replayed at all.
     *
     * @return array<string, mixed>|null
     */
    private function renderForProvider(ChatbotMessage $message, bool $isOlderTurn): ?array
    {
        if ($message->role === ChatbotMessage::ROLE_USER) {
            return ['role' => 'user', 'content' => (string) $message->content];
        }

        if ($message->role === ChatbotMessage::ROLE_TOOL) {
            return [
                'role' => 'tool',
                'tool_call_id' => (string) $message->tool_call_id,
                'content' => $isOlderTurn ? $this->digestToolResult($message) : (string) $message->content,
            ];
        }

        // Panel-generated failures were never produced by the model, so
        // replaying them would teach it to imitate error messages.
        if ($message->status === ChatbotMessage::STATUS_FAILED) {
            return null;
        }

        // `reasoning` is deliberately not replayed. Providers that expose it
        // (DeepSeek among them) reject or degrade on requests that echo a
        // previous turn's chain-of-thought back at them.
        $assistant = ['role' => 'assistant', 'content' => $message->content];

        if ($message->tool_calls) {
            $assistant['tool_calls'] = array_map(
                fn (ToolCall $call) => $call->toArray(),
                $message->toolCalls(),
            );
        }

        return $assistant;
    }

    /**
     * Replaces a bulky older tool result with a one-line stand-in. The outcome
     * is preserved — the model still knows the call succeeded and what it was —
     * but the payload it already reasoned about is not sent again.
     */
    private function digestToolResult(ChatbotMessage $message): string
    {
        $content = (string) $message->content;

        if (strlen($content) <= self::TOOL_RESULT_DIGEST_THRESHOLD) {
            return $content;
        }

        $decoded = json_decode($content, true);
        $ok = is_array($decoded) ? ($decoded['ok'] ?? true) : true;

        return json_encode([
            'ok' => $ok,
            'note' => "Earlier result from {$message->tool_name}, omitted here to save space. Call the tool again if you need it.",
        ]) ?: $content;
    }

    /**
     * Rough token count. Deliberately an estimate: every provider tokenizes
     * differently, and the budget only needs to be approximately right.
     *
     * @param  array<string, mixed>  $message
     */
    private function estimateTokens(array $message): int
    {
        $text = json_encode($message) ?: '';

        // ~4 characters per token, plus a little per-message framing overhead.
        return (int) ceil(mb_strlen($text) / 4) + 4;
    }

    /**
     * Keeps the conversation's rolling summary caught up with everything that
     * has fallen out of the window, and returns it.
     *
     * Failure is not fatal: a conversation that cannot be summarized simply
     * loses its oldest messages, which is what would have happened anyway.
     */
    private function summarizeDropped(ChatbotConversation $conversation, ?int $oldestIncludedId): ?string
    {
        if (! $oldestIncludedId) {
            return $conversation->summary;
        }

        $pending = $conversation->messages()
            ->where('id', '<', $oldestIncludedId)
            ->where('id', '>', (int) $conversation->summary_through_id)
            ->get();

        if ($pending->count() < 2) {
            return $conversation->summary;
        }

        $transcript = $pending
            ->map(fn (ChatbotMessage $m) => match ($m->role) {
                ChatbotMessage::ROLE_USER => 'User: '.$m->content,
                ChatbotMessage::ROLE_TOOL => 'Tool '.$m->tool_name.' returned: '.Str::limit((string) $m->content, 300),
                default => 'Assistant: '.Str::limit((string) $m->content, 600),
            })
            ->implode("\n");

        try {
            $completion = $this->client->chat([
                [
                    'role' => 'system',
                    'content' => 'You compress transcripts. Rewrite the exchange below as a terse third-person note of at most 150 words, preserving decisions made, actions taken on the server, file paths, values the user gave, and anything still outstanding. Drop pleasantries and reasoning. Output the note only.',
                ],
                [
                    'role' => 'user',
                    'content' => ($conversation->summary ? "Existing summary:\n{$conversation->summary}\n\nNewer exchange to fold in:\n" : '').$transcript,
                ],
            ]);
        } catch (ChatbotException $e) {
            Log::warning('Chatbot conversation summarization failed', [
                'conversation' => $conversation->uuid,
                'error' => $e->getMessage(),
            ]);

            return $conversation->summary;
        }

        $summary = trim((string) $completion->content);

        if ($summary === '') {
            return $conversation->summary;
        }

        $conversation->forceFill([
            'summary' => Str::limit($summary, self::MAX_SUMMARY_LENGTH, ''),
            'summary_through_id' => $pending->last()->id,
        ])->save();

        return $conversation->summary;
    }

    /**
     * Serializes turns within one conversation.
     *
     * A turn writes an assistant message, runs its tools and writes their
     * results as separate rows. Two turns running concurrently would interleave
     * those rows, and the resulting history — an assistant message whose tool
     * results are separated from it — is rejected outright by the provider on
     * the next request.
     *
     * The lock is deliberately not waited on: a second request means the user
     * double-submitted, and a turn can legitimately take minutes.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     *
     * @throws ChatbotException
     */
    private function withTurnLock(ChatbotConversation $conversation, \Closure $callback): mixed
    {
        // The TTL bounds the damage if the worker is killed mid-turn: the lock
        // frees itself rather than stranding the conversation. It still sits
        // well above the longest legitimate turn.
        $lock = Cache::lock("chatbot:conversation:{$conversation->id}", 300);

        if (! $lock->get()) {
            throw new ChatbotException('The assistant is still working on your previous message. Please wait for it to finish.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function contextFor(ChatbotConversation $conversation): ToolContext
    {
        return new ToolContext($conversation->server, $conversation->user);
    }

    private function store(ChatbotConversation $conversation, array $attributes): ChatbotMessage
    {
        $message = $conversation->messages()->create(array_merge(
            ['uuid' => Str::uuid()->toString()],
            $attributes,
        ));

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
    }

    private function encode(array $result): string
    {
        return json_encode($result) ?: '{"ok":false,"error":"The result could not be encoded."}';
    }

    /**
     * @throws ChatbotException
     */
    private function assertEnabled(): void
    {
        if (! $this->settings->isEnabled()) {
            throw new ChatbotException('The AI assistant is not enabled on this panel.');
        }
    }
}
