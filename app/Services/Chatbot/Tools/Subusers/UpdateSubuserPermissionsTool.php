<?php

namespace App\Services\Chatbot\Tools\Subusers;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Subuser;
use App\Repositories\Agent\DaemonRevocationRepository;
use App\Repositories\Eloquent\SubuserRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\GetUserPermissionsService;
use Illuminate\Support\Facades\Log;

class UpdateSubuserPermissionsTool extends ChatbotTool
{
    public function __construct(
        private SubuserRepository $repository,
        private DaemonRevocationRepository $revocationRepository,
        private GetUserPermissionsService $permissionsService,
    ) {}

    public function name(): string
    {
        return 'update_subuser_permissions';
    }

    public function description(): string
    {
        return 'Replace the complete permission set of an existing subuser, identified by their email address. This is a replacement, not a merge: any permission you leave out is revoked, so read the current set with list_subusers first and send it back with your changes applied. The subuser is disconnected from the console and SFTP so the new permissions take effect immediately. You cannot edit your own access or the server owner, and you cannot grant a permission the current user does not hold themselves.';
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
                    'description' => 'Email address of the existing subuser to update, exactly as returned by list_subusers.',
                ],
                'permissions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'The full set of permission keys the subuser should end up with. Anything omitted is removed.',
                ],
            ],
            'required' => ['email', 'permissions'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'email' => 'required|string|max:191',
            'permissions' => 'required|array|max:100',
            'permissions.*' => 'required|string|max:191',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_USER_UPDATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $permissions = $arguments['permissions'] ?? [];

        return 'Replace what '.($arguments['email'] ?? 'a subuser').' is allowed to do on this server with '
            .count($permissions).' permission(s): '.(implode(', ', $permissions) ?: 'none')
            .'. Anything not listed is taken away.';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $requested = $arguments['permissions'];

        $subuser = $this->findSubuser($context, $arguments['email']);

        $this->assertCanBeAssigned($context, $requested);

        $permissions = $this->cleanPermissions($requested);
        $current = $subuser->permissions ?? [];

        sort($permissions);
        sort($current);

        if ($permissions === $current) {
            return [
                'email' => $arguments['email'],
                'permissions' => $permissions,
                'changed' => false,
                'revoked' => false,
                'message' => 'The subuser already had exactly these permissions, so nothing was changed.',
            ];
        }

        $this->repository->update($subuser->id, ['permissions' => $permissions]);

        return [
            'email' => $arguments['email'],
            'permissions' => $permissions,
            'removed' => array_values(array_diff($current, $permissions)),
            'added' => array_values(array_diff($permissions, $current)),
            'changed' => true,
            'revoked' => $this->revokeTokens($context, $subuser),
            'ignored_permissions' => array_values(array_diff($requested, $permissions)),
        ];
    }

    /**
     * Mirrors SubuserRequest::authorize(): a user may never edit their own
     * access, and the owner is not a subuser to begin with.
     */
    private function findSubuser(ToolContext $context, string $email): Subuser
    {
        $server = $context->server;

        if (strcasecmp($email, (string) $context->user->email) === 0) {
            throw new ChatbotException('You cannot change your own permissions on this server.');
        }

        if (strcasecmp($email, (string) $server->user?->email) === 0) {
            throw new ChatbotException('That email address belongs to the owner of this server, whose access cannot be changed.');
        }

        /** @var Subuser|null $subuser */
        $subuser = $server->subusers()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->first();

        if (! $subuser) {
            throw new ChatbotException("No subuser with the email address \"$email\" exists on this server. Use list_subusers to see who does.");
        }

        // Belt and braces: the email lookup above is case-insensitive on most
        // collations, so re-check the resolved user rather than the input.
        if ($subuser->user->uuid === $context->user->uuid) {
            throw new ChatbotException('You cannot change your own permissions on this server.');
        }

        return $subuser;
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
     * hand out access they do not hold themselves.
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

    /**
     * Invalidates the subuser's daemon tokens so the old permissions stop
     * applying. A node that is offline is not a reason to fail the whole
     * update: the tokens are invalid again as soon as it boots.
     */
    private function revokeTokens(ToolContext $context, Subuser $subuser): bool
    {
        try {
            $this->revocationRepository->setNode($context->server->node)->deauthorize(
                $subuser->user->uuid,
                [$context->server->uuid],
            );
        } catch (DaemonConnectionException $exception) {
            Log::warning($exception, [
                'user_id' => $subuser->user_id,
                'server_id' => $context->server->id,
            ]);

            return false;
        }

        return true;
    }
}
