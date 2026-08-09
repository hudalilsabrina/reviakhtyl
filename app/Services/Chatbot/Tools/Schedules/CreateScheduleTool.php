<?php

namespace App\Services\Chatbot\Tools\Schedules;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Schedule;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Carbon\Carbon;
use Cron\CronExpression;

class CreateScheduleTool extends ChatbotTool
{
    public function __construct() {}

    public function name(): string
    {
        return 'create_schedule';
    }

    public function description(): string
    {
        return 'Create a new scheduled task for the server. The cron field accepts a standard 5-field cron expression, e.g. "0 3 * * *" for every day at 3am. Each task in the schedule runs in sequence by its sequence_id. Action types: "power" (send a signal: start, stop, restart, kill), "command" (send a console command), "backup" (create a backup).';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'A human-readable name for the schedule.',
                ],
                'cron' => [
                    'type' => 'string',
                    'description' => 'Standard 5-field cron expression, e.g. "0 3 * * *" or "*/15 * * * *".',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether the schedule is active. Defaults to true.',
                ],
                'only_when_online' => [
                    'type' => 'boolean',
                    'description' => 'Only run the schedule when the server is online. Defaults to false.',
                ],
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sequence_id' => [
                                'type' => 'integer',
                                'description' => 'Order this task runs in, starting at 0.',
                            ],
                            'action' => [
                                'type' => 'string',
                                'enum' => ['power', 'command', 'backup'],
                                'description' => 'The type of action: "power", "command", or "backup".',
                            ],
                            'payload' => [
                                'type' => 'string',
                                'description' => 'The action payload. For "power": the signal (start, stop, restart, kill). For "command": the console command. For "backup": unused.',
                            ],
                            'continue_on_failure' => [
                                'type' => 'boolean',
                                'description' => 'Continue to the next task if this one fails. Defaults to false.',
                            ],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'Ordered list of tasks to run when the schedule fires.',
                ],
            ],
            'required' => ['name', 'cron', 'tasks'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'cron' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'only_when_online' => 'nullable|boolean',
            'tasks' => 'required|array|min:1',
            'tasks.*.sequence_id' => 'nullable|integer|min:0',
            'tasks.*.action' => 'required|string|in:power,command,backup',
            'tasks.*.payload' => 'nullable|string|max:191',
            'tasks.*.continue_on_failure' => 'nullable|boolean',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_SCHEDULE_CREATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $name = $arguments['name'] ?? '(schedule)';
        $cron = $arguments['cron'] ?? '* * * * *';

        $actionTypes = collect($arguments['tasks'] ?? [])->pluck('action')->unique()->join(', ');

        return "Create schedule \"{$name}\" (cron: {$cron}) with tasks: {$actionTypes}";
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->validateCron($arguments['cron']);

        $schedule = Schedule::create([
            'server_id' => $context->server->id,
            'name' => $arguments['name'],
            'cron_day_of_week' => '*',
            'cron_month' => '*',
            'cron_day_of_month' => '*',
            'cron_hour' => '*',
            'cron_minute' => '*',
            'is_active' => $arguments['is_active'] ?? true,
            'only_when_online' => $arguments['only_when_online'] ?? false,
            'next_run_at' => $this->nextRun($arguments['cron']),
        ]);

        foreach ($arguments['tasks'] as $index => $task) {
            $schedule->tasks()->create([
                'sequence_id' => $task['sequence_id'] ?? $index,
                'action' => $task['action'],
                'payload' => $task['payload'] ?? '',
                'time_offset' => 0,
                'is_queued' => false,
                'continue_on_failure' => $task['continue_on_failure'] ?? false,
            ]);
        }

        $schedule->loadMissing('tasks');

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'cron' => $this->buildCronString($schedule),
            'is_active' => (bool) $schedule->is_active,
            'only_when_online' => (bool) $schedule->only_when_online,
            'next_run_at' => $schedule->next_run_at?->toAtomString(),
            'tasks' => $schedule->tasks->map(fn ($task) => [
                'sequence_id' => $task->sequence_id,
                'action' => $task->action,
                'payload' => $task->payload,
                'continue_on_failure' => (bool) $task->continue_on_failure,
            ])->values()->all(),
            'message' => "Schedule \"{$schedule->name}\" created.",
        ];
    }

    private function validateCron(string $cron): void
    {
        try {
            CronExpression::factory($cron);
        } catch (\Throwable) {
            throw new ChatbotException("Invalid cron expression: \"{$cron}\". Use a standard 5-field cron like \"0 3 * * *\".");
        }
    }

    private function nextRun(string $cron): Carbon
    {
        try {
            return new Carbon(CronExpression::factory($cron)->getNextRunDate()->getTimestamp());
        } catch (\Throwable) {
            throw new DisplayException('Could not calculate the next run date for the cron expression.');
        }
    }

    private function buildCronString(Schedule $schedule): string
    {
        return $schedule->cron_minute.' '.$schedule->cron_hour.' '.$schedule->cron_day_of_month.' '.$schedule->cron_month.' '.$schedule->cron_day_of_week;
    }
}
