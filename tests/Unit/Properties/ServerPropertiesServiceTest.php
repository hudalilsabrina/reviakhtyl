<?php

use App\Exceptions\DisplayException;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use App\Repositories\Eloquent\SettingsRepository;
use App\Services\Properties\ServerPropertiesService;
use GuzzleHttp\Psr7\Response;

/**
 * Test double that overrides the config-dependent and private methods so the
 * service can be exercised in a plain unit test (no database, no framework).
 */
class TestPropertiesService extends ServerPropertiesService
{
    /** @param array<string, array<string, mixed>> $definitions */
    public function __construct(
        DaemonFileRepository $fileRepository,
        SettingsRepository $settings,
        private array $definitions = [],
        private array $groups = ['general', 'gameplay', 'world', 'players', 'performance', 'network', 'security', 'other'],
        private array $eggIds = [],
    ) {
        parent::__construct($fileRepository, $settings);
    }

    public function definitions(): array
    {
        return $this->definitions;
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function enabledEggIds(): array
    {
        if ($this->eggIds !== null) {
            return $this->eggIds;
        }

        return [];
    }

    // Avoid parent::enabledEggIds() which reads from settings.
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->eggIds, true);
    }
}

/**
 * @param  array<string, mixed>  $settings  key/value pairs for settings::panel:properties
 * @param  array<string, string>  $files  path => content for the fake daemon file store
 * @param  array<string, array<string, mixed>>  $definitions  property definitions keyed by name
 * @param  array<int, string>  $groups  group render order
 */
function propertiesService(
    array $settings = [],
    array $files = [],
    array $definitions = [],
    array $groups = ['general', 'gameplay', 'world', 'players', 'performance', 'network', 'security', 'other']
): TestPropertiesService {
    $fileRepo = Mockery::mock(DaemonFileRepository::class);
    $fileRepo->shouldReceive('setServer')->andReturnSelf();
    $fileRepo->shouldReceive('getContent')->andReturnUsing(function (string $path, int $maxBytes) use (&$files) {
        if (! isset($files[$path])) {
            $ex = Mockery::mock(DaemonConnectionException::class);
            $ex->shouldReceive('getStatusCode')->andReturn(404);
            $ex->shouldReceive('getMessage')->andReturn('not found');

            throw $ex;
        }

        return $files[$path];
    });
    $fileRepo->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) use (&$files) {
        $files[$path] = $content;

        return new Response(200);
    });

    $settingsRepo = Mockery::mock(SettingsRepository::class);
    $settingsRepo->shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use ($settings) {
        $short = str_replace('settings::panel:properties:', '', $key);

        return $settings[$short] ?? $default;
    });

    return new TestPropertiesService(
        $fileRepo,
        $settingsRepo,
        $definitions,
        $groups,
        isset($settings['egg_ids']) ? (is_array($settings['egg_ids']) ? array_map('intval', $settings['egg_ids']) : array_map('intval', json_decode($settings['egg_ids'], true) ?: [])) : [],
    );
}

function server(int $eggId = 1): Server
{
    $s = Mockery::mock(Server::class)->makePartial();
    $s->egg_id = $eggId;

    return $s;
}

describe('enabledEggIds', function () {
    it('returns an empty array when nothing is stored', function () {
        expect(propertiesService()->enabledEggIds())->toBe([]);
    });

    it('returns egg ids from a stored JSON string', function () {
        expect(propertiesService(['egg_ids' => '[1,2,3]'])->enabledEggIds())->toBe([1, 2, 3]);
    });

    it('returns egg ids from a stored array', function () {
        expect(propertiesService(['egg_ids' => [5, 6]])->enabledEggIds())->toBe([5, 6]);
    });
});

describe('isEnabledFor', function () {
    it('returns true when the server egg is in the allowed list', function () {
        $service = propertiesService(['egg_ids' => [1, 2, 3]]);
        expect($service->isEnabledFor(server(2)))->toBeTrue();
    });

    it('returns false when the server egg is not in the list', function () {
        $service = propertiesService(['egg_ids' => [1, 2]]);
        expect($service->isEnabledFor(server(99)))->toBeFalse();
    });

    it('returns false when no eggs are configured', function () {
        expect(propertiesService()->isEnabledFor(server(1)))->toBeFalse();
    });
});

