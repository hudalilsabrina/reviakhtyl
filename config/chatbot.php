<?php

use App\Services\Chatbot\Tools\Backups\CreateBackupTool;
use App\Services\Chatbot\Tools\Backups\DeleteBackupTool;
use App\Services\Chatbot\Tools\Backups\ListBackupsTool;
use App\Services\Chatbot\Tools\Backups\RestoreBackupTool;
use App\Services\Chatbot\Tools\Console\SendConsoleCommandTool;
use App\Services\Chatbot\Tools\Databases\CreateDatabaseTool;
use App\Services\Chatbot\Tools\Databases\DeleteDatabaseTool;
use App\Services\Chatbot\Tools\Databases\ListDatabasesTool;
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
use App\Services\Chatbot\Tools\Schedules\CreateScheduleTool;
use App\Services\Chatbot\Tools\Schedules\DeleteScheduleTool;
use App\Services\Chatbot\Tools\Schedules\ExecuteScheduleTool;
use App\Services\Chatbot\Tools\Schedules\ListSchedulesTool;
use App\Services\Chatbot\Tools\Server\GetActivityLogTool;
use App\Services\Chatbot\Tools\Server\GetResourceHistoryTool;
use App\Services\Chatbot\Tools\Server\GetServerDetailsTool;
use App\Services\Chatbot\Tools\Server\GetServerResourcesTool;
use App\Services\Chatbot\Tools\Server\ReadServerLogTool;
use App\Services\Chatbot\Tools\Server\RenameServerTool;
use App\Services\Chatbot\Tools\Startup\GetStartupTool;
use App\Services\Chatbot\Tools\Startup\UpdateStartupPartsTool;
use App\Services\Chatbot\Tools\Startup\UpdateStartupVariableTool;
use App\Services\Chatbot\Tools\Subusers\CreateSubuserTool;
use App\Services\Chatbot\Tools\Subusers\DeleteSubuserTool;
use App\Services\Chatbot\Tools\Subusers\ListPermissionsTool;
use App\Services\Chatbot\Tools\Subusers\ListSubusersTool;
use App\Services\Chatbot\Tools\Subusers\UpdateSubuserPermissionsTool;

return [
    'tools' => [
        // Read-only information about how the server is configured and how it
        // is currently performing.
        GetServerDetailsTool::class,
        GetServerResourcesTool::class,
        GetResourceHistoryTool::class,
        GetActivityLogTool::class,

        // Server logs.
        ReadServerLogTool::class,

        // Power state control.
        PowerActionTool::class,

        // Console access.
        SendConsoleCommandTool::class,

        // File management.
        ListFilesTool::class,
        ReadFileTool::class,
        WriteFileTool::class,
        CreateFolderTool::class,
        RenameFilesTool::class,
        CopyFileTool::class,
        CompressFilesTool::class,
        DecompressFileTool::class,
        DeleteFilesTool::class,

        // Subuser management.
        ListSubusersTool::class,
        ListPermissionsTool::class,
        CreateSubuserTool::class,
        UpdateSubuserPermissionsTool::class,
        DeleteSubuserTool::class,

        // Server configuration.
        GetStartupTool::class,
        UpdateStartupVariableTool::class,
        UpdateStartupPartsTool::class,
        RenameServerTool::class,

        // Plugin management.
        SearchPluginsTool::class,
        ListPluginsTool::class,
        ListPluginVersionsTool::class,
        InstallPluginTool::class,
        UpdatePluginTool::class,
        RemovePluginTool::class,
        TogglePluginTool::class,

        // Mod management.
        SearchModsTool::class,
        ListModsTool::class,
        ListModVersionsTool::class,
        InstallModTool::class,
        UpdateModTool::class,
        RemoveModTool::class,
        ToggleModTool::class,

        // Backup management.
        ListBackupsTool::class,
        CreateBackupTool::class,
        RestoreBackupTool::class,
        DeleteBackupTool::class,

        // Database management.
        ListDatabasesTool::class,
        CreateDatabaseTool::class,
        DeleteDatabaseTool::class,

        // Schedule management.
        ListSchedulesTool::class,
        CreateScheduleTool::class,
        ExecuteScheduleTool::class,
        DeleteScheduleTool::class,
    ],
];
