<?php

namespace App\Services\Plugins;

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;

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

        try {
            $files = $this->fileRepository->setServer($server)->getDirectory('/plugins');
        } catch (\Exception) {
            return [];
        }

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

        try {
            $contents = $this->fileRepository->setServer($server)->getContent('/plugins/'.$fileName, self::MAX_SIZE);
            $zip = new \ZipArchive();
            $tmp = tempnam(sys_get_temp_dir(), 'jar');
            file_put_contents($tmp, $contents);
            if (! $zip->open($tmp)) {
                @unlink($tmp);

                return $fallback;
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
            @unlink($tmp);

            if (! $meta || empty($meta['slug'])) {
                return $fallback;
            }

            return [
                'slug' => strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $meta['slug'])),
                'title' => (string) ($meta['title'] ?? $meta['slug']),
                'version' => (string) ($meta['version'] ?? 'unknown'),
            ];
        } catch (\Exception) {
            return $fallback;
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
