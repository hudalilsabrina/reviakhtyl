<?php

namespace App\Services\Chatbot\Tools\Console;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Repositories\Agent\DaemonCommandRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Response;

class SendConsoleCommandTool extends ChatbotTool
{
    public function __construct(private DaemonCommandRepository $repository) {}

    public function name(): string
    {
        return 'send_console_command';
    }

    public function description(): string
    {
        return 'Send a single command to the running server console, exactly as if it were typed there. The server must be running. This tool does not return the command output — the output appears in the live console — so do not rely on a response to confirm the result.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Console;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The command to run, without a leading slash unless the game requires one.',
                ],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['command' => 'required|string|max:2000'];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_CONTROL_CONSOLE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Run console command: '.($arguments['command'] ?? '');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        try {
            $this->repository->setServer($context->server)->send($arguments['command']);
        } catch (DaemonConnectionException $exception) {
            $previous = $exception->getPrevious();

            // The daemon answers with 502 when the server is not running.
            if ($previous instanceof BadResponseException && $previous->getResponse()->getStatusCode() === Response::HTTP_BAD_GATEWAY) {
                throw new ChatbotException('The command could not be sent because the server is not running.');
            }

            throw $exception;
        }

        return [
            'command' => $arguments['command'],
            'message' => 'The command was sent to the server console. Its output is visible in the console, not here.',
        ];
    }
}
