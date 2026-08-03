<?php

namespace App\Services\Security;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class FileScanService
{
    private const ALLOWED_BINARIES = [
        '/usr/bin/clamscan',
        '/usr/local/bin/clamscan',
        '/bin/clamscan',
        '/usr/sbin/clamscan',
        '/usr/local/sbin/clamscan',
    ];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ?ProcessRunner $processRunner = null,
        private ?string $binary = null,
        private ?int $maxSize = null,
        private bool $strict = false,
        private bool $enabled = true,
    ) {
        // Validate at construction: binary is configured once, not per-scan.
        // Skip validation when a custom ProcessRunner is injected (e.g., tests
        // with a fake runner) because there is no real clamscan to resolve.
        if ($this->binary !== null && $this->processRunner === null) {
            $this->binary = $this->resolveBinary($this->binary);
        }
    }

    public function scan(string $filePath): FileScanResult
    {
        if (! $this->isEnabled()) {
            return new FileScanResult(ScanVerdict::Skipped, null, 'Scanning disabled');
        }

        if (! is_file($filePath) || ! is_readable($filePath)) {
            return new FileScanResult(ScanVerdict::Skipped, null, 'File not accessible');
        }

        $maxSize = $this->maxSize ?? 256 * 1024 * 1024;
        if (filesize($filePath) > $maxSize) {
            return new FileScanResult(ScanVerdict::Skipped, null, 'File exceeds max scan size');
        }

        $binary = $this->binary ?? 'clamscan';

        try {
            $output = $this->getProcessRunner()->run([$binary, '--no-summary', '--stdout', '--', $filePath]);
        } catch (\Throwable $e) {
            $this->logError('Scanner error', $e->getMessage());

            return new FileScanResult(ScanVerdict::Error, null, $e->getMessage());
        }

        if (preg_match('/(?:^|:\s*)OK\s*$/m', $output)) {
            return new FileScanResult(ScanVerdict::Clean);
        }

        if (preg_match('/FOUND\s+(\S+)/', $output, $m)) {
            $signature = $m[1];
            $this->logInfected($filePath, $signature);

            return new FileScanResult(ScanVerdict::Infected, $signature, $output);
        }

        $this->logError('Unexpected scanner output', $output);

        return new FileScanResult(ScanVerdict::Error, null, "Unexpected scanner output: {$output}");
    }

    public function scanContent(string $content, string $filename = 'uploaded'): FileScanResult
    {
        if (strlen($content) > ($this->maxSize ?? 256 * 1024 * 1024)) {
            return new FileScanResult(ScanVerdict::Skipped, null, 'Content exceeds max scan size');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        try {
            file_put_contents($tmp, $content);

            return $this->scan($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function isEnabled(): bool
    {
        return $this->enabled && (bool) $this->settings->get('settings::panel:files:scan_enabled', false);
    }

    private function getProcessRunner(): ProcessRunner
    {
        return $this->processRunner ?? new SymfonyProcessRunner();
    }

    /**
     * Resolve the clamscan binary, validating against an allowlist.
     *
     * @throws \InvalidArgumentException if the binary is not an allowed path
     */
    private function resolveBinary(): string
    {
        $candidate = $this->binary ?? 'clamscan';

        // Plain name without path — resolve via PATH and validate
        if (! str_contains($candidate, '/')) {
            $which = new Process(['which', $candidate]);
            $which->run();

            if ($which->isSuccessful()) {
                $resolved = (string) trim($which->getOutput());
                if ($resolved !== '' && in_array($resolved, self::ALLOWED_BINARIES, true)) {
                    return $resolved;
                }
            }

            throw new \InvalidArgumentException("clamscan binary '{$candidate}' not found in an allowed path.");
        }

        // Absolute or relative path — validate against allowlist
        if (! in_array($candidate, self::ALLOWED_BINARIES, true)) {
            throw new \InvalidArgumentException("clamscan binary path '{$candidate}' is not an allowed location.");
        }

        return $candidate;
    }

    /**
     * Scan a remote file by streaming it to a temp file, scanning, and cleaning up.
     */
    public function scanRemoteFile(DaemonFileRepository $repository, Server $server, string $remotePath): FileScanResult
    {
        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        try {
            $repository->setServer($server)->streamContentToFile($remotePath, $tmp, $this->maxSize ?? 256 * 1024 * 1024);
        } catch (\Throwable $e) {
            @unlink($tmp);

            return new FileScanResult(ScanVerdict::Error, null, "Failed to fetch remote file: {$e->getMessage()}");
        }

        try {
            return $this->scan($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function logInfected(string $path, string $signature): void
    {
        try {
            Activity::warning("File infected: {$signature}")
                ->withProperty('file', $path)
                ->withProperty('signature', $signature)
                ->log();
            Log::warning('File scan: infected', ['file' => $path, 'signature' => $signature]);
        } catch (\Throwable) {
            // Facades unavailable outside a booted application (e.g. unit tests)
        }
    }

    private function logError(string $context, string $detail): void
    {
        try {
            Log::warning("File scan: {$context}", ['detail' => $detail]);
        } catch (\Throwable) {
            // Facades unavailable outside a booted application (e.g. unit tests)
        }
    }
}
