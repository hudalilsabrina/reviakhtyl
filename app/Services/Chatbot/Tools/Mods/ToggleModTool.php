<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Services\Chatbot\ToolContext;

class ToggleModTool extends ModTool
{
    public function name(): string
    {
        return 'toggle_mod';
    }

    public function description(): string
    {
        return 'Switch an installed mod between enabled and disabled by renaming its jar in /mods, which is how you stop a mod loading without deleting it — useful for testing whether one mod is causing a crash. Identify the mod by the slug from list_mods, which also reports whether each mod is currently disabled. This flips whatever state the mod is in, so check list_mods first if you need to be sure which way it will go. The server must be restarted before the change takes effect.';
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
        // Which way this goes depends on the mod's state on disk, and summarize()
        // is handed nothing but the arguments, so the direction genuinely cannot
        // be named here.
        return sprintf(
            'Enable or disable the mod "%s" on this server, whichever is the opposite of its current state',
            $arguments['slug'] ?? 'unknown',
        );
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $mod = $this->manager->toggle($server, $this->resolveMod($server, $arguments['slug']));
        $disabled = $this->isDisabled($mod);

        return $this->present($mod) + [
            'state' => $disabled ? 'disabled' : 'enabled',
            'message' => sprintf(
                '%s is now %s. The server must be restarted before the change takes effect.',
                $mod->title,
                $disabled ? 'disabled and will not be loaded by the server' : 'enabled and will be loaded by the server',
            ),
        ];
    }
}
