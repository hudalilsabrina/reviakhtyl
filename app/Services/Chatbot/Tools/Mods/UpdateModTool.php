<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Exceptions\DisplayException;
use App\Services\Chatbot\ToolContext;

class UpdateModTool extends ModTool
{
    public function name(): string
    {
        return 'update_mod';
    }

    public function description(): string
    {
        return 'Update one installed mod to the newest build that still matches the server\'s mod loader and game version. Identify the mod by the slug from list_mods. The old jar is replaced in /mods, so the previous version is gone afterwards, and the server must be restarted before the new version is loaded. Mods that are already current are reported as such rather than reinstalled. This never changes the game version or the loader.';
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
            'Update the mod "%s" to the newest version compatible with this server, replacing the installed jar',
            $arguments['slug'] ?? 'unknown',
        );
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $mod = $this->resolveMod($server, $arguments['slug']);
        $previous = (string) $mod->version_number;

        try {
            $mod = $this->manager->update($server, $mod);
        } catch (DisplayException $e) {
            // The service signals "nothing newer exists" by throwing. That is a
            // perfectly good outcome for this tool, not a failure, and reporting
            // it as an error tends to make the model retry or apologise.
            if (str_contains(strtolower($e->getMessage()), 'up to date')) {
                return $this->present($mod) + [
                    'updated' => false,
                    'previous_version' => $previous,
                    'message' => sprintf('%s is already up to date on version %s. Nothing was changed.', $mod->title, $previous),
                ];
            }

            throw $e;
        }

        $updated = (string) $mod->version_number !== $previous;

        return $this->present($mod) + [
            'updated' => $updated,
            'previous_version' => $previous,
            'message' => $updated
                ? sprintf(
                    '%s was updated from version %s to version %s. The server must be restarted before the new version is loaded.',
                    $mod->title,
                    $previous,
                    $mod->version_number,
                )
                : sprintf('%s was already up to date on version %s. Nothing was changed.', $mod->title, $previous),
        ];
    }
}
