<?php

use App\Services\Chatbot\Tools\Console\SendConsoleCommandTool;
use App\Services\Chatbot\Tools\Files\CompressFilesTool;
use App\Services\Chatbot\Tools\Files\CopyFileTool;
use App\Services\Chatbot\Tools\Files\CreateFolderTool;
use App\Services\Chatbot\Tools\Files\DecompressFileTool;
use App\Services\Chatbot\Tools\Files\DeleteFilesTool;
use App\Services\Chatbot\Tools\Files\ListFilesTool;
use App\Services\Chatbot\Tools\Files\ReadFileTool;
use App\Services\Chatbot\Tools\Files\RenameFilesTool;
use App\Services\Chatbot\Tools\Files\WriteFileTool;
use App\Services\Chatbot\Tools\Mods\InstallModTool;
use App\Services\Chatbot\Tools\Mods\ListModsTool;
use App\Services\Chatbot\Tools\Mods\ListModVersionsTool;
use App\Services\Chatbot\Tools\Mods\RemoveModTool;
use App\Services\Chatbot\Tools\Mods\SearchModsTool;
use App\Services\Chatbot\Tools\Mods\ToggleModTool;
use App\Services\Chatbot\Tools\Mods\UpdateModTool;
use App\Services\Chatbot\Tools\Plugins\InstallPluginTool;
use App\Services\Chatbot\Tools\Plugins\ListPluginsTool;
use App\Services\Chatbot\Tools\Plugins\ListPluginVersionsTool;
use App\Services\Chatbot\Tools\Plugins\RemovePluginTool;
use App\Services\Chatbot\Tools\Plugins\SearchPluginsTool;
use App\Services\Chatbot\Tools\Plugins\TogglePluginTool;
use App\Services\Chatbot\Tools\Plugins\UpdatePluginTool;
use App\Services\Chatbot\Tools\Power\PowerActionTool;
use App\Services\Chatbot\Tools\Server\GetActivityLogTool;
use App\Services\Chatbot\Tools\Server\GetResourceHistoryTool;
use App\Services\Chatbot\Tools\Server\GetServerDetailsTool;
use App\Services\Chatbot\Tools\Server\GetServerResourcesTool;
use App\Services\Chatbot\Tools\Startup\GetStartupTool;
use App\Services\Chatbot\Tools\Startup\UpdateStartupPartsTool;
use App\Services\Chatbot\Tools\Startup\UpdateStartupVariableTool;
use App\Services\Chatbot\Tools\Subusers\CreateSubuserTool;
use App\Services\Chatbot\Tools\Subusers\DeleteSubuserTool;
use App\Services\Chatbot\Tools\Subusers\ListPermissionsTool;
use App\Services\Chatbot\Tools\Subusers\ListSubusersTool;
use App\Services\Chatbot\Tools\Subusers\UpdateSubuserPermissionsTool;

/*
|--------------------------------------------------------------------------
| Chatbot Tool Registry
|--------------------------------------------------------------------------
|
| Every capability the AI assistant can use has to be listed here. On boot,
| App\Services\Chatbot\ToolRegistry resolves each class through the container
| (so tools may type-hint the same services and repositories the HTTP
| controllers use) and keys them by their name().
|
| Listing a tool does not expose it. Before a tool is offered to the model,
| the registry checks that an administrator has enabled the tool's group panel
| wide and that the user holds every subuser permission the tool declares, and
| the executor re-checks both again immediately before the call runs. A tool
| removed from this list disappears from every conversation at once, which
| makes this the place to disable a capability outright.
|
*/

return [
    'tools' => [
        // Read-only information about how the server is configured and how it
        // is currently performing.
        GetServerDetailsTool::class,
        GetServerResourcesTool::class,
        GetResourceHistoryTool::class,
        GetActivityLogTool::class,

        // Power state control.
        PowerActionTool::class,

        // Console access.
        SendConsoleCommandTool::class,

        // Filesystem browsing and modification.
        ListFilesTool::class,
        ReadFileTool::class,
        WriteFileTool::class,
        CreateFolderTool::class,
        RenameFilesTool::class,
        CopyFileTool::class,
        CompressFilesTool::class,
        DecompressFileTool::class,
        DeleteFilesTool::class,

        // Subuser access management.
        ListSubusersTool::class,
        ListPermissionsTool::class,
        CreateSubuserTool::class,
        UpdateSubuserPermissionsTool::class,
        DeleteSubuserTool::class,

        // Startup command, egg variables and modular startup parts.
        GetStartupTool::class,
        UpdateStartupVariableTool::class,
        UpdateStartupPartsTool::class,

        // Plugin installer. Gated by the egg allowlist in addition to the
        // tool group, exactly as the plugin page is.
        ListPluginsTool::class,
        SearchPluginsTool::class,
        ListPluginVersionsTool::class,
        InstallPluginTool::class,
        UpdatePluginTool::class,
        RemovePluginTool::class,
        TogglePluginTool::class,

        // Mod installer, gated the same way as the plugin installer.
        ListModsTool::class,
        SearchModsTool::class,
        ListModVersionsTool::class,
        InstallModTool::class,
        UpdateModTool::class,
        RemoveModTool::class,
        ToggleModTool::class,
    ],
];
