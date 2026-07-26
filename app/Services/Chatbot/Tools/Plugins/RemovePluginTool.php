<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Services\Chatbot\ToolContext;

class RemovePluginTool extends PluginTool
{
    public function name(): string
    {
        return 'remove_plugin';
    }

    public function description(): string
    {
        return 'Uninstall a plugin from this server, deleting its jar. Any configuration the plugin wrote is left in place. Identify it by the name shown in list_plugins. Prefer toggle_plugin if the user only wants to disable it temporarily.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The plugin\'s name or slug, as shown by list_plugins.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:191'];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Uninstall the plugin "'.($arguments['name'] ?? '?').'" and delete its jar from this server';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $plugin = $this->findPlugin($context, $arguments['name']);
        $removed = $this->describe($plugin);

        $this->manager->delete($context->server, $plugin);

        return $removed + [
            'message' => "\"{$removed['title']}\" was uninstalled. Restart the server to unload it. Its configuration files were left alone.",
        ];
    }
}