describe('parse', function () {
    /** @return array<int, array<string, mixed>> */
    $parse = function (string $raw) {
        return propertiesService([], [$raw], [], [])->parse($raw);
    };

    it('splits a simple key=value file into entry nodes', function () use ($parse) {
        $nodes = $parse("key=value\n");

        expect($nodes)->toHaveCount(1);
        expect($nodes[0]['type'])->toBe('entry');
        expect($nodes[0]['key'])->toBe('key');
        expect($nodes[0]['value'])->toBe('value');
        expect($nodes[0]['source'])->toHaveCount(1);
    });

    it('preserves comments as raw nodes alongside entries', function () use ($parse) {
        $nodes = $parse("# header\nkey=value\n");

        expect($nodes[0]['type'])->toBe('raw');
        expect($nodes[1]['type'])->toBe('entry');
    });

    it('preserves !-style comments as raw nodes', function () use ($parse) {
        $nodes = $parse("! another style\nkey=value\n");

        expect($nodes[0]['type'])->toBe('raw');
    });

    it('preserves keys with dots and dashes', function () use ($parse) {
        $nodes = $parse("query.port=25565\nrcon.password=secret\n");

        expect($nodes[0]['key'])->toBe('query.port');
        expect($nodes[1]['key'])->toBe('rcon.password');
    });

    it('accepts colon as separator', function () use ($parse) {
        $nodes = $parse("key:value\n");

        expect($nodes[0]['key'])->toBe('key');
        expect($nodes[0]['value'])->toBe('value');
    });

    it('treats a bare key with no separator as an entry with empty value', function () use ($parse) {
        $nodes = $parse("barekey\n");

        expect($nodes[0]['type'])->toBe('entry');
        expect($nodes[0]['key'])->toBe('barekey');
        expect($nodes[0]['value'])->toBe('');
    });
});

describe('render', function () {
    $parseAndRender = function (string $raw, array $changes) {
        $svc = propertiesService([], [$raw], [], []);
        $nodes = $svc->parse($raw);

        return $svc->render($nodes, $changes);
    };

    it('replaces only the changed key, preserves everything else', function () use ($parseAndRender) {
        $result = $parseAndRender("a=1\nb=2\nc=3\n", ['b' => '9']);

        expect($result)->toBe("a=1\nb=9\nc=3\n");
    });

    it('preserves comments around a changed entry', function () use ($parseAndRender) {
        $result = $parseAndRender("# header\nold=1\n", ['old' => '2']);

        expect($result)->toBe("# header\nold=2\n");
    });

    it('appends unknown keys at the end of the file', function () {
        $svc = propertiesService([], ['/server.properties' => "a=1\n"], [], []);
        $nodes = $svc->parse("a=1\n");
        $result = $svc->render($nodes, ['new-key' => '42']);

        expect($result)->toBe("a=1\nnew-key=42\n");
    });

    it('always terminates with exactly one newline', function () {
        $svc = propertiesService([], ['/server.properties' => ''], [], []);
        $result = $svc->render([], ['a' => '1']);

        expect(substr($result, -1))->toBe("\n");
    });
});

describe('normalize', function () {
    $svc = function (array $definitions = []) {
        return propertiesService([], [], $definitions, []);
    };

    it('casts a bool value to lowercase true/false', function () use ($svc) {
        expect($svc(['enabled' => ['type' => 'bool', 'locked' => false]])->normalize(['enabled' => true]))
            ->toBe(['enabled' => 'true']);
    });

    it('validates int range against min/max', function () use ($svc) {
        $defs = ['count' => ['type' => 'int', 'min' => 0, 'max' => 100, 'locked' => false]];

        expect(fn () => $svc($defs)->normalize(['count' => -1]))
            ->toThrow(DisplayException::class);
        expect(fn () => $svc($defs)->normalize(['count' => 101]))
            ->toThrow(DisplayException::class);
        expect($svc($defs)->normalize(['count' => '50']))->toBe(['count' => '50']);
    });

    it('drops locked keys silently', function () use ($svc) {
        expect($svc(['server-port' => ['type' => 'int', 'locked' => true]])->normalize(['server-port' => '3000']))
            ->toBe([]);
    });

    it('rejects invalid key characters', function () use ($svc) {
        expect(fn () => $svc()->normalize(['bad key' => 'v']))
            ->toThrow(DisplayException::class);
    });

    it('rejects line breaks in values', function () use ($svc) {
        expect(fn () => $svc()->normalize(['motd' => "line1\nline2"]))
            ->toThrow(DisplayException::class);
    });

    it('rejects non-scalar values', function () use ($svc) {
        expect(fn () => $svc()->normalize(['data' => ['nested']]))
            ->toThrow(DisplayException::class);
    });

    it('rejects enum values not in the options list', function () use ($svc) {
        $defs = ['difficulty' => ['type' => 'enum', 'options' => ['easy', 'normal', 'hard'], 'locked' => false]];

        expect(fn () => $svc($defs)->normalize(['difficulty' => 'peaceful']))
            ->toThrow(DisplayException::class);
    });
});

