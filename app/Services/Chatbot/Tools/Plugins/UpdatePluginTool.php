<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Exceptions\DisplayException;
use App\Services\Chatbot\ToolContext;

class UpdatePluginTool extends PluginTool
{
    public function name(): string
    {
        return 'update_plugin';
    }

    public function description(): string
    {
        return 'Update an installed plugin to the newest version compatible with this server. Identify it by the name shown in list_plugins. If it is already current, nothing is downloaded. A restart is needed for the new version to load.';
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
        return 'Update the plugin "'.($arguments['name'] ?? '?').'" to its newest compatible version';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $plugin = $this->findPlugin($context, $arguments['name']);
        $before = $plugin->version_number;

        try {
            $updated = $this->manager->update($context->server, $plugin);
        } catch (DisplayException $e) {
            // The service signals "nothing to do" by throwing. Reported as a
            // failure the model apologises and retries, so it is turned back
            // into the ordinary outcome it actually is. Other failures — a
            // download error, say — still propagate.
            if (! str_contains($e->getMessage(), 'up to date')) {
                throw $e;
            }

            return $this->describe($plugin) + [
                'changed' => false,
                'message' => "\"$plugin->title\" is already on the newest compatible version ($before).",
            ];
        }

        if ($updated->version_number === $before) {
            return $this->describe($updated) + [
                'changed' => false,
                'message' => "\"$updated->title\" was already on the newest compatible version ($before).",
            ];
        }

        return $this->describe($updated) + [
            'changed' => true,
            'previous_version' => $before,
            'message' => "Updated from $before to $updated->version_number. Restart the server for it to take effect.",
        ];
    }
}
