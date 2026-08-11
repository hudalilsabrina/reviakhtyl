<?php

use App\Models\Egg;
use App\Models\EggStartupPart;
use App\Models\Server;
use App\Services\Servers\EnvironmentService;
use Illuminate\Support\Collection;

function makeEnvironmentServer(array $parts = [], array $choices = []): Server
{
    $egg = Mockery::mock(Egg::class);
    $egg->shouldReceive('getAttribute')->with('startupParts')->andReturn(collect($parts));

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->andReturnUsing(function (string $key) use ($egg, $choices): mixed {
        return match ($key) {
            'egg' => $egg,
            'variables' => new Collection(),
            'startup' => 'java -Xmx{{SERVER_MEMORY}}M -jar server.jar',
            'uuid' => 'uuid-1',
            'allocation_limit' => 0,
            'location' => null,
            'startup_parts' => $choices,
            default => null,
        };
    });
    $server->shouldReceive('startupPartChoices')->andReturn(collect($choices));
    $server->shouldReceive('offsetExists')->andReturn(false);

    return $server;
}

function part(int $id, string $value, bool $defaultEnabled = true): EggStartupPart
{
    $part = Mockery::mock(EggStartupPart::class);
    $part->shouldReceive('getAttribute')->with('id')->andReturn($id);
    $part->shouldReceive('getAttribute')->with('value')->andReturn($value);
    $part->shouldReceive('getAttribute')->with('default_enabled')->andReturn($defaultEnabled);
    $part->shouldReceive('getAttribute')->with('required')->andReturn(false);

    return $part;
}

beforeEach(function () {
    $this->service = new EnvironmentService();
});

it('builds STARTUP_PARTS from enabled egg parts', function () {
    $server = makeEnvironmentServer(
        parts: [part(1, '--nogui'), part(2, '--max-players 20'), part(3, '--op-permission-level=4')],
        choices: [1 => true, 2 => false, 3 => true]
    );

    $env = $this->service->handle($server);

    expect($env['STARTUP_PARTS'])->toBe('--nogui --op-permission-level=4');
});

it('falls back to default_enabled when the user has not chosen', function () {
    $server = makeEnvironmentServer(
        parts: [part(1, '--nogui', defaultEnabled: true), part(2, '--offline', defaultEnabled: false)],
        choices: []
    );

    $env = $this->service->handle($server);

    expect($env['STARTUP_PARTS'])->toBe('--nogui');
});

it('returns empty STARTUP_PARTS when the egg has no parts', function () {
    $server = makeEnvironmentServer(parts: []);

    $env = $this->service->handle($server);

    expect($env['STARTUP_PARTS'])->toBe('');
});

it('skips parts with empty or whitespace-only values', function () {
    $server = makeEnvironmentServer(
        parts: [part(1, '   '), part(2, '--enabled')],
        choices: [1 => true, 2 => true]
    );

    $env = $this->service->handle($server);

    expect($env['STARTUP_PARTS'])->toBe('--enabled');
});
