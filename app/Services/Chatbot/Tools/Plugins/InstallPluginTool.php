<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Services\Chatbot\ToolContext;

class InstallPluginTool extends PluginTool
{
    public function name(): string
    {
        return 'install_plugin';
    }

    public function description(): string
    {
        return 'Download and install a plugin onto this server by its project id, which you get from search_plugins. The newest compatible version is chosen unless you pass a version_id — call list_plugin_versions first when the user needs a particular build, such as one matching an older game version. Installing runs third-party code on the server, so only install what the user actually asked for, and tell them the server must be restarted afterwards.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'string',
                    'description' => 'The registry project id, from search_plugins.',
                ],
                'provider' => $this->providerParameter(),
                'version_id' => [
                    'type' => 'string',
                    'description' => 'Install this exact version instead of the newest compatible one.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'The plugin\'s display name, so it is listed readably afterwards.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'The plugin\'s slug from search_plugins. Pass it so the plugin is listed under a readable name.',
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
            'provider' => 'sometimes|nullable|string|max:64',
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
        $name = $arguments['title'] ?? $arguments['project_id'] ?? 'a plugin';
        $provider = $arguments['provider'] ?? 'modrinth';

        return "Install the plugin \"$name\" from $provider and place it in this server's plugins folder";
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $server = $context->server;
        $provider = $this->providerName($arguments);

        // The panel refuses the same plugin from two registries at once; the
        // jars would both load and conflict at runtime. This matches on slug,
        // falling back the way PluginController::store() does.
        $duplicate = $this->manager->crossProviderDuplicate(
            $server,
            $provider,
            $arguments['slug'] ?? $arguments['title'] ?? $arguments['project_id'],
        );

        if ($duplicate) {
            throw new ChatbotException(
                "\"$duplicate->title\" is already installed from $duplicate->provider. Remove that copy first if you want it from $provider instead."
            );
        }

        $plugin = $this->manager->install(
            $server,
            $provider,
            $arguments['project_id'],
            $arguments['title'] ?? null,
            null,
            $arguments['version_id'] ?? null,
            $arguments['slug'] ?? null,
        );

        return $this->describe($plugin) + [
            'message' => 'The plugin was installed. Restart the server for it to load.',
        ];
    }
}
