<?php

use App\Exceptions\DisplayException;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Http\Controllers\Api\Client\Servers\PropertiesController;
use App\Http\Requests\Api\Client\Servers\Properties\AcceptEulaRequest;
use App\Http\Requests\Api\Client\Servers\Properties\GetPropertiesRequest;
use App\Http\Requests\Api\Client\Servers\Properties\UpdatePropertiesRequest;
use App\Http\Requests\Api\Client\Servers\Properties\UpdateRawPropertiesRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use App\Repositories\Eloquent\SettingsRepository;
use App\Services\Activity\ActivityLogService;
use App\Services\Properties\ServerPropertiesService;
use GuzzleHttp\Psr7\Response;

afterEach(function () {
    Mockery::close();
});

/**
 * Builds a partial ServerPropertiesService backed by an in-memory file store.
 */
function service(
    array $files = [],
    array $definitions = [],
    array $groups = ['general', 'gameplay', 'world', 'players', 'performance', 'network', 'security', 'other']
): ServerPropertiesService {
    $fileRepo = Mockery::mock(DaemonFileRepository::class);
    $fileRepo->shouldReceive('setServer')->andReturnSelf();
    $fileRepo->shouldReceive('getContent')->andReturnUsing(function (string $path) use (&$files) {
        if (! isset($files[$path])) {
            throw new class extends DaemonConnectionException
            {
                public function __construct() {}

                public function getStatusCode(): int
                {
                    return 404;
                }
            };
        }

        return $files[$path];
    });
    $fileRepo->shouldReceive('putContent')->andReturnUsing(function (string $path, string $content) use (&$files) {
        $files[$path] = $content;

        return new Response(200);
    });

    $settingsRepo = Mockery::mock(SettingsRepository::class);
    $settingsRepo->shouldReceive('get')->andReturn(null);

    return new class($fileRepo, $settingsRepo, $definitions, $groups) extends ServerPropertiesService
    {
        private $mockFileRepo;

        public function __construct(
            $fileRepo,
            $settingsRepo,
            private array $definitions = [],
            private array $groups = [],
        ) {
            parent::__construct($fileRepo, $settingsRepo);
            $this->mockFileRepo = $fileRepo;
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
            return [];
        }

        public function isEnabledFor(Server $server): bool
        {
            return true;
        }

        // The parent's applyNormalized is private; re-implement using public
        // methods so the controller can call it through the same interface.
        public function applyNormalized(Server $server, array $normalized): array
        {
            $current = $this->read($server);

            if ($normalized === []) {
                return $current;
            }

            $content = $this->render($this->parse($current['raw']), $normalized);

            $this->mockFileRepo->setServer($server)->putContent(ServerPropertiesService::FILE, $content);

            return [
                'exists' => true,
                'raw' => $content,
                'values' => $this->read($server)['values'],
            ];
        }
    };
}

/**
 * A Server model stub that satisfies the controller and service without
 * touching the database.
 */
function fakeServer(int $eggId = 1): Server
{
    $s = Mockery::mock(Server::class)->makePartial();
    $s->egg_id = $eggId;

    return $s;
}

beforeEach(function () {
    $mock = Mockery::mock(ActivityLogService::class);
    $mock->shouldReceive('event')->andReturnSelf();
    $mock->shouldReceive('property')->andReturnSelf();
    $mock->shouldReceive('log')->andReturn(Mockery::mock(ActivityLog::class));
    app()->instance(ActivityLogService::class, $mock);
});

