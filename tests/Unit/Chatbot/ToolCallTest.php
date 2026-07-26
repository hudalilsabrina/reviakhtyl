<?php

use App\Services\Chatbot\Data\ToolCall;

it('builds a tool call from a provider payload', function () {
    $call = ToolCall::fromArray([
        'id' => 'call_abc',
        'type' => 'function',
        'function' => ['name' => 'write_file', 'arguments' => '{"path":"/eula.txt","content":"eula=true"}'],
    ]);

    expect($call->id)->toBe('call_abc')
        ->and($call->name)->toBe('write_file')
        ->and($call->arguments)->toBe(['path' => '/eula.txt', 'content' => 'eula=true']);
});

it('accepts arguments that already arrived decoded', function () {
    $call = ToolCall::fromArray([
        'id' => 'call_abc',
        'function' => ['name' => 'power_action', 'arguments' => ['action' => 'restart']],
    ]);

    expect($call->arguments)->toBe(['action' => 'restart']);
});

it('degrades malformed argument JSON to an empty array', function (string $arguments) {
    $call = ToolCall::fromArray([
        'id' => 'call_abc',
        'function' => ['name' => 'read_file', 'arguments' => $arguments],
    ]);

    expect($call->arguments)->toBe([]);
})->with([
    'truncated object' => ['{"path": "/server.p'],
    'not json at all' => ['read the file please'],
    'a bare scalar' => ['"just a string"'],
    'empty string' => [''],
]);

it('generates an id when the provider omits one', function () {
    $call = ToolCall::fromArray(['function' => ['name' => 'read_file', 'arguments' => '{}']]);

    expect($call->id)->toStartWith('call_')
        ->and(strlen($call->id))->toBeGreaterThan(5);
});

it('yields an empty name for a payload with no function block', function () {
    $call = ToolCall::fromArray(['id' => 'call_abc']);

    expect($call->name)->toBe('')
        ->and($call->arguments)->toBe([]);
});

it('encodes back into the provider shape', function () {
    $call = new ToolCall('call_abc', 'read_file', ['path' => '/server.properties']);

    expect($call->toArray())->toBe([
        'id' => 'call_abc',
        'type' => 'function',
        'function' => [
            'name' => 'read_file',
            'arguments' => '{"path":"\/server.properties"}',
        ],
    ]);
});

it('encodes empty arguments as a JSON object and never as an array', function () {
    // Providers reject `"arguments": "[]"` when replaying the conversation.
    $call = new ToolCall('call_abc', 'get_server_details', []);

    expect($call->toArray()['function']['arguments'])->toBe('{}')
        ->not->toBe('[]');
});

it('round trips through fromArray and toArray', function () {
    $original = [
        'id' => 'call_abc',
        'type' => 'function',
        'function' => ['name' => 'list_files', 'arguments' => '{"directory":"plugins"}'],
    ];

    expect(ToolCall::fromArray($original)->toArray())->toBe($original);
});

it('round trips a call with no arguments', function () {
    $encoded = ToolCall::fromArray([
        'id' => 'call_abc',
        'function' => ['name' => 'get_server_resources', 'arguments' => '{}'],
    ])->toArray();

    expect($encoded['function']['arguments'])->toBe('{}');

    expect(ToolCall::fromArray($encoded)->arguments)->toBe([]);
});
