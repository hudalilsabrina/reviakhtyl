<?php

use App\Services\Chatbot\ToolExecutor;

/**
 * redact() and truncate() are private, and deliberately so — they are internal
 * to execute(). They are also the two places where a mistake leaks a file body
 * into the activity log or blows out the model's context window, so they are
 * exercised directly through reflection.
 *
 * The executor is built without its constructor: neither method touches the
 * tool registry, and building one would pull the whole container in.
 */
function toolExecutorCall(string $method, mixed ...$arguments): mixed
{
    static $executor = null;

    $executor ??= (new ReflectionClass(ToolExecutor::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(ToolExecutor::class, $method))->invoke($executor, ...$arguments);
}

function chatbotRedact(array $arguments): array
{
    return toolExecutorCall('redact', $arguments);
}

function chatbotTruncate(array $result): array
{
    return toolExecutorCall('truncate', $result);
}

describe('redact', function () {
    it('replaces string values longer than 512 characters', function () {
        $redacted = chatbotRedact(['content' => str_repeat('a', 900)]);

        expect($redacted['content'])->toBe('(900 characters omitted)');
    });

    it('leaves a value of exactly the limit alone', function () {
        $value = str_repeat('a', 512);

        expect(chatbotRedact(['content' => $value])['content'])->toBe($value);

        expect(chatbotRedact(['content' => $value.'b'])['content'])->toBe('(513 characters omitted)');
    });

    it('leaves short strings untouched', function () {
        $arguments = ['path' => '/server.properties', 'action' => 'restart'];

        expect(chatbotRedact($arguments))->toBe($arguments);
    });

    it('leaves non-string values untouched', function () {
        $arguments = ['lines' => 250, 'recursive' => true, 'since' => null, 'ratio' => 0.5];

        expect(chatbotRedact($arguments))->toBe($arguments);
    });

    it('recurses into nested arrays', function () {
        $redacted = chatbotRedact([
            'files' => [
                'short.txt',
                str_repeat('b', 600),
                'nested' => ['deep' => str_repeat('c', 1024), 'fine' => 'ok'],
            ],
        ]);

        expect($redacted['files'][0])->toBe('short.txt')
            ->and($redacted['files'][1])->toBe('(600 characters omitted)')
            ->and($redacted['files']['nested']['deep'])->toBe('(1024 characters omitted)')
            ->and($redacted['files']['nested']['fine'])->toBe('ok');
    });

    it('preserves keys and the overall shape', function () {
        $redacted = chatbotRedact(['path' => '/eula.txt', 'content' => str_repeat('x', 1000)]);

        expect(array_keys($redacted))->toBe(['path', 'content']);
    });

    it('handles an empty argument list', function () {
        expect(chatbotRedact([]))->toBe([]);
    });
});

describe('truncate', function () {
    it('returns a small result unchanged', function () {
        $result = ['ok' => true, 'content' => 'eula=true'];

        expect(chatbotTruncate($result))->toBe($result);
    });

    it('trims an oversized string on the content key', function () {
        $result = chatbotTruncate(['ok' => true, 'content' => str_repeat('a', 20000)]);

        expect(strlen($result['content']))->toBe(12000)
            ->and($result['truncated'])->toBeTrue()
            ->and($result['ok'])->toBeTrue();
    });

    it('trims an oversized string on the output key', function () {
        $result = chatbotTruncate(['ok' => true, 'output' => str_repeat('b', 20000)]);

        expect(strlen($result['output']))->toBe(12000)
            ->and($result['truncated'])->toBeTrue();
    });

    it('caps an oversized list on the files key at a hundred entries', function () {
        $files = array_map(fn (int $i) => ['name' => "file-$i.txt", 'size' => $i * 1024], range(1, 800));

        $result = chatbotTruncate(['ok' => true, 'files' => $files]);

        expect($result['files'])->toHaveCount(100)
            ->and($result['files'][0]['name'])->toBe('file-1.txt')
            ->and($result['truncated'])->toBeTrue();
    });

    it('caps an oversized list on the entries key', function () {
        $entries = array_map(fn (int $i) => ['event' => "server:event.$i", 'note' => str_repeat('n', 50)], range(1, 500));

        $result = chatbotTruncate(['ok' => true, 'entries' => $entries]);

        expect($result['entries'])->toHaveCount(100)
            ->and($result['truncated'])->toBeTrue();
    });

    it('uses the first trimmable key it finds', function () {
        $result = chatbotTruncate([
            'ok' => true,
            'content' => str_repeat('a', 20000),
            'output' => str_repeat('b', 20000),
        ]);

        expect(strlen($result['content']))->toBe(12000)
            ->and(strlen($result['output']))->toBe(20000);
    });

    it('falls back to a note when no trimmable key exists', function () {
        $result = chatbotTruncate(['ok' => true, 'variables' => str_repeat('v', 20000)]);

        expect($result)->toBe([
            'ok' => true,
            'truncated' => true,
            'note' => 'The result was too large to return in full. Narrow the request and try again.',
        ]);
    });

    it('keeps a failed result failed when falling back to the note', function () {
        $result = chatbotTruncate(['ok' => false, 'error' => str_repeat('e', 20000)]);

        expect($result['ok'])->toBeFalse()
            ->and($result['truncated'])->toBeTrue()
            ->and($result)->not->toHaveKey('error');
    });

    it('does not cut a multibyte character in half', function () {
        // Regression: truncate() used to slice with byte-wise substr(). A file
        // whose 12000th byte landed mid-UTF-8-sequence came back as invalid
        // UTF-8, json_encode() returned false, and the whole result was replaced
        // by "The result could not be encoded." — so reading any large file with
        // non-ASCII content failed outright instead of being truncated.
        $result = chatbotTruncate(['ok' => true, 'content' => str_repeat('a', 11999).str_repeat('é', 100)]);

        expect(mb_check_encoding($result['content'], 'UTF-8'))->toBeTrue()
            ->and(json_encode($result))->not->toBeFalse();
    });

    it('does not trim a result that is exactly at the limit', function () {
        // Exactly 12000 bytes of encoded JSON, padding out the envelope.
        $envelope = strlen((string) json_encode(['ok' => true, 'content' => '']));
        $result = ['ok' => true, 'content' => str_repeat('a', 12000 - $envelope)];

        expect(strlen((string) json_encode($result)))->toBe(12000);

        expect(chatbotTruncate($result))->toBe($result);
    });
});
