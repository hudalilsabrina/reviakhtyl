<?php

namespace App\Services\Chatbot;

use App\Services\Chatbot\Tools\ChatbotTool;

/**
 * The registry for panel-scope (admin) tools.
 *
 * Unlike the server registry, tools are never hidden by the per-server tool
 * group toggles — every admin tool is gated by root admin status alone, which
 * is what AdminTool::isAvailableFor() enforces.
 */
class AdminToolRegistry extends ToolRegistry
{
    /**
     * @return array<int, string>
     */
    protected function toolClasses(): array
    {
        return config('chatbot.admin_tools', []);
    }

    protected function isEnabledFor(ChatbotTool $tool): bool
    {
        return true;
    }
}
