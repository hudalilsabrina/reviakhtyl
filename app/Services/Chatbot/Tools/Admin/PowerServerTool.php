<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Repositories\Agent\DaemonPowerRepository;
use App\Services\Chatbot\ToolContext;

class PowerServerTool extends AdminTool
{
    private const PERMISSIONS = ['start', 'stop', 'restart', 'kill'];

    public function __construct(private DaemonPowerRepository $repository) {}

    public function name(): string
    {
        return 'power_server';
    }

    public function description(): string
    {
        return 'Change the power state of any server on the panel. "start" boots it, "stop" asks it to shut down gracefully, "restart" stops then starts it, and "kill" terminates the process immediately and can cause data loss. Prefer "stop" over "kill" unless the server is unresponsive.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => $this->serverIdSchema(),
                'signal' => [
                    'type' => 'string',
                    'enum' => self::PERMISSIONS,
                    'description' => 'The power signal to send.',
                ],
            ],
            'required' => ['server_id', 'signal'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return array_merge($this->serverIdRule(), [
            'signal' => 'required|string|in:'.implode(',', self::PERMISSIONS),
        ]);
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return match ($arguments['signal'] ?? null) {
            'start' => 'Start server '.($arguments['server_id'] ?? ''),
            'stop' => 'Stop server '.($arguments['server_id'] ?? ''),
            'restart' => 'Restart server '.($arguments['server_id'] ?? ''),
            'kill' => 'Force-kill server '.($arguments['server_id'] ?? ''),
            default => 'Send a power signal to server '.($arguments['server_id'] ?? ''),
        };
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $this->resolveServer($arguments);
        $signal = $arguments['signal'];

        try {
            $this->repository->setServer($server)->send($signal);
        } catch (\Throwable $e) {
            throw new ChatbotException("The $signal signal could not be sent: {$e->getMessage()}");
        }

        return [
            'signal' => $signal,
            'message' => "The $signal signal was sent to server \"{$server->name}\". It may take a few moments to take effect.",
        ];
    }
}
