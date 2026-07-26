<?php

namespace App\Services\Chatbot\Data;

/**
 * A normalized assistant turn returned by the provider.
 */
class ChatCompletion
{
    public function __construct(
        public readonly ?string $content,
        /** @var ToolCall[] */
        public readonly array $toolCalls,
        public readonly ?string $finishReason,
        /** @var array<string, mixed> */
        public readonly array $usage,
        /** Chain-of-thought, when the model exposes it separately from the answer. */
        public readonly ?string $reasoning = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $message = $payload['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? null;

        // Some providers return content as an array of typed parts rather than
        // a plain string; flatten those to text so the panel can display them.
        if (is_array($content)) {
            // No filter(): a bare filter() drops '0', so a part whose text is
            // exactly "0" would vanish from the answer.
            $content = collect($content)
                ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part)
                ->implode('');
        }

        // Providers disagree on where reasoning lives: a dedicated field on the
        // message (DeepSeek, vLLM, OpenRouter) or inline <think> tags in the
        // content itself. Both are pulled out so the answer stays clean.
        $reasoning = $message['reasoning_content'] ?? $message['reasoning'] ?? null;
        $reasoning = is_string($reasoning) && trim($reasoning) !== '' ? trim($reasoning) : null;

        if (is_string($content)) {
            [$content, $inline] = self::splitThinkTags($content);

            if ($inline !== null) {
                $reasoning = trim(($reasoning ?? '')."\n\n".$inline);
            }
        }

        $toolCalls = array_map(
            fn (array $call) => ToolCall::fromArray($call),
            array_values(array_filter((array) ($message['tool_calls'] ?? []), 'is_array')),
        );

        return new self(
            content: is_string($content) && trim($content) !== '' ? $content : null,
            toolCalls: array_values(array_filter($toolCalls, fn (ToolCall $call) => $call->name !== '')),
            finishReason: $payload['choices'][0]['finish_reason'] ?? null,
            usage: (array) ($payload['usage'] ?? []),
            reasoning: $reasoning,
        );
    }

    /**
     * Separates inline `<think>…</think>` blocks from the answer.
     *
     * A block left unterminated — the model hit its token limit mid-thought —
     * is treated as reasoning all the way to the end, so half a monologue is
     * never presented to the user as the answer.
     *
     * @return array{0: string, 1: string|null} the content, and the reasoning if any
     */
    private static function splitThinkTags(string $content): array
    {
        if (! str_contains($content, '<think>')) {
            return [$content, null];
        }

        preg_match_all('/<think>(.*?)<\/think>/s', $content, $matches);
        $thoughts = array_map('trim', $matches[1]);

        $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);

        if (str_contains($content, '<think>')) {
            [$content, $unterminated] = explode('<think>', $content, 2);
            $thoughts[] = trim($unterminated);
        }

        $thoughts = array_values(array_filter($thoughts, fn (string $t) => $t !== ''));

        return [trim($content), $thoughts === [] ? null : implode("\n\n", $thoughts)];
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
