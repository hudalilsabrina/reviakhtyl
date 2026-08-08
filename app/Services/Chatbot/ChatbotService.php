<?php

namespace App\Services\Chatbot;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\Concerns\ManagesChatbotTurns;
use App\Services\Chatbot\Data\ToolCall;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Runs the assistant: turns a user message into an answer, calling tools in a
 * loop until the model is done, has hit its iteration budget, or needs the user
 * to approve something.
 *
 * When orchestration is enabled the loop is delegated wholesale to
 * RoutingService, which swaps the single flat model for a router that fans out
 * to narrow sub-agents.
 */
class ChatbotService
{
    use ManagesChatbotTurns;

    public function __construct(
        private ChatbotSettings $settings,
        private OpenAiClient $client,
        private ToolRegistry $registry,
        private ToolExecutor $executor,
        private SystemPromptBuilder $promptBuilder,
        private RoutingService $routing,
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
     * The decision is per tool call: `$decisions` maps call ids to true
     * (run) or false (deny), so a user can approve one action in a batch and
     * refuse the rest. Every call still receives a tool result — a denial is
     * a result too — because the provider rejects the next request with an
     * unanswered tool_call_id.
     *
     * @param  array<string, bool>  $decisions
     * @return Collection<int, ChatbotMessage>
     */
    public function resolveConfirmation(
        ChatbotConversation $conversation,
        ChatbotMessage $message,
        array $decisions,
        ?callable $emit = null,
    ): Collection {
        $this->assertEnabled();

        return $this->withTurnLock($conversation, fn () => $this->runConfirmation($conversation, $message, $decisions, $emit));
    }

    /**
     * Regenerates the assistant's turn that starts at the given message.
     *
     * The target message and every message after it are removed, then the
     * conversation is re-run from its new tail.  The caller receives all
     * freshly created messages.
     *
     * @throws ChatbotException
     */
    public function regenerate(ChatbotConversation $conversation, ChatbotMessage $target): Collection
    {
        $this->assertEnabled();

        if ($target->conversation_id !== $conversation->id) {
            throw new ChatbotException('The requested message does not belong to this conversation.');
        }

        return $this->withTurnLock($conversation, function () use ($conversation, $target) {
            $cutoff = $conversation->messages()
                ->where('role', ChatbotMessage::ROLE_USER)
                ->where('id', '<=', $target->id)
                ->reorder('id', 'desc')
                ->first();

            if (! $cutoff) {
                return collect();
            }

            $conversation->messages()
                ->where('id', '>=', $cutoff->id)
                ->delete();

            return $this->run($conversation);
        });
    }

    /**
     * @param  array<string, bool>  $decisions
     * @return Collection<int, ChatbotMessage>
     */
    private function runConfirmation(
        ChatbotConversation $conversation,
        ChatbotMessage $message,
        array $decisions,
        ?callable $emit = null,
    ): Collection {
        if ($message->conversation_id !== $conversation->id) {
            throw new ChatbotException('That action is no longer waiting for a decision.');
        }

        $anyApproved = collect($decisions)->contains(true);

        // Claim the decision with a conditional update rather than a read
        // followed by a write. Two approvals arriving together would otherwise
        // both pass the check and run the tools twice — deleting the same files
        // or restarting the server a second time.
        $claimed = ChatbotMessage::query()
            ->whereKey($message->getKey())
            ->where('status', ChatbotMessage::STATUS_AWAITING_CONFIRMATION)
            ->update(['status' => $anyApproved ? ChatbotMessage::STATUS_COMPLETE : ChatbotMessage::STATUS_DENIED]);

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

            // Calls the user did not list are denied, not executed: a client
            // that sends a partial batch has decided against the rest.
            $approved = $decisions[$id] ?? false;

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
     * Runs one turn: the flat single-model loop, or the orchestrating router
     * when orchestration is enabled. A simple request routed by the router
     * comes back here through runFlatLoop().
     *
     * @return Collection<int, ChatbotMessage>
     */
    private function run(ChatbotConversation $conversation, ?callable $emit = null): Collection
    {
        if ($this->settings->orchestrationEnabled()) {
            return $this->routing->run($conversation, $emit);
        }

        return $this->runFlatLoop($conversation, $emit);
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
