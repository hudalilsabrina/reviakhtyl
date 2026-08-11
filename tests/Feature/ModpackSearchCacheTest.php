<?php

use App\Repositories\Agent\DaemonFileRepository;
use App\Repositories\Eloquent\SettingsRepository;
use App\Services\Mods\CurseForgeService;
use App\Services\Mods\ModManagerService;
use App\Services\Mods\ModrinthService;
use App\Services\Security\FileScanService;

afterEach(function () {
    Mockery::close();
});

/**
 * Builds a real ModManagerService with mocked providers that count searchModpacks
 * calls, so the tests can assert the cache collapses duplicate searches.
 */
function modManagerWithCountedProviders(): array
{
    $modrinth = Mockery::mock(ModrinthService::class);
    $modrinth->shouldReceive('searchModpacks')->andReturn(['hits' => [['id' => 'abc']], 'total' => 1]);

    $curseforge = Mockery::mock(CurseForgeService::class);
    $curseforge->shouldReceive('searchModpacks')->andReturn(['hits' => [['id' => 'abc']], 'total' => 1]);

    $manager = new ModManagerService(
        Mockery::mock(DaemonFileRepository::class),
        Mockery::mock(SettingsRepository::class),
        Mockery::mock(FileScanService::class),
        $modrinth,
        $curseforge,
    );

    return [$manager, $modrinth, $curseforge];
}

it('caches modpack search results for the same query', function () {
    [$manager, $modrinth] = modManagerWithCountedProviders();

    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack', '1.20', 20, 0, 'relevance');
    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack', '1.20', 20, 0, 'relevance');

    $modrinth->shouldHaveReceived('searchModpacks')->once();
});

it('uses a distinct cache entry per query', function () {
    [$manager, $modrinth] = modManagerWithCountedProviders();

    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack-a', '1.20', 20, 0, 'relevance');
    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack-b', '1.20', 20, 0, 'relevance');

    $modrinth->shouldHaveReceived('searchModpacks')->twice();
});

it('uses a distinct cache entry per provider', function () {
    [$manager, $modrinth, $curseforge] = modManagerWithCountedProviders();

    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack', '1.20', 20, 0, 'relevance');
    $manager->searchModpacks(CurseForgeService::PROVIDER, 'pack', '1.20', 20, 0, 'relevance');

    $modrinth->shouldHaveReceived('searchModpacks')->once();
    $curseforge->shouldHaveReceived('searchModpacks')->once();
});

it('does not share a cache entry across different page sizes', function () {
    [$manager, $modrinth] = modManagerWithCountedProviders();

    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack', '1.20', 20, 0, 'relevance');
    $manager->searchModpacks(ModrinthService::PROVIDER, 'pack', '1.20', 50, 0, 'relevance');

    $modrinth->shouldHaveReceived('searchModpacks')->twice();
});
