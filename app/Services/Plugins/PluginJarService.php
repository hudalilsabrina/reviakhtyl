<?php

namespace App\Services\Plugins;

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

class PluginJarService
{
    /** Maximum jar size to download for metadata parsing (64 MB). */
    private const MAX_SIZE = 64 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $fileRepository) {}

    /**
     * Jars present in /plugins that are not tracked in server_plugins.
     * Returns: [['file_name' => ..., 'size' => int], ...]
     */
    public function untracked(Server $server): array
    {
        $tracked = $server->plugins->pluck('file_name')
            ->merge($server->plugins->map(fn ($p) => $p->file_name.'.disabled'))
            ->filter()
            ->all();

        // Cache directory listing for 30 seconds to reduce Wings calls
        $cacheKey = sprintf('server:%d:plugins-dir', $server->id);
        $files = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($server) {
            try {
                return $this->fileRepository->setServer($server)->getDirectory('/plugins');
            } catch (\Exception) {
                return [];
            }
        });

        return collect($files)
            ->filter(fn ($f) => ($f['file'] ?? true) && str_ends_with(strtolower($f['name'] ?? ''), '.jar'))
            ->reject(fn ($f) => in_array($f['name'], $tracked, true))
            ->map(fn ($f) => ['file_name' => $f['name'], 'size' => (int) ($f['size'] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * Extract plugin metadata from a jar's descriptor (plugin.yml, paper-plugin.yml,
     * velocity-plugin.json, bungee.yml). Falls back to the file name.
     *
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

        // Cache parsed metadata per jar; keyed by name+size so re-uploads bust it.
        $cacheKey = sprintf('server:%d:jar-meta:%s:%d', $server->id, md5($fileName), $size);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            // Stream only the descriptor files instead of loading entire jar
            $meta = $this->extractMetadataStreaming($server, $fileName, $size);

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
     * Extract metadata by reading only descriptor files from the jar.
     * Avoids loading entire jar into memory.
     */
    private function extractMetadataStreaming(Server $server, string $fileName, int $size): ?array
    {
        $contents = $this->fileRepository->setServer($server)->getContent('/plugins/'.$fileName, self::MAX_SIZE);

        // Write to temp file for ZipArchive (no in-memory option in PHP < 8.2)
        $tmp = tempnam(sys_get_temp_dir(), 'jar');
        try {
            file_put_contents($tmp, $contents);
            $zip = new \ZipArchive();

            if (! $zip->open($tmp)) {
                return null;
            }

            $meta = null;
            foreach (['plugin.yml', 'paper-plugin.yml', 'bungee.yml'] as $descriptor) {
                $raw = $zip->getFromName($descriptor);
                if ($raw !== false) {
                    $meta = $this->parseYamlDescriptor($raw);
                    break;
                }
            }

            if (! $meta && ($raw = $zip->getFromName('velocity-plugin.json')) !== false) {
                $json = json_decode($raw, true);
                if (is_array($json) && ! empty($json['id'])) {
                    $meta = ['slug' => $json['id'], 'title' => $json['name'] ?? $json['id'], 'version' => $json['version'] ?? 'unknown'];
                }
            }

            $zip->close();

            return $meta;
        } finally {
            @unlink($tmp);
        }
    }

    /** Minimal flat YAML parse: top-level name/version keys only. */
    private function parseYamlDescriptor(string $raw): ?array
    {
        if (function_exists('yaml_parse')) {
            $parsed = @\yaml_parse($raw);
            if (is_array($parsed) && ! empty($parsed['name'])) {
                return ['slug' => $parsed['name'], 'title' => $parsed['name'], 'version' => $parsed['version'] ?? 'unknown'];
            }
        }

        $name = preg_match('/^name:\s*["\']?([^"\'\r\n#]+?)["\']?\s*$/m', $raw, $m) ? trim($m[1]) : null;
        $version = preg_match('/^version:\s*["\']?([^"\'\r\n#]+?)["\']?\s*$/m', $raw, $m) ? trim($m[1]) : null;

        if (! $name) {
            return null;
        }

        return ['slug' => $name, 'title' => $name, 'version' => $version ?: 'unknown'];
    }
}
