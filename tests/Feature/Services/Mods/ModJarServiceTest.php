<?php

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Mods\ModJarService;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function makeModJarService(): array
{
    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $service = new ModJarService($fileRepository);

    return [$service, $fileRepository];
}

function modZipFromEntries(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ziptest_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $contents = file_get_contents($tmp);
    @unlink($tmp);

    return $contents;
}

beforeEach(function () {
    Cache::flush();
});

it('parses fabric.mod.json metadata from a well-formed jar', function () {
    [$service, $fileRepository] = makeModJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, modZipFromEntries([
                'fabric.mod.json' => json_encode([
                    'id' => 'sodium',
                    'name' => 'Sodium',
                    'version' => '0.5.8',
                ]),
            ]));
        });

    $meta = $service->metadata($server, 'sodium.jar', 1000);

    expect($meta['slug'])->toBe('sodium')
        ->and($meta['title'])->toBe('Sodium')
        ->and($meta['version'])->toBe('0.5.8');
});

it('rejects a mod jar with zip-slip entries and falls back to the file name', function () {
    [$service, $fileRepository] = makeModJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, modZipFromEntries([
                '../payload.so' => 'evil',
                'fabric.mod.json' => json_encode(['id' => 'innocent', 'name' => 'Innocent', 'version' => '1.0.0']),
            ]));
        });

    $meta = $service->metadata($server, 'evil-mod.jar', 1000);

    expect($meta['slug'])->toBe('evil-mod')
        ->and($meta['title'])->toBe('evil-mod')
        ->and($meta['version'])->toBe('unknown');
});

it('parses META-INF/mods.toml descriptors', function () {
    [$service, $fileRepository] = makeModJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, modZipFromEntries([
                'META-INF/mods.toml' => "modLoader=\"javafml\"\n[[mods]]\nmodId=\"examplemod\"\ndisplayName=\"Example Mod\"\nversion=\"2.0.0\"\n",
            ]));
        });

    $meta = $service->metadata($server, 'examplemod.jar', 1000);

    expect($meta['slug'])->toBe('examplemod')
        ->and($meta['title'])->toBe('Example Mod')
        ->and($meta['version'])->toBe('2.0.0');
});
