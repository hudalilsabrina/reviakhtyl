<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Services\Chatbot\ToolContext;
use App\Services\Mods\ModManagerService;
use App\Services\Mods\ModpackManagerService;

class InstallModpackTool extends ModTool
{
    public function __construct(
        ModManagerService $manager,
        private ModpackManagerService $modpackManager,
    ) {
        parent::__construct($manager);
    }

    public function name(): string
    {
        return 'install_modpack';
    }

    public function description(): string
    {
        return 'Install an entire modpack from a Modrinth (.mrpack) or CurseForge modpack URL. Downloads and parses the manifest, then installs every compatible mod listed inside. Some mods may fail if they aren\'t compatible with the server\'s game version or loader — check the result for failures. The server must be restarted afterwards.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to the modpack .mrpack or CurseForge zip file.',
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'url' => 'required|string|url|max:2048',
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return sprintf('Install modpack from "%s" onto this server', $arguments['url']);
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $results = $this->modpackManager->installFromUrl($server, $arguments['url']);

        $successCount = count($results['success']);
        $failedCount = count($results['failed']);

        $message = sprintf(
            'Modpack "%s" (%s): %d mods installed, %d failed.',
            $results['name'],
            $results['format'],
            $successCount,
            $failedCount,
        );

        if ($failedCount > 0) {
            $failures = array_map(
                fn (array $f) => ($f['project_id'] ?? 'unknown').': '.$f['error'],
                $results['failed'],
            );
            $message .= ' Failures: '.implode('; ', $failures);
        }

        $message .= ' The server must be restarted for the mods to load.';

        return [
            'format' => $results['format'],
            'name' => $results['name'],
            'installed' => $successCount,
            'failed' => $failedCount,
            'message' => $message,
        ];
    }
}
