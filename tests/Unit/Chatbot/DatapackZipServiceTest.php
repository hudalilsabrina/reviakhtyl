<?php

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Datapacks\DatapackZipService;

/**
 * Build a DatapackZipService whose streamContentToFile writes a zip fixture
 * with a `../escape.txt` entry when $evil is true, a clean zip otherwise.
 */
function datapackZipServiceFixture(bool $evil = false): DatapackZipService
{
    $repository = Mockery::mock(DaemonFileRepository::class);
    $repository->shouldReceive('setServer')->andReturnSelf();
    $repository->shouldReceive('streamContentToFile')->andReturnUsing(
        function (string $path, string $destination) use ($evil) {
            $tmp = tempnam(sys_get_temp_dir(), 'dpzip_');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::OVERWRITE);
            $zip->addFromString('pack.mcmeta', json_encode(['pack' => ['pack_format' => 15]]));

            if ($evil) {
                $zip->addFromString('../escape.txt', 'oops');
            }

            $zip->close();
            copy($tmp, $destination);
            @unlink($tmp);
        }
    );

    return new DatapackZipService($repository);
}

describe('DatapackZipService entry validation', function () {
    it('accepts a clean datapack zip', function () {
        $service = datapackZipServiceFixture(false);

        expect($service->hasPackMcmeta(Mockery::mock(Server::class), 'good.zip'))->toBeTrue();
    });

    it('rejects a zip with a path traversal entry', function () {
        $service = datapackZipServiceFixture(true);

        expect($service->hasPackMcmeta(Mockery::mock(Server::class), 'evil.zip'))->toBeFalse();
    });
});
