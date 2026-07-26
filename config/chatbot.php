<?php

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
        App\Services\Chatbot\Tools\Server\GetServerDetailsTool::class,
        App\Services\Chatbot\Tools\Server\GetServerResourcesTool::class,

        // Power state control.
        App\Services\Chatbot\Tools\Power\PowerActionTool::class,

        // Console access.
        App\Services\Chatbot\Tools\Console\SendConsoleCommandTool::class,

        // Filesystem browsing and modification.
        App\Services\Chatbot\Tools\Files\ListFilesTool::class,
        App\Services\Chatbot\Tools\Files\ReadFileTool::class,
        App\Services\Chatbot\Tools\Files\WriteFileTool::class,
        App\Services\Chatbot\Tools\Files\CreateFolderTool::class,
        App\Services\Chatbot\Tools\Files\RenameFilesTool::class,
        App\Services\Chatbot\Tools\Files\CopyFileTool::class,
        App\Services\Chatbot\Tools\Files\CompressFilesTool::class,
        App\Services\Chatbot\Tools\Files\DecompressFileTool::class,
        App\Services\Chatbot\Tools\Files\DeleteFilesTool::class,

        // Subuser access management.
        App\Services\Chatbot\Tools\Subusers\ListSubusersTool::class,
        App\Services\Chatbot\Tools\Subusers\ListPermissionsTool::class,
        App\Services\Chatbot\Tools\Subusers\CreateSubuserTool::class,
        App\Services\Chatbot\Tools\Subusers\UpdateSubuserPermissionsTool::class,
        App\Services\Chatbot\Tools\Subusers\DeleteSubuserTool::class,

        // Startup command, egg variables and modular startup parts.
        App\Services\Chatbot\Tools\Startup\GetStartupTool::class,
        App\Services\Chatbot\Tools\Startup\UpdateStartupVariableTool::class,
        App\Services\Chatbot\Tools\Startup\UpdateStartupPartsTool::class,
    ],
];
