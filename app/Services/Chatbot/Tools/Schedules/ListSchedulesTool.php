<?php

namespace App\Services\Chatbot\Tools\Schedules;

use App\Enum\ChatbotToolGroup;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class ListSchedulesTool extends ChatbotTool
{
    public function name(): string
    {
        return 'list_schedules';
    }

    public function description(): string
    {
        return 'List all scheduled tasks for this server, including their cron expressions, whether they are active, when they last ran and when they will run next, and the sequence of actions each schedule performs.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_SCHEDULE_READ];
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $schedules = $context->server->schedules()->with('tasks')->get();

        return [
            'count' => $schedules->count(),
            'entries' => $schedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'cron' => $schedule->cron_day_of_week.' '.$schedule->cron_month.' '.$schedule->cron_day_of_month.' '.$schedule->cron_hour.' '.$schedule->cron_minute,
                'is_active' => (bool) $schedule->is_active,
                'is_processing' => (bool) $schedule->is_processing,
                'only_when_online' => (bool) $schedule->only_when_online,
                'last_run_at' => $schedule->last_run_at?->toAtomString(),
                'next_run_at' => $schedule->next_run_at?->toAtomString(),
                'tasks' => $schedule->tasks->map(fn ($task) => [
                    'sequence_id' => $task->sequence_id,
                    'action' => $task->action,
                    'payload' => $task->payload,
                    'time_offset' => $task->time_offset,
                    'continue_on_failure' => (bool) $task->continue_on_failure,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
