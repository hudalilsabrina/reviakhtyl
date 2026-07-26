<?php

namespace App\Services\Chatbot\Tools\Subusers;

use App\Enum\ChatbotToolGroup;
use App\Enum\ResourceLimit;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Exceptions\Service\Subuser\ServerSubuserExistsException;
use App\Exceptions\Service\Subuser\UserIsServerOwnerException;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\GetUserPermissionsService;
use App\Services\Subusers\SubuserCreationService;

class CreateSubuserTool extends ChatbotTool
{
    public function __construct(
        private SubuserCreationService $creationService,
        private GetUserPermissionsService $permissionsService,
    ) {}

    public function name(): string
    {
        return 'create_subuser';
    }

    public function description(): string
    {
        return 'Give someone access to this server by inviting their email address as a subuser with a specific set of permissions. If no panel account exists for that email address, one is created for them. You cannot grant a permission the current user does not hold themselves, and you cannot invite the server owner or somebody who is already a subuser. Call list_subuser_permissions first to get valid permission keys; websocket.connect is granted automatically.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Subusers;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'description' => 'Email address of the person to invite.',
                ],
                'permissions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Permission keys to grant, for example "control.console" or "file.read". Use list_subuser_permissions to see the valid keys.',
                ],
            ],
            'required' => ['email', 'permissions'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|between:1,191',
            'permissions' => 'required|array|max:100',
            'permissions.*' => 'required|string|max:191',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_USER_CREATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $permissions = $arguments['permissions'] ?? [];

        return 'Give '.($arguments['email'] ?? 'someone').' access to this server with '
            .count($permissions).' permission(s): '.(implode(', ', $permissions) ?: 'none');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $requested = $arguments['permissions'];

        // The HTTP endpoint carries ResourceLimit::Subuser; without this the
        // assistant would be a way around the per-server creation allowance.
        if (! ResourceLimit::Subuser->hit($context->server)) {
            throw new ChatbotException(
                'This server has reached its limit for adding subusers in a short period. Try again in a few minutes.'
            );
        }

        $this->assertCanBeAssigned($context, $requested);

        $permissions = $this->cleanPermissions($requested);

        try {
            $subuser = $this->creationService->handle($context->server, $arguments['email'], $permissions);
        } catch (UserIsServerOwnerException) {
            throw new ChatbotException('That email address belongs to the owner of this server, who already has full access and cannot be added as a subuser.');
        } catch (ServerSubuserExistsException) {
            throw new ChatbotException('That email address is already a subuser on this server. Use update_subuser_permissions to change what they are allowed to do.');
        }

        return [
            'email' => $arguments['email'],
            'uuid' => $subuser->user?->uuid,
            'permissions' => array_values($permissions),
            'ignored_permissions' => array_values(array_diff($requested, $permissions)),
        ];
    }

    /**
     * Drops permission keys that do not exist and always grants websocket
     * access, mirroring SubuserController::getDefaultPermissions().
     */
    private function cleanPermissions(array $requested): array
    {
        $allowed = Permission::permissions()
            ->map(fn ($group, $prefix) => array_map(fn ($key) => "$prefix.$key", array_keys($group['keys'])))
            ->flatten()
            ->all();

        return array_values(array_unique(array_merge(
            array_intersect($requested, $allowed),
            [Permission::ACTION_WEBSOCKET_CONNECT],
        )));
    }

    /**
     * Mirrors SubuserRequest::validatePermissionsCanBeAssigned(): nobody may
     * hand out access they do not hold themselves. Root admins and the server
     * owner are exempt because they implicitly hold everything.
     */
    private function assertCanBeAssigned(ToolContext $context, array $requested): void
    {
        $user = $context->user;
        $server = $context->server;

        if ($user->root_admin || $user->id === $server->owner_id) {
            return;
        }

        $missing = array_diff($requested, $this->permissionsService->handle($server, $user));

        if (count($missing) > 0) {
            throw new ChatbotException(
                'Cannot assign permissions to a subuser that your account does not actively possess: '
                .implode(', ', $missing).'. Use list_subuser_permissions to see what you may assign.',
            );
        }
    }
}
