<?php

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Services\Chatbot\ChatbotSettings;
use App\Services\Chatbot\OpenAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A ChatbotSettings that answers from memory. Every accessor the client uses is
 * overridden, so the parent's settings lookup — and the settings table behind
 * it — is never reached.
 */
function fakeChatbotSettings(array $overrides = []): ChatbotSettings
{
    return new class($overrides) extends ChatbotSettings
    {
        public function __construct(private array $overrides = []) {}

        public function isEnabled(): bool
        {
            return $this->overrides['enabled'] ?? true;
        }

        public function baseUrl(): string
        {
            return $this->overrides['base_url'] ?? 'https://provider.test/v1';
        }

        public function apiKey(): string
        {
            return $this->overrides['api_key'] ?? 'sk-test';
        }

        public function model(): string
        {
            return $this->overrides['model'] ?? 'gpt-4o-mini';
        }

        public function temperature(): float
        {
            return $this->overrides['temperature'] ?? 0.2;
        }

        public function maxTokens(): int
        {
            return $this->overrides['max_tokens'] ?? 1024;
        }

        public function timeout(): int
        {
            return $this->overrides['timeout'] ?? 30;
        }
    };
}

function fakeChatbotClient(array $overrides = []): OpenAiClient
{
    return new OpenAiClient(fakeChatbotSettings($overrides));
}

beforeEach(function () {
    // Nothing in this file may leave the machine.
    Http::preventStrayRequests();
    Log::spy();
});

it('parses a successful chat completion', function () {
    Http::fake([
        'provider.test/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Your server is online.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['total_tokens' => 42],
        ]),
    ]);

    $completion = fakeChatbotClient()->chat([['role' => 'user', 'content' => 'status?']]);

    expect($completion->content)->toBe('Your server is online.')
        ->and($completion->finishReason)->toBe('stop')
        ->and($completion->usage)->toBe(['total_tokens' => 42]);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://provider.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'gpt-4o-mini'
            && $request['max_tokens'] === 1024
            && ! isset($request['tools']);
    });
});

it('sends tool definitions and an automatic tool choice when tools are given', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
    ]);

    fakeChatbotClient()->chat(
        [['role' => 'user', 'content' => 'hi']],
        [['type' => 'function', 'function' => ['name' => 'read_file', 'parameters' => ['type' => 'object']]]],
    );

    Http::assertSent(fn (Request $request) => $request['tool_choice'] === 'auto'
        && $request['tools'][0]['function']['name'] === 'read_file');
});

it('turns a provider error into a ChatbotException carrying the provider message', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401),
    ]);

    expect(fn () => fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'The AI provider rejected the request: Incorrect API key provided.');
});

it('falls back to the status code when the error body has no message', function () {
    Http::fake(['*' => Http::response('gateway is down', 502)]);

    expect(fn () => fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'The AI provider rejected the request: HTTP 502');
});

it('refuses to call out at all when the assistant is not configured', function () {
    Http::fake();

    expect(fn () => fakeChatbotClient(['enabled' => false])->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'The AI assistant is not configured on this panel.');

    Http::assertNothingSent();
});

it('rejects a success response that has no choices', function () {
    Http::fake(['*' => Http::response(['id' => 'chatcmpl-1'])]);

    expect(fn () => fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'The AI provider returned a response the panel could not understand.');
});

it('retries with max_completion_tokens when the provider demands it', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."]], 400)
            ->push(['choices' => [['message' => ['content' => 'Retried fine.'], 'finish_reason' => 'stop']]], 200),
    ]);

    $completion = fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]);

    expect($completion->content)->toBe('Retried fine.');

    $requests = collect(Http::recorded())->map(fn (array $pair) => $pair[0]);

    expect($requests)->toHaveCount(2);

    $first = $requests[0]->data();
    $second = $requests[1]->data();

    expect($first)->toHaveKey('max_tokens')
        ->and($first)->toHaveKey('temperature')
        ->and($first)->not->toHaveKey('max_completion_tokens');

    // The retry has to drop both keys: the models that reject max_tokens reject
    // a non-default temperature too.
    expect($second['max_completion_tokens'])->toBe(1024)
        ->and($second)->not->toHaveKey('max_tokens')
        ->and($second)->not->toHaveKey('temperature')
        ->and($second['messages'])->toBe([['role' => 'user', 'content' => 'hi']]);
});

it('does not retry for an unrelated provider error', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit reached.']], 429)
            ->push(['choices' => [['message' => ['content' => 'never reached']]]], 200),
    ]);

    expect(fn () => fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'Rate limit reached.');

    expect(Http::recorded())->toHaveCount(1);
});

it('lists the model identifiers the provider advertises', function () {
    Http::fake([
        'provider.test/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-4o-mini'],
            ['id' => 'gpt-4o'],
            ['id' => ''],
            ['name' => 'no id here'],
        ]]),
    ]);

    expect(fakeChatbotClient()->models())->toBe(['gpt-4o-mini', 'gpt-4o']);
});

describe('verify', function () {
    it('reports success with the advertised models', function () {
        Http::fake([
            'provider.test/v1/models' => Http::response(['data' => [['id' => 'gpt-4o'], ['id' => 'o3-mini']]]),
        ]);

        $result = OpenAiClient::verify('https://provider.test/v1/', 'sk-test');

        expect($result['ok'])->toBeTrue()
            ->and($result['message'])->toBe('Connected successfully.')
            ->and($result['models'])->toBe(['gpt-4o', 'o3-mini']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://provider.test/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    });

    it('reports the provider message on a failed response', function () {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Invalid authentication credentials.']], 401),
        ]);

        $result = OpenAiClient::verify('https://provider.test/v1', 'sk-wrong');

        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->toBe('Invalid authentication credentials.')
            ->and($result['models'])->toBe([]);
    });

    it('falls back to the status code when the failure body is unhelpful', function () {
        Http::fake(['*' => Http::response('', 503)]);

        $result = OpenAiClient::verify('https://provider.test/v1', 'sk-test');

        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->toBe('HTTP 503');
    });

    it('reports a connection failure instead of throwing', function () {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $result = OpenAiClient::verify('https://provider.test/v1', 'sk-test');

        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->toBe('Could not resolve host.')
            ->and($result['models'])->toBe([]);
    });
});

it('reports an unreachable provider as a friendly message', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

    expect(fn () => fakeChatbotClient()->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(ChatbotException::class, 'Could not reach the AI provider. Please try again in a moment.');
});
