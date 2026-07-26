<?php

namespace App\Services\Chatbot\Tools\Subusers;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Subuser;
use App\Repositories\Agent\DaemonRevocationRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Facades\Log;

class DeleteSubuserTool extends ChatbotTool
{
    public function __construct(private DaemonRevocationRepository $revocationRepository) {}

    public function name(): string
    {
        return 'delete_subuser';
    }

    public function description(): string
    {
        return 'Remove a subuser, identified by their email address, from this server. They immediately lose all access and are disconnected from the console and SFTP. Their panel account and any access they have to other servers are left untouched. You cannot remove yourself or the server owner. To reduce what someone can do without cutting them off entirely, use update_subuser_permissions instead.';
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
                    'description' => 'Email address of the subuser to remove, exactly as returned by list_subusers.',
                ],
            ],
            'required' => ['email'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['email' => 'required|string|max:191'];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_USER_DELETE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Remove '.($arguments['email'] ?? 'a subuser').' from this server entirely, revoking all of their access';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $subuser = $this->findSubuser($context, $arguments['email']);
        $uuid = $subuser->user->uuid;

        $subuser->delete();

        return [
            'email' => $arguments['email'],
            'uuid' => $uuid,
            'revoked' => $this->revokeTokens($context, $subuser, $uuid),
            'message' => 'The subuser no longer has any access to this server.',
        ];
    }

    /**
     * Mirrors SubuserRequest::authorize(): a user may never remove their own
     * access, and the owner is not a subuser to begin with.
     */
    private function findSubuser(ToolContext $context, string $email): Subuser
    {
        $server = $context->server;

        if (strcasecmp($email, (string) $context->user->email) === 0) {
            throw new ChatbotException('You cannot remove your own access to this server.');
        }

        if (strcasecmp($email, (string) $server->user?->email) === 0) {
            throw new ChatbotException('That email address belongs to the owner of this server, who cannot be removed.');
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
            throw new ChatbotException('You cannot remove your own access to this server.');
        }

        return $subuser;
    }

    /**
     * Invalidates the removed user's daemon tokens. A node that is offline is
     * not a reason to fail the whole removal: the tokens are invalid again as
     * soon as it boots.
     */
    private function revokeTokens(ToolContext $context, Subuser $subuser, string $uuid): bool
    {
        try {
            $this->revocationRepository->setNode($context->server->node)->deauthorize(
                $uuid,
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
