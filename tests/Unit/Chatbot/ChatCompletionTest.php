<?php

use App\Services\Chatbot\Data\ChatCompletion;
use App\Services\Chatbot\Data\StreamAccumulator;
use App\Services\Chatbot\Data\ToolCall;

/**
 * Builds a provider payload around a single assistant message.
 */
function completionPayload(array $message, array $extra = []): array
{
    return array_replace_recursive([
        'choices' => [
            ['message' => $message, 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], $extra);
}

it('parses a plain content response', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'role' => 'assistant',
        'content' => 'Your server is running.',
    ]));

    expect($completion->content)->toBe('Your server is running.')
        ->and($completion->reasoning)->toBeNull()
        ->and($completion->toolCalls)->toBe([])
        ->and($completion->hasToolCalls())->toBeFalse()
        ->and($completion->finishReason)->toBe('stop')
        ->and($completion->usage)->toBe(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);
});

it('returns null rather than an empty string when there is no content', function (mixed $content) {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'role' => 'assistant',
        'content' => $content,
    ]));

    expect($completion->content)->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace only' => ["  \n\t "],
    'empty array of parts' => [[]],
]);

it('returns null content when the message has no content key at all', function () {
    $completion = ChatCompletion::fromResponse(completionPayload(['role' => 'assistant']));

    expect($completion->content)->toBeNull()
        ->and($completion->reasoning)->toBeNull();
});

it('tolerates a payload without choices', function () {
    $completion = ChatCompletion::fromResponse([]);

    expect($completion->content)->toBeNull()
        ->and($completion->toolCalls)->toBe([])
        ->and($completion->finishReason)->toBeNull()
        ->and($completion->usage)->toBe([]);
});

it('lifts DeepSeek style reasoning_content out of the message', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'role' => 'assistant',
        'content' => 'Restart the server.',
        'reasoning_content' => "  The logs show a stalled thread.\n",
    ]));

    expect($completion->reasoning)->toBe('The logs show a stalled thread.')
        ->and($completion->content)->toBe('Restart the server.');
});

it('lifts OpenRouter style reasoning out of the message', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'role' => 'assistant',
        'content' => 'Restart the server.',
        'reasoning' => 'The logs show a stalled thread.',
    ]));

    expect($completion->reasoning)->toBe('The logs show a stalled thread.')
        ->and($completion->content)->toBe('Restart the server.');
});

it('prefers reasoning_content when a provider sends both fields', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => 'Done.',
        'reasoning_content' => 'dedicated field',
        'reasoning' => 'fallback field',
    ]));

    expect($completion->reasoning)->toBe('dedicated field');
});

it('ignores a blank reasoning field', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => 'Done.',
        'reasoning_content' => '   ',
    ]));

    expect($completion->reasoning)->toBeNull();
});

it('splits an inline think block out of the content', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => "<think>The user wants the player count.</think>\nThere are 4 players online.",
    ]));

    expect($completion->content)->toBe('There are 4 players online.')
        ->and($completion->reasoning)->toBe('The user wants the player count.');
});

it('concatenates multiple think blocks', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => '<think>First thought</think>Hello<think>Second thought</think> world',
    ]));

    expect($completion->content)->toBe('Hello world')
        ->and($completion->reasoning)->toBe("First thought\n\nSecond thought");
});

it('treats an unterminated think block as reasoning to the end of the content', function () {
    // The model hit its token cap mid-thought: half a monologue must never be
    // handed to the user as if it were the answer.
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => '<think>Checking the logs now, the crash looks like it came from',
    ]));

    expect($completion->content)->toBeNull()
        ->and($completion->reasoning)->toBe('Checking the logs now, the crash looks like it came from');
});

it('keeps the answer written before an unterminated think block', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => 'Here is the summary. <think>and now I keep going forever',
    ]));

    expect($completion->content)->toBe('Here is the summary.')
        ->and($completion->reasoning)->toBe('and now I keep going forever');
});

it('merges a separate reasoning field with an inline think block', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => '<think>inline thought</think>Answer.',
        'reasoning_content' => 'field thought',
    ]));

    expect($completion->content)->toBe('Answer.')
        ->and($completion->reasoning)->toBe("field thought\n\ninline thought");
});

it('leaves content untouched when there is no think tag', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => 'No thinking here, just an answer with a < sign.',
    ]));

    expect($completion->content)->toBe('No thinking here, just an answer with a < sign.')
        ->and($completion->reasoning)->toBeNull();
});

