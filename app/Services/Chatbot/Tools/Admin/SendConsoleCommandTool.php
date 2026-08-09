<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Repositories\Agent\DaemonCommandRepository;
use App\Services\Chatbot\ToolContext;

class SendConsoleCommandTool extends AdminTool
{
    public function __construct(private DaemonCommandRepository $repository) {}

    public function name(): string
    {
        return 'send_console_command';
    }

    public function description(): string
    {
        return 'Send a single command to the console of any server on the panel, exactly as if it were typed there. The server must be running. This tool does not return the command output.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => $this->serverIdSchema(),
                'command' => [
                    'type' => 'string',
                    'description' => 'The command to run, without a leading slash unless the game requires one.',
                ],
            ],
            'required' => ['server_id', 'command'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return array_merge($this->serverIdRule(), [
            'command' => 'required|string|max:2000',
        ]);
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Run console command "'.($arguments['command'] ?? '').'" on server '.($arguments['server_id'] ?? '');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $this->resolveServer($arguments);

        $this->repository->setServer($server)->send($arguments['command']);

        return [
            'command' => $arguments['command'],
            'message' => "The command was sent to the console of server \"{$server->name}\". Its output is visible in the console, not here.",
        ];
    }
}