describe('PropertiesController', function () {
    describe('GET /api/client/servers/{server}/properties', function () {
        it('returns exists:false when the file does not exist on the daemon', function () {
            $svc = service();
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $result = $ctrl->index(new GetPropertiesRequest(), $server);

            expect($result)->toBeArray();
            expect($result['exists'])->toBeFalse();
            expect($result['raw'])->toBe('');
            expect($result['values'])->toBe([]);
            expect($result['eula_accepted'])->toBeFalse();
        });

        it('returns parsed values, groups and the raw file when the file exists', function () {
            $files = ['/server.properties' => "motd=Hello\n\ndifficulty=normal\n"];
            $defs = [
                'motd' => ['type' => 'string', 'default' => '', 'group' => 'general', 'locked' => false, 'sensitive' => false, 'warn' => false],
                'difficulty' => ['type' => 'enum', 'default' => 'peaceful', 'options' => ['peaceful', 'easy', 'normal', 'hard'], 'group' => 'gameplay', 'locked' => false, 'sensitive' => false, 'warn' => false],
            ];
            $svc = service($files, $defs, ['general', 'gameplay']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $result = $ctrl->index(new GetPropertiesRequest(), $server);

            expect($result['exists'])->toBeTrue();
            expect($result['values']['motd'])->toBe('Hello');
            expect($result['values']['difficulty'])->toBe('normal');
            expect($result['raw'])->toContain('motd=Hello');
            expect($result['groups'])->toHaveCount(2);
            expect($result['groups'][0]['id'])->toBe('general');
            expect($result['groups'][0]['properties'])->toHaveCount(1);
            expect($result['groups'][1]['id'])->toBe('gameplay');
            expect($result['groups'][1]['properties'])->toHaveCount(1);
        });

        it('adds unknown keys from the file into the "other" group', function () {
            $files = ['/server.properties' => "unknown-key=42\n"];
            $svc = service($files, [], ['other']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $result = $ctrl->index(new GetPropertiesRequest(), $server);

            $other = $result['groups'][0];
            expect($other['id'])->toBe('other');
            expect($other['properties'][0]['key'])->toBe('unknown-key');
            expect($other['properties'][0]['locked'])->toBeFalse();
        });

        it('marks locked and sensitive definitions in the response', function () {
            $files = ['/server.properties' => "server-port=25565\nrcon.password=secret\n"];
            $defs = [
                'server-port' => ['type' => 'int', 'default' => '25565', 'group' => 'network', 'locked' => true, 'sensitive' => false, 'warn' => false],
                'rcon.password' => ['type' => 'string', 'default' => '', 'group' => 'network', 'locked' => false, 'sensitive' => true, 'warn' => false],
            ];
            $svc = service($files, $defs, ['network']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $result = $ctrl->index(new GetPropertiesRequest(), $server);

            $props = $result['groups'][0]['properties'];
            expect($props[0]['locked'])->toBeTrue();
            expect($props[0]['sensitive'])->toBeFalse();
            expect($props[1]['sensitive'])->toBeTrue();
            expect($props[1]['locked'])->toBeFalse();
        });

        it('reports eula_accepted=true when eula.txt contains eula=true', function () {
            $files = [
                '/server.properties' => "motd=Hi\n",
                '/eula.txt' => "eula=true\n",
            ];
            $svc = service($files, [], []);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $result = $ctrl->index(new GetPropertiesRequest(), $server);

            expect($result['eula_accepted'])->toBeTrue();
        });
    });

    describe('PUT /api/client/servers/{server}/properties', function () {
        it('applies normalized changes and returns updated state', function () {
            $files = ['/server.properties' => "motd=Hello\n\ndifficulty=peaceful\n"];
            $defs = [
                'motd' => ['type' => 'string', 'default' => '', 'group' => 'general', 'locked' => false, 'sensitive' => false, 'warn' => false],
                'difficulty' => ['type' => 'enum', 'default' => 'peaceful', 'options' => ['peaceful', 'easy', 'normal', 'hard'], 'group' => 'gameplay', 'locked' => false, 'sensitive' => false, 'warn' => false],
            ];
            $svc = service($files, $defs, ['general', 'gameplay']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdatePropertiesRequest();
            $request->merge(['properties' => ['motd' => 'Goodbye']]);

            $result = $ctrl->update($request, $server);

            expect($result['values']['motd'])->toBe('Goodbye');
            expect($result['values']['difficulty'])->toBe('peaceful');
            expect($result['raw'])->toContain('motd=Goodbye');
            expect($result['raw'])->toContain('difficulty=peaceful');
        });

        it('drops locked keys silently and leaves them unchanged on disk', function () {
            $files = ['/server.properties' => "server-port=25565\nmotd=Hello\n"];
            $defs = [
                'server-port' => ['type' => 'int', 'default' => '25565', 'group' => 'network', 'locked' => true, 'sensitive' => false, 'warn' => false],
                'motd' => ['type' => 'string', 'default' => '', 'group' => 'general', 'locked' => false, 'sensitive' => false, 'warn' => false],
            ];
            $svc = service($files, $defs, ['network', 'general']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdatePropertiesRequest();
            $request->merge(['properties' => ['server-port' => '30000', 'motd' => 'Bye']]);

            $result = $ctrl->update($request, $server);

            expect($result['raw'])->toContain('server-port=25565');
            expect($result['raw'])->toContain('motd=Bye');
        });

        it('rejects an out-of-range int value', function () {
            $defs = [
                'count' => ['type' => 'int', 'default' => '0', 'min' => 0, 'max' => 100, 'group' => 'gameplay', 'locked' => false, 'sensitive' => false, 'warn' => false],
            ];
            $svc = service([], $defs, ['gameplay']);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdatePropertiesRequest();
            $request->merge(['properties' => ['count' => '-1']]);

            expect(fn () => $ctrl->update($request, $server))
                ->toThrow(DisplayException::class);
        });
    });

    describe('PUT /api/client/servers/{server}/properties/raw', function () {
        it('replaces the entire file content', function () {
            $files = ['/server.properties' => "old=1\n"];
            $svc = service($files, [], []);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdateRawPropertiesRequest();
            $request->merge(['content' => "new=2\n"]);

            $result = $ctrl->updateRaw($request, $server);

            expect($result['raw'])->toBe("new=2\n");
            expect($result['values']['new'])->toBe('2');
            expect(array_keys($result['values']))->not->toContain('old');
        });

        it('rejects content over 512 KB', function () {
            $svc = service([], [], []);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdateRawPropertiesRequest();
            $request->merge(['content' => str_repeat('x', 512 * 1024 + 1)]);

            expect(fn () => $ctrl->updateRaw($request, $server))
                ->toThrow(DisplayException::class);
        });

        it('rejects null bytes', function () {
            $svc = service([], [], []);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $request = new UpdateRawPropertiesRequest();
            $request->merge(['content' => "bad\0content\n"]);

            expect(fn () => $ctrl->updateRaw($request, $server))
                ->toThrow(DisplayException::class);
        });
    });

    describe('POST /api/client/servers/{server}/properties/eula', function () {
        it('writes eula=true and reports it as accepted', function () {
            $files = [];
            $svc = service($files, [], []);
            $ctrl = new PropertiesController($svc);
            $server = fakeServer();

            $ctrl->acceptEula(new AcceptEulaRequest(), $server);

            expect($svc->eulaAccepted($server))->toBeTrue();
        });
    });
});
