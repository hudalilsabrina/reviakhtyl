<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Services\Chatbot\ToolContext;

class InstallModTool extends ModTool
{
    public function name(): string
    {
        return 'install_mod';
    }

    public function description(): string
    {
        return 'Download a mod from a registry into the server\'s /mods folder and track it for future updates. Takes the project_id from search_mods; do not guess one. The newest build matching the server\'s mod loader and game version is chosen automatically unless you pass a version_id — call list_mod_versions first when the user needs a particular build, such as one matching an older game version. Installing fails outright if no compatible build exists. Installing again over an existing entry replaces it with a newer version. The server must be restarted before the mod loads, and a mod that needs dependencies will not work until those are installed too.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'string',
                    'description' => 'The registry id of the mod, taken from a search_mods result.',
                ],
                'provider' => $this->providerParameter(),
                'version_id' => [
                    'type' => 'string',
                    'description' => 'Install this specific version instead of the newest compatible one. Leave out unless the user asked for a particular version.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'The mod\'s display name from the search result. Used for the confirmation prompt and the installed mod list, so always send it when you have it.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'The mod\'s slug from search_mods. Pass it so the mod is listed under a readable name instead of its project id.',
                ],
            ],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|string|max:128',
            'provider' => 'sometimes|nullable|string|max:32',
            'version_id' => 'sometimes|nullable|string|max:128',
            'title' => 'sometimes|nullable|string|max:191',
            'slug' => 'sometimes|nullable|string|max:191',
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $name = $this->label($arguments);
        $provider = is_string($arguments['provider'] ?? null) && $arguments['provider'] !== ''
            ? $arguments['provider']
            : self::DEFAULT_PROVIDER;

        $summary = sprintf('Install "%s" (%s) onto this server', $name, $provider);

        if (is_string($arguments['version_id'] ?? null) && $arguments['version_id'] !== '') {
            $summary .= ', version '.$arguments['version_id'];
        }

        return $summary;
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $provider = $this->providerName($arguments);
        $projectId = $arguments['project_id'];
        $title = $arguments['title'] ?? null;

        // Two copies of the same mod from different registries load twice and
        // usually crash the server, so refuse rather than let the model work it
        // out from a failed start afterwards.
        // Matches on slug, with the same fallback chain ModController::store() uses.
        $duplicate = $this->manager->crossProviderDuplicate(
            $server,
            $provider,
            $arguments['slug'] ?? $title ?? $projectId,
        );

        if ($duplicate) {
            throw new ChatbotException(sprintf(
                '"%s" is already installed on this server from %s. Remove it with remove_mod before installing the %s copy, or leave the existing one in place.',
                $duplicate->title,
                $duplicate->provider,
                $provider,
            ));
        }

        $mod = $this->manager->install(
            $server,
            $provider,
            $projectId,
            $title,
            null,
            $arguments['version_id'] ?? null,
            $arguments['slug'] ?? null,
        );

        return $this->present($mod) + [
            'message' => sprintf(
                '%s %s was installed into /mods. The server must be restarted before the mod is loaded.',
                $mod->title,
                $mod->version_number,
            ),
        ];
    }

    private function label(array $arguments): string
    {
        foreach (['title', 'project_id'] as $key) {
            $value = $arguments[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'a mod';
    }
}
