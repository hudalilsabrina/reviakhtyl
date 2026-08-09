<?php

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Services\Security\FileScanService;
use App\Services\Security\ProcessRunner;
use App\Services\Security\ScanVerdict;

/**
 * Build a FileScanService with a fake process runner whose output is returned
 * verbatim as clamscan stdout.
 *
 * @param  array<string, mixed>  $config  panel.file_scan config values + runner output
 */
function scanService(array $config = []): FileScanService
{
    $enabled = (bool) ($config['enabled'] ?? true);
    $binary = $config['binary'] ?? null;
    $maxSize = $config['max_scan_size'] ?? null;
    $strict = $config['strict'] ?? false;

    $runner = new class($config) implements ProcessRunner
    {
        /** @param array<string, mixed> $config */
        public function __construct(private array $config = []) {}

        public function run(array $command): string
        {
            return (string) ($this->config['output'] ?? '');
        }
    };

    $settings = Mockery::mock(SettingsRepositoryInterface::class);
    $settings->shouldReceive('get')->andReturn($enabled);

    return new FileScanService($settings, $runner, $binary, $maxSize, $strict, $enabled);
}

// ---------------------------------------------------------------------------
// Gating
// ---------------------------------------------------------------------------

describe('scan — gating', function () {
    it('skips when disabled in config', function () {
        $service = scanService(['enabled' => false, 'output' => "OK\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'hello');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Skipped);
        expect($result->message)->toBe('Scanning disabled');
    });

    it('skips when the file does not exist', function () {
        $service = scanService(['output' => "OK\n"]);

        $result = $service->scan('/no/such/file.jar');
        expect($result->verdict)->toEqual(ScanVerdict::Skipped);
        expect($result->message)->toBe('File not accessible');
    });

    it('skips files that exceed max_scan_size', function () {
        $service = scanService(['max_scan_size' => 10, 'output' => "OK\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, str_repeat('x', 100));
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Skipped);
        expect($result->message)->toBe('File exceeds max scan size');
    });
});

// ---------------------------------------------------------------------------
// Verdict parsing
// ---------------------------------------------------------------------------

describe('scan — verdict parsing', function () {
    it('returns Clean on OK output', function () {
        $service = scanService(['output' => "/tmp/test.jar: OK\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'hello');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Clean);
        expect($result->isClean())->toBeTrue();
    });

    it('returns Infected with the signature when FOUND is detected', function () {
        $service = scanService(['output' => "/tmp/test.jar: Eicar-Test-Signature FOUND\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'Eicar');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Infected);
        expect($result->signature)->toBe('Eicar-Test-Signature');
        expect($result->isInfected())->toBeTrue();
    });

    it('returns Infected when the signature appears after FOUND', function () {
        $service = scanService(['output' => "/tmp/test.jar: FOUND Eicar-Test-Signature\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'Eicar');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Infected);
        expect($result->signature)->toBe('Eicar-Test-Signature');
    });

    it('returns Error on unrecognised output', function () {
        $service = scanService(['output' => "something weird\n"]);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'hello');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Error);
        expect($result->isError())->toBeTrue();
    });

    it('returns Error when the runner throws', function () {
        $runner = new class implements ProcessRunner
        {
            public function run(array $command): string
            {
                throw new RuntimeException('binary missing');
            }
        };

        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn(true);

        $service = new FileScanService($settings, $runner);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'hello');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Error);
    });
});

// ---------------------------------------------------------------------------
// strict mode
// ---------------------------------------------------------------------------

describe('scan — strict mode', function () {
    it('returns Error when strict=false and the runner throws', function () {
        $runner = new class implements ProcessRunner
        {
            public function run(array $command): string
            {
                throw new RuntimeException('clamscan not found');
            }
        };

        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn(true);

        $service = new FileScanService($settings, $runner);

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        file_put_contents($tmp, 'hello');
        $result = $service->scan($tmp);
        @unlink($tmp);

        expect($result->verdict)->toEqual(ScanVerdict::Error);
    });
});

// ---------------------------------------------------------------------------
// scanContent()
// ---------------------------------------------------------------------------

describe('scanContent', function () {
    it('writes content to a temp file, scans it, and cleans up', function () {
        $service = scanService(['output' => "OK\n"]);

        $result = $service->scanContent('jar-content', 'test.jar');
        expect($result->verdict)->toEqual(ScanVerdict::Clean);

        $temps = glob(sys_get_temp_dir().'/avscan_*');
        expect($temps)->toBe([]);
    });

    it('propagates infection verdict', function () {
        $service = scanService(['output' => "/tmp/test: FOUND Malware.X\n"]);

        $result = $service->scanContent('malicious', 'evil.jar');
        expect($result->verdict)->toEqual(ScanVerdict::Infected);
        expect($result->signature)->toBe('Malware.X');
    });
});
