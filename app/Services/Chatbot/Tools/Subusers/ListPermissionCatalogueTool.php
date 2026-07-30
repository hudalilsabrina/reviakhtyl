<?php

namespace App\Services\Chatbot\Tools\Subusers;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\GetUserPermissionsService;

class ListPermissionCatalogueTool extends ChatbotTool
{
    public function __construct(private GetUserPermissionsService $permissionsService) {}

    public function name(): string
    {
        return 'list_subuser_permissions';
    }

    public function description(): string
    {
        return 'List every permission key that can be granted to a subuser, with an explanation of what each one allows, plus the subset of those keys the current user is allowed to hand out. Always call this before create_subuser or update_subuser_permissions so that the permission strings you send are real keys; invented keys are silently discarded or rejected.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Subusers;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function permissions(): array
    {
        return [Permission::ACTION_USER_READ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $catalogue = [];

        foreach (Permission::permissions() as $prefix => $group) {
            foreach ($group['keys'] as $key => $description) {
                $catalogue["$prefix.$key"] = $description;
            }
        }

        $assignable = $this->permissionsService->handle($context->server, $context->user);
        $canAssignAll = in_array('*', $assignable, true);

        return [
            // Named "entries" so an oversized catalogue is trimmed by the
            // executor rather than discarded wholesale.
            'entries' => $catalogue,
            'can_assign_all' => $canAssignAll,
            'assignable' => $canAssignAll
                ? array_keys($catalogue)
                : array_values(array_intersect($assignable, array_keys($catalogue))),
            'note' => '"entries" maps every permission key to what it allows. websocket.connect is always granted automatically and does not need to be requested.',
        ];
    }
}
