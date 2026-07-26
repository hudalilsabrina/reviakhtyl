<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Services\Chatbot\ToolContext;

class TogglePluginTool extends PluginTool
{
    public function name(): string
    {
        return 'toggle_plugin';
    }

    public function description(): string
    {
        return 'Enable or disable an installed plugin without uninstalling it, by renaming its jar so the server does or does not load it. Useful for narrowing down which plugin is causing a problem. The change takes effect on the next restart.';
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
        return 'Enable or disable the plugin "'.($arguments['name'] ?? '?').'" on this server';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $plugin = $this->findPlugin($context, $arguments['name']);
        $toggled = $this->manager->toggle($context->server, $plugin);
        $disabled = (bool) $toggled->disabled;

        // Spelled out rather than left as a bare flag: models invert booleans.
        return $this->describe($toggled) + [
            'state' => $disabled ? 'disabled' : 'enabled',
            'message' => "\"$toggled->title\" is now ".($disabled ? 'disabled' : 'enabled')
                .'. Restart the server for the change to take effect.',
        ];
    }
}
