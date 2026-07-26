<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\ServerPlugin;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Plugins\PluginManagerService;

/**
 * Shared plumbing for the plugin tools: the availability gate, provider
 * resolution and looking an installed plugin up by the name the user used.
 */
abstract class PluginTool extends ChatbotTool
{
    public function __construct(protected PluginManagerService $manager) {}

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Plugins;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_PLUGIN_MANAGE];
    }

    /**
     * Mirrors PluginController::assertEnabled(). This is what enforces the
     * egg allowlist — without it the assistant could install plugins on
     * servers where an administrator switched the feature off.
     *
     * @throws ChatbotException
     */
    protected function assertEnabled(ToolContext $context): void
    {
        if (! $this->manager->isEnabledFor($context->server)) {
            throw new ChatbotException('Plugins are not available for this server.');
        }
    }

    /**
     * @throws ChatbotException
     */
    protected function providerName(array $arguments): string
    {
        $name = $arguments['provider'] ?? 'modrinth';

        try {
            $this->manager->provider($name);
        } catch (\Throwable) {
            throw new ChatbotException(
                "\"$name\" is not a plugin source on this panel. Available sources: "
                .implode(', ', $this->manager->providerNames()).'.'
            );
        }

        return $name;
    }

    /**
     * Resolves the name the model used against what is actually installed.
     *
     * Models refer to plugins the way a person would, so slug and title are
     * both accepted; an ambiguous name is reported rather than guessed at,
     * because the alternative is updating or deleting the wrong plugin.
     *
     * @throws ChatbotException
     */
    protected function findPlugin(ToolContext $context, string $name): ServerPlugin
    {
        $plugins = $context->server->plugins;

        $matches = $plugins->filter(
            fn (ServerPlugin $plugin) => strcasecmp($plugin->slug, $name) === 0
                || strcasecmp((string) $plugin->title, $name) === 0
        );

        if ($matches->isEmpty()) {
            throw new ChatbotException(
                "No plugin called \"$name\" is installed on this server. Use list_plugins to see what is."
            );
        }

        if ($matches->count() > 1) {
            throw new ChatbotException(
                "\"$name\" matches more than one installed plugin ("
                .$matches->map(fn (ServerPlugin $p) => $p->slug.' from '.$p->provider)->implode(', ')
                .'). Say which one you mean.'
            );
        }

        return $matches->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(ServerPlugin $plugin): array
    {
        return [
            'slug' => $plugin->slug,
            'title' => $plugin->title,
            'provider' => $plugin->provider,
            'project_id' => $plugin->project_id,
            'version_number' => $plugin->version_number,
            'file_name' => $plugin->file_name,
            'disabled' => (bool) $plugin->disabled,
        ];
    }

    /**
     * The provider argument shared by every tool in this group.
     */
    protected function providerParameter(): array
    {
        return [
            'type' => 'string',
            'description' => 'Which registry to use. Defaults to modrinth. Available: '
                .implode(', ', $this->manager->providerNames()).'.',
        ];
    }
}
