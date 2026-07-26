<?php

namespace App\Services\Chatbot\Tools\Startup;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Repositories\Eloquent\ServerVariableRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\StartupCommandService;
use Illuminate\Support\Facades\Validator;

class UpdateStartupVariableTool extends ChatbotTool
{
    public function __construct(
        private StartupCommandService $startupCommandService,
        private ServerVariableRepository $repository,
    ) {}

    public function name(): string
    {
        return 'update_startup_variable';
    }

    public function description(): string
    {
        return 'Change the value of one startup variable, identified by its environment variable name (for example SERVER_JARFILE). Only variables that get_startup reports as editable can be changed, and the new value has to satisfy that variable\'s validation rules or the change is rejected. The server keeps running with its old configuration until it is restarted, so tell the user a restart is needed for the change to take effect.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Startup;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'The env_variable name of the variable to change, exactly as returned by get_startup.',
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'The new value. Pass an empty string to clear it.',
                ],
            ],
            'required' => ['key', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'key' => 'required|string|max:191',
            'value' => 'present|string',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_STARTUP_UPDATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Change the startup variable '.($arguments['key'] ?? '').' to "'.($arguments['value'] ?? '')
            .'" (takes effect on the next server restart)';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;
        $value = $arguments['value'] ?? '';

        $variable = $server->variables()->where('env_variable', $arguments['key'])->first();

        if (is_null($variable) || ! $variable->user_viewable) {
            throw new ChatbotException('The environment variable you are trying to edit does not exist. Use get_startup to see the variables this server has.');
        }

        if (! $variable->user_editable) {
            throw new ChatbotException("The environment variable $variable->env_variable is read-only and cannot be changed.");
        }

        // The egg defines its own validation rules per variable; a value that
        // fails them would break the server's startup command.
        $validator = Validator::make(['value' => $value], ['value' => $variable->rules]);

        if ($validator->fails()) {
            throw new ChatbotException(
                "The value for $variable->env_variable is not valid: ".implode(' ', $validator->errors()->all())
                ." It must satisfy: $variable->rules"
            );
        }

        $original = $variable->server_value;

        $this->repository->updateOrCreate([
            'server_id' => $server->id,
            'variable_id' => $variable->id,
        ], [
            'variable_value' => $value,
        ]);

        return [
            'env_variable' => $variable->env_variable,
            'old_value' => $original,
            'new_value' => $value,
            'startup_command' => $this->startupCommandService->handle($server->load('variables')),
            'message' => 'The variable was saved. The server must be restarted before the change takes effect.',
        ];
    }
}
