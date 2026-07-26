<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Server;
use App\Models\ServerMod;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Mods\ModManagerService;

/**
 * Shared plumbing for the mod tools.
 *
 * Every mod tool needs the same three things before it can do any work: the
 * feature has to be switched on for the server's egg, a provider name has to be
 * resolved, and the model's free-text mod name has to be turned into a row that
 * actually belongs to this server. Keeping those here means the egg allowlist
 * check cannot be forgotten in one tool and enforced in the others.
 */
abstract class ModTool extends ChatbotTool
{
    protected const DEFAULT_PROVIDER = 'modrinth';

    public function __construct(protected ModManagerService $manager) {}

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Mods;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_MOD_MANAGE];
    }

    /**
     * Mirrors ModController::assertEnabled(). An administrator restricts the
     * mod installer to specific eggs, and that allowlist is the only thing
     * stopping the assistant from dropping jars into servers that cannot run
     * them, so this runs before anything else in every tool.
     */
    protected function assertEnabled(Server $server): void
    {
        if (! $this->manager->isEnabledFor($server)) {
            throw new ChatbotException('Mods are not available for this server.');
        }
    }

    /**
     * The provider to talk to, defaulting to Modrinth when the model does not
     * name one. The service throws a generic "Unknown mod provider" for a bad
     * name; the model can only recover from that if it is told what the valid
     * names are, so it is rewritten here.
     */
    protected function providerName(array $arguments): string
    {
        $name = $arguments['provider'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? strtolower(trim($name)) : self::DEFAULT_PROVIDER;

        try {
            $this->manager->provider($name);
        } catch (DisplayException) {
            throw new ChatbotException(sprintf(
                'Unknown mod provider "%s". Valid providers are: %s.',
                $name,
                implode(', ', $this->manager->providerNames()),
            ));
        }

        return $name;
    }

    /**
     * Resolves the identifier the model passes back from list_mods to one of
     * this server's installed mods. Slug is tried before title because that is
     * what list_mods presents as the handle, but models routinely send the
     * display name instead.
     */
    protected function resolveMod(Server $server, string $needle): ServerMod
    {
        $normalized = mb_strtolower(trim($needle));
        $mods = $server->mods;

        if ($mods->isEmpty()) {
            throw new ChatbotException('This server has no mods installed, so there is nothing to act on. Use list_mods to confirm.');
        }

        $matches = $mods->filter(fn (ServerMod $mod) => mb_strtolower((string) $mod->slug) === $normalized);

        if ($matches->isEmpty()) {
            $matches = $mods->filter(fn (ServerMod $mod) => mb_strtolower((string) $mod->title) === $normalized);
        }

        if ($matches->isEmpty()) {
            throw new ChatbotException("No installed mod matches \"$needle\". Use list_mods to see exactly which mods are installed on this server and use the slug it reports.");
        }

        if ($matches->count() > 1) {
            $candidates = $matches
                ->map(fn (ServerMod $mod) => sprintf('%s (%s, version %s)', $mod->title, $mod->provider, $mod->version_number))
                ->implode('; ');

            throw new ChatbotException("\"$needle\" matches more than one installed mod: $candidates. These cannot be told apart by name, so ask the user which one they mean.");
        }

        return $matches->first();
    }

    /**
     * The fields worth showing the model. Deliberately narrower than the API
     * transformer: icon URLs and internal ids are noise in a chat context.
     *
     * ServerMod has no "disabled" attribute of its own; like
     * ServerModTransformer, the state lives in the file name on disk.
     */
    protected function present(ServerMod $mod): array
    {
        return [
            'slug' => $mod->slug,
            'title' => $mod->title,
            'provider' => $mod->provider,
            'project_id' => $mod->project_id,
            'version_number' => $mod->version_number,
            'file_name' => $mod->file_name,
            'disabled' => $this->isDisabled($mod),
        ];
    }

    protected function isDisabled(ServerMod $mod): bool
    {
        return $mod->disabled;
    }

    /**
     * The optional provider argument, identical in every tool that takes one.
     */
    protected function providerParameter(): array
    {
        return [
            'type' => 'string',
            'description' => sprintf(
                'Registry to use. One of: %s. Defaults to %s.',
                implode(', ', $this->manager->providerNames()),
                self::DEFAULT_PROVIDER,
            ),
        ];
    }
}