describe('read', function () {
    it('returns exists:false on a 404 from the daemon', function () {
        $service = propertiesService([]);
        $result = $service->read(server(1));

        expect($result['exists'])->toBeFalse();
        expect($result['raw'])->toBe('');
        expect($result['values'])->toBe([]);
    });

    it('parses the file when it exists', function () {
        $files = ['/server.properties' => "a=1\nb=2\n"];
        $service = propertiesService([], $files);
        $result = $service->read(server(1));

        expect($result['exists'])->toBeTrue();
        expect($result['values'])->toBe(['a' => '1', 'b' => '2']);
    });
});

describe('apply', function () {
    it('normalizes input and writes only the changed keys', function () {
        $files = ['/server.properties' => "a=1\nb=2\n"];
        $service = propertiesService(['egg_ids' => [1]], $files);
        $result = $service->apply(server(1), ['a' => '99']);

        expect($result['raw'])->toBe("a=99\nb=2\n");
        expect($result['values']['a'])->toBe('99');
    });

    it('rejects invalid input via normalize', function () {
        $files = ['/server.properties' => "a=1\n"];
        $service = propertiesService(['egg_ids' => [1]], $files);

        expect(fn () => $service->apply(server(1), ['a' => ['bad']]))
            ->toThrow(DisplayException::class);
    });

    it('returns current state when no changes are provided', function () {
        $files = ['/server.properties' => "a=1\n"];
        $service = propertiesService(['egg_ids' => [1]], $files);
        $result = $service->apply(server(1), []);

        expect($result['raw'])->toBe("a=1\n");
    });
});

describe('updateRaw', function () {
    it('writes the full file content', function () {
        $files = ['/server.properties' => ''];
        $service = propertiesService(['egg_ids' => [1]], $files);

        $result = $service->updateRaw(server(1), "new=content\n");

        expect($result['raw'])->toBe("new=content\n");
        expect($result['values']['new'])->toBe('content');
    });

    it('rejects content over 512 KB', function () {
        $service = propertiesService(['egg_ids' => [1]]);

        expect(fn () => $service->updateRaw(server(1), str_repeat('x', 512 * 1024 + 1)))
            ->toThrow(DisplayException::class);
    });

    it('rejects null bytes', function () {
        $service = propertiesService(['egg_ids' => [1]]);

        expect(fn () => $service->updateRaw(server(1), "bad\0content\n"))
            ->toThrow(DisplayException::class);
    });

    it('strips locked properties so a raw overwrite cannot enable RCON', function () {
        $definitions = [
            'enable-rcon' => ['type' => 'bool', 'locked' => true],
            'rcon.password' => ['type' => 'string', 'locked' => true],
            'motd' => ['type' => 'string'],
        ];
        $service = propertiesService(['egg_ids' => [1]], [], $definitions);

        $result = $service->updateRaw(
            server(1),
            "enable-rcon=true\nrcon.password=hunter2\nmotd=hello\n"
        );

        expect($result['raw'])->toBe("motd=hello\n");
        expect($result['values'])->not->toHaveKey('enable-rcon')
            ->not->toHaveKey('rcon.password');
    });

    it('preserves unknown keys through a raw overwrite', function () {
        $service = propertiesService(['egg_ids' => [1]]);

        $result = $service->updateRaw(server(1), "custom-key=value\n");

        expect($result['raw'])->toBe("custom-key=value\n");
        expect($result['values']['custom-key'])->toBe('value');
    });
});

describe('eulaAccepted', function () {
    it('returns false when the file does not exist', function () {
        $service = propertiesService([]);

        expect($service->eulaAccepted(server(1)))->toBeFalse();
    });

    it('returns true when eula=true is present', function () {
        $files = ['/eula.txt' => "eula=true\n"];
        $service = propertiesService([], $files);

        expect($service->eulaAccepted(server(1)))->toBeTrue();
    });

    it('returns false when eula=false is present', function () {
        $files = ['/eula.txt' => "eula=false\n"];
        $service = propertiesService([], $files);

        expect($service->eulaAccepted(server(1)))->toBeFalse();
    });

    it('is case-insensitive on the value', function () {
        $files = ['/eula.txt' => "eula=True\n"];
        $service = propertiesService([], $files);

        expect($service->eulaAccepted(server(1)))->toBeTrue();
    });
});

describe('acceptEula', function () {
    it('writes eula=true, then reports it as accepted', function () {
        $files = [];
        $service = propertiesService([], $files);
        $service->acceptEula(server(1));

        expect($service->eulaAccepted(server(1)))->toBeTrue();
    });
});
