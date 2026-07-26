<?php

namespace App\Services\Chatbot;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Services\Chatbot\Data\ChatCompletion;
use App\Services\Chatbot\Data\StreamAccumulator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin client for any provider exposing the OpenAI chat completions API.
 *
 * Only the pieces the assistant needs are implemented — chat completions with
 * tool calling, plus a model listing used by the admin "test connection" button.
 */
class OpenAiClient
{
    public function __construct(private ChatbotSettings $settings) {}

    /**
     * Sends a conversation to the provider and returns the assistant's turn.
     *
     * @param  array<int, array<string, mixed>>  $messages  provider-shaped message list
     * @param  array<int, array<string, mixed>>  $tools  provider-shaped tool definitions
     *
     * @throws ChatbotException
     */
    public function chat(array $messages, array $tools = []): ChatCompletion
    {
        $payload = $this->payload($messages, $tools);

        try {
            $response = $this->request('post', '/chat/completions', $payload);
        } catch (ChatbotException $e) {
            // Newer OpenAI models reject `max_tokens` and demand
            // `max_completion_tokens`, while most other OpenAI-compatible
            // providers only understand the older key. Rather than make the
            // administrator know which is which, retry once with the other one.
            if (! str_contains($e->getMessage(), 'max_completion_tokens')) {
                throw $e;
            }

            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens'], $payload['temperature']);

            $response = $this->request('post', '/chat/completions', $payload);
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['choices'][0])) {
            throw new ChatbotException('The AI provider returned a response the panel could not understand.');
        }

        return ChatCompletion::fromResponse($body);
    }

    /**
     * The request body shared by the blocking and streaming paths, so the two
     * cannot drift apart in what they ask the model for.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->settings->model(),
            'messages' => $messages,
            'temperature' => $this->settings->temperature(),
            'max_tokens' => $this->settings->maxTokens(),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * Streams a completion, invoking $onText with each fragment of the answer as
     * it arrives, and returning the assembled turn.
     *
     * Falls back to the blocking path when the provider rejects streaming, so a
     * provider that does not support it degrades to the previous behaviour
     * rather than failing the message.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  callable(string): void  $onText
     *
     * @throws ChatbotException
     */
    public function stream(array $messages, array $tools, callable $onText): ChatCompletion
    {
        $payload = $this->payload($messages, $tools) + ['stream' => true];
        $accumulator = new StreamAccumulator();

        try {
            $response = Http::withToken($this->settings->apiKey())
                ->withOptions(['stream' => true])
                ->accept('text/event-stream')
                ->timeout($this->settings->timeout())
                ->connectTimeout(15)
                ->post($this->settings->baseUrl().'/chat/completions', $payload);
        } catch (\Throwable $e) {
            Log::warning('Chatbot streaming request failed, falling back', ['error' => $e->getMessage()]);

            return $this->chat($messages, $tools);
        }

        if (! $response->successful()) {
            Log::warning('Chatbot provider rejected streaming, falling back', [
                'status' => $response->status(),
            ]);

            return $this->chat($messages, $tools);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // Events are separated by a blank line; a read can land anywhere,
            // so only whole events are consumed and the remainder is kept.
            while (($break = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 2);

                foreach ($this->dataLines($event) as $data) {
                    if ($data === '[DONE]') {
                        return $accumulator->toCompletion();
                    }

                    $decoded = json_decode($data, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    if ($text = $accumulator->push($decoded)) {
                        $onText($text);
                    }
                }
            }
        }

        return $accumulator->toCompletion();
    }

    /**
     * The `data:` payloads of one SSE event. Comment lines (`:` keep-alives) and
     * other fields are ignored.
     *
     * @return string[]
     */
    private function dataLines(string $event): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $lines[] = trim(substr($line, 5));
            }
        }

        return $lines;
    }

    /**
     * Returns the model identifiers the provider advertises.
     *
     * @return string[]
     *
     * @throws ChatbotException
     */
    public function models(): array
    {
        $body = $this->request('get', '/models')->json();

        return collect($body['data'] ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    /**
     * Verifies credentials against an arbitrary endpoint. Used by the admin
     * settings page before the values are persisted.
     *
     * @return array{ok: bool, message: string, models: string[]}
     */
    public static function verify(string $baseUrl, string $apiKey, int $timeout = 15): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->get($baseUrl.'/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'models' => []];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => self::errorMessage($response),
                'models' => [],
            ];
        }

        $models = collect($response->json('data') ?? [])
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        return ['ok' => true, 'message' => 'Connected successfully.', 'models' => $models];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ChatbotException
     */
    private function request(string $method, string $path, array $payload = []): Response
    {
        if (! $this->settings->isEnabled()) {
            throw new ChatbotException('The AI assistant is not configured on this panel.');
        }

        try {
            $request = Http::withToken($this->settings->apiKey())
                ->acceptJson()
                ->timeout($this->settings->timeout())
                ->connectTimeout(15);

            $response = $method === 'get'
                ? $request->get($this->settings->baseUrl().$path)
                : $request->post($this->settings->baseUrl().$path, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Chatbot provider unreachable', ['error' => $e->getMessage()]);

            throw new ChatbotException('Could not reach the AI provider. Please try again in a moment.', $e);
        } catch (\Throwable $e) {
            Log::error('Chatbot request failed', ['error' => $e->getMessage()]);

            throw new ChatbotException('The request to the AI provider failed.', $e);
        }

        if (! $response->successful()) {
            $message = self::errorMessage($response);

            Log::warning('Chatbot provider returned an error', [
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new ChatbotException("The AI provider rejected the request: $message");
        }

        return $response;
    }

    /**
     * Pulls the most useful error string out of a failed provider response.
     */
    private static function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $message = $body['error']['message'] ?? $body['message'] ?? $body['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'HTTP '.$response->status();
    }
}
