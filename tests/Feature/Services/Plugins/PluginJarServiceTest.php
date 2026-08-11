<?php

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Plugins\PluginJarService;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function makePluginJarService(): array
{
    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $service = new PluginJarService($fileRepository);

    return [$service, $fileRepository];
}

function zipFromEntries(array $entries): string
{
    // Build a real zip in memory so entriesSafe() runs against real archive bytes.
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

it('returns the fallback slug/title for jars too large to parse', function () {
    [$service] = makePluginJarService();

    $meta = $service->metadata(Mockery::mock(Server::class), 'my-cool-plugin.jar', 65 * 1024 * 1024);

    expect($meta['slug'])->toBe('my-cool-plugin')
        ->and($meta['title'])->toBe('my-cool-plugin')
        ->and($meta['version'])->toBe('unknown');
});

it('parses plugin.yml metadata from a well-formed jar', function () {
    [$service, $fileRepository] = makePluginJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, zipFromEntries([
                'plugin.yml' => "name: MyPlugin\nversion: 1.2.3\nmain: com.example.Main\n",
                'META-INF/MANIFEST.MF' => "Manifest-Version: 1.0\n",
            ]));
        });

    $meta = $service->metadata($server, 'myplugin.jar', 1000);

    expect($meta['slug'])->toBe('myplugin')
        ->and($meta['title'])->toBe('MyPlugin')
        ->and($meta['version'])->toBe('1.2.3');
});

it('rejects a jar with zip-slip entries and falls back to the file name', function () {
    [$service, $fileRepository] = makePluginJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, zipFromEntries([
                '../evil.sh' => '#!/bin/sh\nrm -rf /\n',
                'plugin.yml' => "name: Innocent\nversion: 1.0.0\n",
            ]));
        });

    $meta = $service->metadata($server, 'evil.jar', 1000);

    // entriesSafe() bailed out, so only the fallback derived from the file name.
    expect($meta['slug'])->toBe('evil')
        ->and($meta['title'])->toBe('evil')
        ->and($meta['version'])->toBe('unknown');
});

it('rejects a jar with an absolute-path entry', function () {
    [$service, $fileRepository] = makePluginJarService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $fileRepository->shouldReceive('setServer')->andReturnSelf();
    $fileRepository->shouldReceive('streamContentToFile')
        ->andReturnUsing(function ($path, $dest) {
            file_put_contents($dest, zipFromEntries([
                '/etc/cron.d/pwned' => 'evil',
                'plugin.yml' => "name: Innocent\nversion: 1.0.0\n",
            ]));
        });

    $meta = $service->metadata($server, 'absolute.jar', 1000);

    expect($meta['slug'])->toBe('absolute')
        ->and($meta['version'])->toBe('unknown');
});
