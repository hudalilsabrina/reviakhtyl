<?php

namespace App\Services\Chatbot\Data;

/**
 * A single tool invocation requested by the model.
 */
class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** @var array<string, mixed> */
        public readonly array $arguments,
    ) {}

    /**
     * Builds a tool call from the provider's raw payload. Arguments arrive as a
     * JSON string that models occasionally emit malformed, so decoding failures
     * degrade to an empty argument list rather than taking the request down.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $function = $payload['function'] ?? [];
        $arguments = $function['arguments'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: (string) ($payload['id'] ?? uniqid('call_')),
            name: (string) ($function['name'] ?? ''),
            arguments: is_array($arguments) ? $arguments : [],
        );
    }

    /**
     * The representation stored on the message and replayed to the provider.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($this->arguments === [] ? new \stdClass() : $this->arguments),
            ],
        ];
    }
}
