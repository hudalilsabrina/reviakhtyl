<?php

namespace App\Services\Datapacks;

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

class DatapackZipService
{
    private const MAX_SIZE = 64 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $fileRepository) {}

    /**
     * ZIP files in /datapacks that are not tracked in server_datapacks.
     * Returns: [['file_name' => ..., 'size' => int], ...]
     */
    public function untracked(Server $server): array
    {
        $tracked = $server->datapacks->pluck('file_name')
            ->merge($server->datapacks->map(fn ($d) => $d->file_name.'.disabled'))
            ->filter()
            ->all();

        $cacheKey = sprintf('server:%d:datapacks-dir', $server->id);
        $files = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($server) {
            try {
                return $this->fileRepository->setServer($server)->getDirectory('/datapacks');
            } catch (\Exception) {
                return [];
            }
        });

        return collect($files)
            ->filter(function ($f) {
                $name = strtolower($f['name'] ?? '');

                return ($f['file'] ?? true) && (str_ends_with($name, '.zip') || str_ends_with($name, '.zip.disabled'));
            })
            ->reject(fn ($f) => in_array($f['name'], $tracked, true))
            ->map(fn ($f) => ['file_name' => $f['name'], 'size' => (int) ($f['size'] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * Check whether the ZIP contains a parseable pack.mcmeta.
     */
    public function hasPackMcmeta(Server $server, string $fileName, int $size): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'datapack');
        try {
            $this->fileRepository->setServer($server)->streamContentToFile(
                '/datapacks/'.$fileName,
                $tmp,
                self::MAX_SIZE
            );

            $zip = new \ZipArchive();
            if (! $zip->open($tmp)) {
                return false;
            }

            $raw = $zip->getFromName('pack.mcmeta');
            $zip->close();

            if ($raw === false) {
                return false;
            }

            $data = json_decode($raw, true);

            return is_array($data) && isset($data['pack']);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Extract pack metadata from pack.mcmeta inside a datapack ZIP.
     * Returns: ['slug' => string, 'title' => string, 'pack_format' => int|null, 'description' => string]
     */
    public function parsePackMcmeta(Server $server, string $fileName, int $size): array
    {
        $fallbackSlug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($fileName, PATHINFO_FILENAME)));

        if ($size > self::MAX_SIZE) {
            return [
                'slug' => $fallbackSlug,
                'title' => pathinfo($fileName, PATHINFO_FILENAME),
                'pack_format' => null,
                'description' => '',
            ];
        }

        $cacheKey = sprintf('server:%d:datapack-zip-meta:%s:%d', $server->id, md5($fileName), $size);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $meta = $this->extractPackMcmetaStreaming($server, $fileName);

            if (! $meta) {
                $result = [
                    'slug' => $fallbackSlug,
                    'title' => pathinfo($fileName, PATHINFO_FILENAME),
                    'pack_format' => null,
                    'description' => '',
                ];
                Cache::put($cacheKey, $result, now()->addHour());

                return $result;
            }

            $result = [
                'slug' => strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($meta['slug'] ?? $fallbackSlug))),
                'title' => (string) ($meta['title'] ?? $meta['slug'] ?? pathinfo($fileName, PATHINFO_FILENAME)),
                'pack_format' => $meta['pack_format'] ?? null,
                'description' => (string) ($meta['description'] ?? ''),
            ];
            Cache::put($cacheKey, $result, now()->addHour());

            return $result;
        } catch (\Exception) {
            return [
                'slug' => $fallbackSlug,
                'title' => pathinfo($fileName, PATHINFO_FILENAME),
                'pack_format' => null,
                'description' => '',
            ];
        }
    }

    /**
     * Stream ZIP to temp file and parse pack.mcmeta.
     */
    private function extractPackMcmetaStreaming(Server $server, string $fileName): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'datapack');
        try {
            $this->fileRepository->setServer($server)->streamContentToFile(
                '/datapacks/'.$fileName,
                $tmp,
                self::MAX_SIZE
            );

            $zip = new \ZipArchive();
            if (! $zip->open($tmp)) {
                return null;
            }

            $raw = $zip->getFromName('pack.mcmeta');
            $zip->close();

            if ($raw === false) {
                return null;
            }

            $data = json_decode($raw, true);
            if (! is_array($data) || ! isset($data['pack'])) {
                return null;
            }

            $pack = $data['pack'];

            // Derive slug and title from description if pack.mcmeta doesn't have explicit fields.
            $description = is_string($pack['description'] ?? null) ? $pack['description'] : '';
            $title = $description !== '' ? $description : pathinfo($fileName, PATHINFO_FILENAME);

            return [
                'slug' => strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title)),
                'title' => $title,
                'pack_format' => $pack['pack_format'] ?? null,
                'description' => $description,
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
