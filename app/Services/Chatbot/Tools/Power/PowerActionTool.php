<?php

namespace App\Services\Chatbot\Tools\Power;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Repositories\Agent\DaemonPowerRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class PowerActionTool extends ChatbotTool
{
    /**
     * The subuser permission each signal requires, mirroring SendPowerRequest.
     */
    private const PERMISSIONS = [
        'start' => Permission::ACTION_CONTROL_START,
        'stop' => Permission::ACTION_CONTROL_STOP,
        'kill' => Permission::ACTION_CONTROL_STOP,
        'restart' => Permission::ACTION_CONTROL_RESTART,
    ];

    public function __construct(private DaemonPowerRepository $repository) {}

    public function name(): string
    {
        return 'power_action';
    }

    public function description(): string
    {
        return 'Change the power state of the server. "start" boots it, "stop" asks it to shut down gracefully, "restart" stops then starts it, and "kill" terminates the process immediately and can cause data loss. Prefer "stop" over "kill" unless the server is unresponsive.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Power;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'signal' => [
                    'type' => 'string',
                    'enum' => ['start', 'stop', 'restart', 'kill'],
                    'description' => 'The power signal to send.',
                ],
            ],
            'required' => ['signal'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['signal' => 'required|string|in:start,stop,restart,kill'];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return match ($arguments['signal'] ?? null) {
            'start' => 'Start the server',
            'stop' => 'Stop the server',
            'restart' => 'Restart the server',
            'kill' => 'Forcibly kill the server process',
            default => 'Send a power signal to the server',
        };
    }

    /**
     * Offered as long as the user can perform at least one power action; the
     * specific signal is authorized in handle().
     */
    public function isAvailableFor(ToolContext $context): bool
    {
        foreach (array_unique(self::PERMISSIONS) as $permission) {
            if ($context->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $signal = $arguments['signal'];
        $permission = self::PERMISSIONS[$signal];

        if (! $context->can($permission)) {
            throw new ChatbotException("You do not have permission to $signal this server.");
        }

        $this->repository->setServer($context->server)->send($signal);

        return [
            'signal' => $signal,
            'message' => "The $signal signal was sent to the server. It may take a few moments to take effect.",
        ];
    }
}
