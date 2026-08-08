<?php

namespace App\Services\Chatbot\Concerns;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\Chatbot\Data\ToolCall;
use App\Services\Chatbot\ToolContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The turn machinery shared by the flat loop (ChatbotService) and the
 * orchestrating router (RoutingService), kept in one place so the two can
 * never drift apart in how history is windowed, tool results are digested or
 * messages are stored.
 *
 * The methods reference $this->settings, $this->client and $this->registry;
 * every consuming class must declare those properties.
 */
trait ManagesChatbotTurns
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
}
