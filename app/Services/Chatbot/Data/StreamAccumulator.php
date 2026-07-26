<?php

namespace App\Services\Chatbot\Data;

/**
 * Reassembles a streamed chat completion from its deltas.
 *
 * Content arrives as plain fragments, but tool calls arrive shredded: the name
 * appears in one chunk, the JSON arguments dribble in across many more, and the
 * only thing tying them together is an `index`. Providers also disagree on
 * whether the id and name repeat on later fragments, so both are only ever
 * filled in when seen and never overwritten with an empty value.
 */
class StreamAccumulator
{
    private string $content = '';

    private string $reasoning = '';

    /** @var array<int, array{id: string, name: string, arguments: string}> */
    private array $toolCalls = [];

    private ?string $finishReason = null;

    /** @var array<string, mixed> */
    private array $usage = [];

    /**
     * Folds one streamed chunk in, returning the text fragment it contributed
     * so the caller can forward it to the client immediately.
     *
     * @param  array<string, mixed>  $chunk
     */
    public function push(array $chunk): string
    {
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $this->usage = $chunk['usage'];
        }

        $choice = $chunk['choices'][0] ?? null;

        if (! is_array($choice)) {
            return '';
        }

        if (! empty($choice['finish_reason'])) {
            $this->finishReason = (string) $choice['finish_reason'];
        }

        $delta = $choice['delta'] ?? [];

        if (! is_array($delta)) {
            return '';
        }

        foreach (['reasoning_content', 'reasoning'] as $key) {
            if (isset($delta[$key]) && is_string($delta[$key])) {
                $this->reasoning .= $delta[$key];
            }
        }

        foreach ((array) ($delta['tool_calls'] ?? []) as $fragment) {
            if (is_array($fragment)) {
                $this->pushToolCall($fragment);
            }
        }

        $text = $delta['content'] ?? null;

        if (! is_string($text) || $text === '') {
            return '';
        }

        $this->content .= $text;

        return $text;
    }

    /**
     * @param  array<string, mixed>  $fragment
     */
    private function pushToolCall(array $fragment): void
    {
        $index = (int) ($fragment['index'] ?? count($this->toolCalls));

        $this->toolCalls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

        if (! empty($fragment['id'])) {
            $this->toolCalls[$index]['id'] = (string) $fragment['id'];
        }

        $function = $fragment['function'] ?? [];

        if (! is_array($function)) {
            return;
        }

        if (! empty($function['name'])) {
            $this->toolCalls[$index]['name'] = (string) $function['name'];
        }

        if (isset($function['arguments']) && is_string($function['arguments'])) {
            $this->toolCalls[$index]['arguments'] .= $function['arguments'];
        }
    }

    /**
     * The completed turn, in the same shape the non-streaming path produces so
     * everything downstream is unaware of how it was fetched.
     */
    public function toCompletion(): ChatCompletion
    {
        ksort($this->toolCalls);

        $payload = [
            'choices' => [[
                'message' => array_filter([
                    'content' => $this->content,
                    'reasoning_content' => $this->reasoning !== '' ? $this->reasoning : null,
                    'tool_calls' => array_values(array_map(fn (array $call) => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => $call['arguments'],
                        ],
                    ], $this->toolCalls)),
                ], fn ($value) => $value !== null && $value !== []),
                'finish_reason' => $this->finishReason,
            ]],
            'usage' => $this->usage,
        ];

        return ChatCompletion::fromResponse($payload);
    }
}
