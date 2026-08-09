<?php

namespace App\Services\Mods;

use App\Exceptions\DisplayException;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class ModpackManagerService
{
    public function __construct(
        private ModManagerService $modManager,
    ) {}

    /**
     * Install a modpack from a URL.
     *
     * Returns ['success' => [...], 'failed' => [...], 'format' => 'modrinth'|'curseforge', 'name' => string].
     */
    public function installFromUrl(Server $server, string $url): array
    {
        $manifest = $this->downloadAndParse($url);

        return $this->installMods($server, $manifest);
    }

    /**
     * Download a URL and parse it as a modpack manifest.
     *
     * @return array{format: string, name: string, mods: array}
     */
    private function downloadAndParse(string $url): array
    {
        return $this->parseManifest($url);
    }

    /**
     * Public variant of downloadAndParse: downloads and parses a modpack
     * manifest without installing anything. Used for previews.
     *
     * @return array{format: string, name: string, mods: array}
     */
    public function parseManifest(string $url): array
    {
        $response = Http::timeout(60)->get($url);

        if ($response->failed()) {
            throw new DisplayException('Failed to download modpack from URL.');
        }

        $body = $response->body();
        $tempFile = tempnam(sys_get_temp_dir(), 'modpack_');

        try {
            file_put_contents($tempFile, $body);

            $zip = new ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new DisplayException('The downloaded file is not a valid zip archive.');
            }

            $manifest = null;
            $format = null;

            if (($content = $zip->getFromName('modrinth.index.json')) !== false) {
                $manifest = json_decode($content, true);
                if (! is_array($manifest)) {
                    throw new DisplayException('Invalid modrinth.index.json in modpack.');
                }
                $format = 'modrinth';
            } elseif (($content = $zip->getFromName('manifest.json')) !== false) {
                $manifest = json_decode($content, true);
                if (! is_array($manifest)) {
                    throw new DisplayException('Invalid manifest.json in modpack.');
                }

                $type = $manifest['manifestType'] ?? '';
                if ($type !== 'minecraftModpack') {
                    throw new DisplayException('Unsupported manifest type: "'.$type.'". Only Minecraft modpacks are supported.');
                }

                $format = 'curseforge';
            } else {
                throw new DisplayException('No modpack manifest found in the archive. Expected modrinth.index.json or manifest.json at the zip root.');
            }

            $zip->close();

            $parsedMods = $format === 'modrinth'
                ? $this->parseModrinthManifest($manifest)
                : $this->parseCurseForgeManifest($manifest);

            $name = $manifest['name'] ?? basename(parse_url($url, PHP_URL_PATH));

            return [
                'format' => $format,
                'name' => $name,
                'mods' => $parsedMods,
            ];
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Parse a Modrinth modpack manifest (modrinth.index.json).
     */
    private function parseModrinthManifest(array $manifest): array
    {
        $files = $manifest['files'] ?? [];

        return collect($files)
            ->filter(fn (array $file) => $this->isServerMod($file))
            ->map(function (array $file): array {
                $downloadUrl = $file['downloads'][0] ?? '';
                $info = $this->extractModrinthProjectInfo($downloadUrl);

                return [
                    'project_id' => $info['project_id'] ?? null,
                    'version_id' => $info['version_id'] ?? null,
                    'file_name' => $info['file_name'] ?? basename($file['path'] ?? ''),
                    'download_url' => $downloadUrl,
                    'provider' => 'modrinth',
                    'path' => $file['path'] ?? '',
                ];
            })
            ->filter(fn (array $mod) => $mod['project_id'] && $mod['version_id'])
            ->values()
            ->all();
    }

    /**
     * Parse a CurseForge modpack manifest (manifest.json).
     */
    private function parseCurseForgeManifest(array $manifest): array
    {
        $files = $manifest['files'] ?? [];

        return collect($files)
            ->map(fn (array $file): array => [
                'project_id' => (string) ($file['projectID'] ?? ''),
                'version_id' => (string) ($file['fileID'] ?? ''),
                'provider' => 'curseforge',
            ])
            ->filter(fn (array $mod) => $mod['project_id'] && $mod['version_id'])
            ->values()
            ->all();
    }

    /**
     * Check whether a Modrinth mod file targets the server environment.
     */
    private function isServerMod(array $file): bool
    {
        $env = $file['env'] ?? [];

        if (empty($env)) {
            return true;
        }

        $server = $env['server'] ?? 'optional';

        return $server === 'required' || $server === 'optional';
    }

    /**
     * Extract project_id and version_id from a Modrinth CDN download URL.
     *
     * URL format: https://cdn.modrinth.com/data/{project_id}/versions/{version_id}/{filename}
     */
    private function extractModrinthProjectInfo(string $url): ?array
    {
        if (preg_match('#/data/([^/]+)/versions/([^/]+)/([^/]+)$#', $url, $matches)) {
            return [
                'project_id' => $matches[1],
                'version_id' => $matches[2],
                'file_name' => $matches[3],
            ];
        }

        return null;
    }

    /**
     * Install each mod from the parsed manifest, returning success/failure results.
     */
    private function installMods(Server $server, array $manifest): array
    {
        $results = [
            'format' => $manifest['format'],
            'name' => $manifest['name'],
            'success' => [],
            'failed' => [],
        ];

        foreach ($manifest['mods'] as $mod) {
            try {
                $provider = $mod['provider'];

                if ($provider === 'curseforge') {
                    $providerService = $this->modManager->provider('curseforge');
                    $version = collect($providerService->versions(
                        $mod['project_id'],
                        $this->modManager->loaders($server),
                        $this->modManager->gameVersion($server),
                        100
                    ))->firstWhere('id', $mod['version_id']);

                    if (! $version) {
                        throw new DisplayException('Version '.$mod['version_id'].' not found for project '.$mod['project_id']);
                    }

                    $installed = $this->modManager->install(
                        $server,
                        'curseforge',
                        $mod['project_id'],
                        null,
                        null,
                        $mod['version_id'],
                    );

                    $results['success'][] = [
                        'project_id' => $mod['project_id'],
                        'title' => $installed->title,
                        'version' => $installed->version_number,
                        'provider' => 'curseforge',
                    ];
                } else {
                    $installed = $this->modManager->install(
                        $server,
                        'modrinth',
                        $mod['project_id'],
                        null,
                        null,
                        $mod['version_id'],
                    );

                    $results['success'][] = [
                        'project_id' => $mod['project_id'],
                        'title' => $installed->title,
                        'version' => $installed->version_number,
                        'provider' => 'modrinth',
                    ];
                }
            } catch (DisplayException $e) {
                $results['failed'][] = [
                    'project_id' => $mod['project_id'] ?? null,
                    'provider' => $mod['provider'] ?? null,
                    'error' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'project_id' => $mod['project_id'] ?? null,
                    'provider' => $mod['provider'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
