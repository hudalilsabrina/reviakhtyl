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
use Illuminate\Support\Str;

/**
 * Runs the assistant: turns a user message into an answer, calling tools in a
 * loop until the model is done, has hit its iteration budget, or needs the user
 * to approve something.
 */
class ChatbotService
{
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
    public function sendMessage(ChatbotConversation $conversation, string $content): Collection
    {
        $this->assertEnabled();

        if ($this->pendingConfirmation($conversation)) {
            throw new ChatbotException('The assistant is waiting for you to approve or deny its last proposed action.');
        }

        $created = collect([
            $this->store($conversation, [
                'role' => ChatbotMessage::ROLE_USER,
                'content' => $content,
            ]),
        ]);

        if (! $conversation->title) {
            $conversation->update(['title' => $conversation->titleFrom($content)]);
        }

        return $created->concat($this->run($conversation));
    }

    /**
     * Resolves a pending confirmation and continues the conversation.
     *
     * @return Collection<int, ChatbotMessage>
     */
    public function resolveConfirmation(ChatbotConversation $conversation, ChatbotMessage $message, bool $approved): Collection
    {
        $this->assertEnabled();

        if (! $message->isAwaitingConfirmation() || $message->conversation_id !== $conversation->id) {
            throw new ChatbotException('That action is no longer waiting for a decision.');
        }

        $context = $this->contextFor($conversation);
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

            // Every tool call must be answered by a tool message, otherwise the
            // provider rejects the next request as an unresolved call.
            $created->push($this->store($conversation, [
                'role' => ChatbotMessage::ROLE_TOOL,
                'tool_call_id' => $id,
                'tool_name' => $name,
                'content' => $this->encode($result),
            ]));
        }

        $message->update([
            'tool_calls' => $calls,
            'status' => $approved ? ChatbotMessage::STATUS_COMPLETE : ChatbotMessage::STATUS_DENIED,
        ]);

        return $created->concat($this->run($conversation));
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
    private function run(ChatbotConversation $conversation): Collection
    {
        $context = $this->contextFor($conversation);
        $tools = $this->registry->availableFor($context);
        $definitions = array_values(array_map(fn (ChatbotTool $tool) => $tool->definition(), $tools));

        $created = collect();

        for ($iteration = 0; $iteration < $this->settings->maxIterations(); $iteration++) {
            try {
                $completion = $this->client->chat(
                    $this->buildProviderMessages($conversation, $context, $tools),
                    $definitions,
                );
            } catch (ChatbotException $e) {
                return $created->push($this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_ASSISTANT,
                    'content' => $e->getMessage(),
                    'status' => ChatbotMessage::STATUS_FAILED,
                ]));
            }

            if (! $completion->hasToolCalls()) {
                return $created->push($this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_ASSISTANT,
                    'content' => $completion->content ?? 'I could not produce a response to that. Please rephrase and try again.',
                    'reasoning' => $completion->reasoning,
                ]));
            }

            $needsApproval = $this->settings->requiresConfirmation()
                && $this->containsDestructiveCall($context, $completion->toolCalls);

            $assistant = $this->store($conversation, [
                'role' => ChatbotMessage::ROLE_ASSISTANT,
                'content' => $completion->content,
                'reasoning' => $completion->reasoning,
                'tool_calls' => $this->describeCalls($context, $completion->toolCalls, $needsApproval ? 'pending' : null),
                'status' => $needsApproval ? ChatbotMessage::STATUS_AWAITING_CONFIRMATION : ChatbotMessage::STATUS_COMPLETE,
            ]);

            $created->push($assistant);

            // Hand control back to the user; resolveConfirmation() resumes here.
            if ($needsApproval) {
                return $created;
            }

            $calls = $assistant->tool_calls;

            foreach ($completion->toolCalls as $index => $call) {
                $result = $this->executor->execute($context, $call->name, $call->arguments);

                $calls[$index]['status'] = ($result['ok'] ?? false) ? 'executed' : 'failed';
                $calls[$index]['ok'] = (bool) ($result['ok'] ?? false);

                $created->push($this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_TOOL,
                    'tool_call_id' => $call->id,
                    'tool_name' => $call->name,
                    'content' => $this->encode($result),
                ]));
            }

            $assistant->update(['tool_calls' => $calls]);
        }

        return $created->push($this->store($conversation, [
            'role' => ChatbotMessage::ROLE_ASSISTANT,
            'content' => 'I stopped after taking too many steps in a row without reaching an answer. Tell me what to focus on and I will try a narrower approach.',
        ]));
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
        $history = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit($this->settings->historyLimit())
            ->get()
            ->reverse()
            ->values();

        // Trim from the front until the window starts on a user message: an
        // assistant turn separated from its tool results is invalid input.
        while ($history->isNotEmpty() && $history->first()->role !== ChatbotMessage::ROLE_USER) {
            $history->shift();
        }

        // A single turn that called more tools than the history limit trims away
        // to nothing. Replay the whole turn instead — sending only the system
        // prompt would leave the model with no idea what it was asked to do.
        if ($history->isEmpty()) {
            $start = $conversation->messages()
                ->where('role', ChatbotMessage::ROLE_USER)
                ->reorder('id', 'desc')
                ->value('id');

            $history = $conversation->messages()
                ->when($start, fn ($query) => $query->where('id', '>=', $start))
                ->get()
                ->values();
        }

        $messages = [[
            'role' => 'system',
            'content' => $this->promptBuilder->build($context, $tools),
        ]];

        foreach ($history as $message) {
            if ($message->role === ChatbotMessage::ROLE_USER) {
                $messages[] = ['role' => 'user', 'content' => (string) $message->content];

                continue;
            }

            if ($message->role === ChatbotMessage::ROLE_TOOL) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $message->tool_call_id,
                    'content' => (string) $message->content,
                ];

                continue;
            }

            // Panel-generated failures were never produced by the model, so
            // replaying them would teach it to imitate error messages.
            if ($message->status === ChatbotMessage::STATUS_FAILED) {
                continue;
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

            $messages[] = $assistant;
        }

        return $messages;
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