it('flattens content returned as an array of typed parts', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => [
            ['type' => 'text', 'text' => 'The server '],
            ['type' => 'text', 'text' => 'is online.'],
        ],
    ]));

    expect($completion->content)->toBe('The server is online.');
});

it('flattens array parts that are plain strings and skips textless parts', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => [
            'plain ',
            ['type' => 'text', 'text' => 'string'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/a.png']],
        ],
    ]));

    expect($completion->content)->toBe('plain string');
});

it('splits think tags out of flattened array content', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => [
            ['type' => 'text', 'text' => '<think>hidden</think>'],
            ['type' => 'text', 'text' => 'Visible.'],
        ],
    ]));

    expect($completion->content)->toBe('Visible.')
        ->and($completion->reasoning)->toBe('hidden');
});

it('parses tool calls into ToolCall objects', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'content' => null,
        'tool_calls' => [
            [
                'id' => 'call_abc',
                'type' => 'function',
                'function' => ['name' => 'read_file', 'arguments' => '{"path":"/server.properties"}'],
            ],
            [
                'id' => 'call_def',
                'type' => 'function',
                'function' => ['name' => 'list_files', 'arguments' => '{"directory":"/"}'],
            ],
        ],
    ], ['choices' => [['finish_reason' => 'tool_calls']]]));

    expect($completion->hasToolCalls())->toBeTrue()
        ->and($completion->toolCalls)->toHaveCount(2)
        ->and($completion->toolCalls[0])->toBeInstanceOf(ToolCall::class)
        ->and($completion->toolCalls[0]->id)->toBe('call_abc')
        ->and($completion->toolCalls[0]->name)->toBe('read_file')
        ->and($completion->toolCalls[0]->arguments)->toBe(['path' => '/server.properties'])
        ->and($completion->toolCalls[1]->name)->toBe('list_files')
        ->and($completion->finishReason)->toBe('tool_calls')
        ->and($completion->content)->toBeNull();
});

it('degrades malformed tool call arguments to an empty array instead of throwing', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'tool_calls' => [
            [
                'id' => 'call_bad',
                'function' => ['name' => 'read_file', 'arguments' => '{"path": "/server.properties'],
            ],
        ],
    ]));

    expect($completion->toolCalls)->toHaveCount(1)
        ->and($completion->toolCalls[0]->name)->toBe('read_file')
        ->and($completion->toolCalls[0]->arguments)->toBe([]);
});

it('drops tool calls that have no name', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'tool_calls' => [
            ['id' => 'call_1', 'function' => ['name' => '', 'arguments' => '{}']],
            ['id' => 'call_2', 'function' => ['arguments' => '{}']],
            ['id' => 'call_3', 'function' => ['name' => 'power_action', 'arguments' => '{"action":"restart"}']],
        ],
    ]));

    expect($completion->toolCalls)->toHaveCount(1)
        ->and($completion->toolCalls[0]->name)->toBe('power_action');

    // The surviving call must be re-indexed from zero, since the list is
    // encoded straight back to the provider as a JSON array.
    expect(array_keys($completion->toolCalls))->toBe([0]);
});

it('ignores tool call entries that are not arrays', function () {
    $completion = ChatCompletion::fromResponse(completionPayload([
        'tool_calls' => [
            'garbage',
            ['id' => 'call_1', 'function' => ['name' => 'get_server_details', 'arguments' => '{}']],
        ],
    ]));

    expect($completion->toolCalls)->toHaveCount(1)
        ->and($completion->toolCalls[0]->name)->toBe('get_server_details');
});

it('forwards streamed reasoning and content fragments separately', function () {
    $accumulator = new StreamAccumulator();

    $first = $accumulator->push([
        'choices' => [['delta' => ['reasoning_content' => 'The logs show ']]],
    ]);

    $second = $accumulator->push([
        'choices' => [['delta' => ['reasoning_content' => 'a stalled thread.', 'content' => 'I found it.']]],
    ]);

    $third = $accumulator->push([
        'choices' => [['delta' => ['content' => ' The issue is the config.']]],
    ]);

    expect($first)->toBe(['content' => '', 'reasoning' => 'The logs show '])
        ->and($second)->toBe(['content' => 'I found it.', 'reasoning' => 'a stalled thread.'])
        ->and($third)->toBe(['content' => ' The issue is the config.', 'reasoning' => '']);

    $completion = $accumulator->toCompletion();

    expect($completion->content)->toBe('I found it. The issue is the config.')
        ->and($completion->reasoning)->toBe('The logs show a stalled thread.');
});
