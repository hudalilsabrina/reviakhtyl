<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Services\Chatbot\ToolContext;

class RemoveModTool extends ModTool
{
    public function name(): string
    {
        return 'remove_mod';
    }

    public function description(): string
    {
        return 'Permanently delete an installed mod: its jar is removed from /mods and it stops being tracked. Identify the mod by the slug from list_mods. This cannot be undone, though the mod can be installed again from the registry. Other mods that depend on this one will break, and worlds or items created by it may be lost when the server next starts. The server must be restarted for the removal to take effect. To stop a mod loading without deleting it, use toggle_mod instead.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The slug of the installed mod, as reported by list_mods. Its title also works.',
                ],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'slug' => 'required|string|max:191',
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return sprintf(
            'Permanently delete the mod "%s" from this server, removing its jar from /mods',
            $arguments['slug'] ?? 'unknown',
        );
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $mod = $this->resolveMod($server, $arguments['slug']);
        $removed = $this->present($mod);

        $this->manager->delete($server, $mod);

        return [
            'removed' => $removed,
            'message' => sprintf(
                '%s was deleted from /mods and is no longer tracked. The server must be restarted for the removal to take effect.',
                $removed['title'],
            ),
        ];
    }
}
