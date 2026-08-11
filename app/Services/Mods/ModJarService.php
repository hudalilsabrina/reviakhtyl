<?php

namespace App\Services\Mods;

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

class ModJarService
{
    /** Maximum jar size to download for metadata parsing (64 MB). */
    private const MAX_SIZE = 64 * 1024 * 1024;

    /** Largest single descriptor entry, as a decompressed-size ceiling. */
    private const MAX_ENTRY_SIZE = 16 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $fileRepository) {}

    /**
     * Jars present in /mods that are not tracked in server_mods.
     * Returns: [['file_name' => ..., 'size' => int], ...]
     */
    public function untracked(Server $server): array
    {
        $tracked = $server->mods->pluck('file_name')
            ->merge($server->mods->map(fn ($m) => $m->file_name.'.disabled'))
            ->filter()
            ->all();

        $cacheKey = sprintf('server:%d:mods-dir', $server->id);
        $files = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($server) {
            try {
                return $this->fileRepository->setServer($server)->getDirectory('/mods');
            } catch (\Exception) {
                return [];
            }
        });

        return collect($files)
            ->filter(function ($f) {
                $name = strtolower($f['name'] ?? '');

                return ($f['file'] ?? true) && (str_ends_with($name, '.jar') || str_ends_with($name, '.jar.disabled'));
            })
            ->reject(fn ($f) => in_array($f['name'], $tracked, true))
            ->map(fn ($f) => ['file_name' => $f['name'], 'size' => (int) ($f['size'] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * Extract mod metadata from fabric.mod.json, META-INF/mods.toml, or quilt.mod.json.
     * Returns: ['slug' => string, 'title' => string, 'version' => string]
     */
    public function metadata(Server $server, string $fileName, int $size): array
    {
        $fallback = [
            'slug' => strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($fileName, PATHINFO_FILENAME))),
            'title' => pathinfo($fileName, PATHINFO_FILENAME),
            'version' => 'unknown',
        ];

        if ($size > self::MAX_SIZE) {
            return $fallback;
        }

        $cacheKey = sprintf('server:%d:mod-jar-meta:%s:%d', $server->id, md5($fileName), $size);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $meta = $this->extractMetadataStreaming($server, $fileName);

            if (! $meta || empty($meta['slug'])) {
                Cache::put($cacheKey, $fallback, now()->addHour());

                return $fallback;
            }

            $result = [
                'slug' => strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $meta['slug'])),
                'title' => (string) ($meta['title'] ?? $meta['slug']),
                'version' => (string) ($meta['version'] ?? 'unknown'),
            ];
            Cache::put($cacheKey, $result, now()->addHour());

            return $result;
        } catch (\Exception) {
            return $fallback;
        }
    }

    /**
     * Stream jar to temp file and parse mod descriptors.
     * ponytail: Wings files/contents has no Range API — still transfers full jar over wire.
     */
    private function extractMetadataStreaming(Server $server, string $fileName): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'modjar');
        try {
            $this->fileRepository->setServer($server)->streamContentToFile(
                '/mods/'.$fileName,
                $tmp,
                self::MAX_SIZE
            );

            $zip = new \ZipArchive();
            if (! $zip->open($tmp)) {
                return null;
            }

            // Never trust entry names from a third-party jar: reject archives
            // with path traversal, absolute paths or oversized descriptors
            // before reading anything out of them.
            if (! $this->entriesSafe($zip)) {
                $zip->close();

                return null;
            }

            $meta = null;

            if (($raw = $zip->getFromName('fabric.mod.json')) !== false) {
                $json = json_decode($raw, true);
                if (is_array($json) && ! empty($json['id'])) {
                    $meta = [
                        'slug' => $json['id'],
                        'title' => $json['name'] ?? $json['id'],
                        'version' => $json['version'] ?? 'unknown',
                    ];
                }
            }

            if (! $meta && ($raw = $zip->getFromName('quilt.mod.json')) !== false) {
                $json = json_decode($raw, true);
                $qid = $json['quilt_loader']['id'] ?? $json['id'] ?? null;
                if (is_array($json) && $qid) {
                    $meta = [
                        'slug' => $qid,
                        'title' => $json['quilt_loader']['metadata']['name'] ?? $json['name'] ?? $qid,
                        'version' => $json['quilt_loader']['version'] ?? $json['version'] ?? 'unknown',
                    ];
                }
            }

            if (! $meta && ($raw = $zip->getFromName('META-INF/mods.toml')) !== false) {
                $meta = $this->parseModsToml($raw);
            }

            $zip->close();

            return $meta;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Reject archives that could smuggle malicious content onto the server:
     * entries with `..` segments, absolute paths, symlinks, or entries larger
     * than a sane decompressed ceiling.
     */
    private function entriesSafe(\ZipArchive $zip): bool
    {
        $count = $zip->numFiles;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                return false;
            }

            $name = $stat['name'] ?? '';

            if ($name === '') {
                return false;
            }

            // Zip-slip: reject any path segment that climbs out or an absolute path.
            if (str_starts_with($name, '/') || str_contains($name, '\\')
                || in_array('..', explode('/', $name), true)) {
                return false;
            }

            if (($stat['size'] ?? 0) > self::MAX_ENTRY_SIZE) {
                return false;
            }

            // Symlink entries are never legitimate in a mod jar. The mode is
            // not exposed on every PHP build, so treat a missing value as a
            // regular file rather than failing closed on a field we cannot read.
            $mode = $stat['mode'] ?? 0;

            if (($mode & 0o170000) === 0o120000) {
                return false;
            }
        }

        return true;
    }

    /** Minimal mods.toml parse: first modId / displayName / version. */
    private function parseModsToml(string $raw): ?array
    {
        $modId = preg_match('/^\s*modId\s*=\s*["\']([^"\']+)["\']/m', $raw, $m) ? $m[1] : null;
        $name = preg_match('/^\s*displayName\s*=\s*["\']([^"\']+)["\']/m', $raw, $m) ? $m[1] : null;
        $version = preg_match('/^\s*version\s*=\s*["\']([^"\']+)["\']/m', $raw, $m) ? $m[1] : null;

        if (! $modId) {
            return null;
        }

        return [
            'slug' => $modId,
            'title' => $name ?: $modId,
            'version' => $version ?: 'unknown',
        ];
    }
}
