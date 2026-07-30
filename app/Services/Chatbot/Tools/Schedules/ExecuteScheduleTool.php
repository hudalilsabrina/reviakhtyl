<?php

namespace App\Services\Chatbot\Tools\Schedules;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Schedule;
use App\Repositories\Eloquent\ScheduleRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Schedules\ProcessScheduleService;

class ExecuteScheduleTool extends ChatbotTool
{
    public function __construct(
        private ScheduleRepository $scheduleRepository,
        private ProcessScheduleService $processService,
    ) {}

    public function name(): string
    {
        return 'execute_schedule';
    }

    public function description(): string
    {
        return 'Trigger a scheduled task to run immediately, ignoring its cron schedule. The schedule\'s tasks run in sequence.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_SCHEDULE_UPDATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return "Immediately execute schedule #" . ($arguments['schedule_id'] ?? '(id)');
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schedule_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the schedule to run.',
                ],
            ],
            'required' => ['schedule_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'schedule_id' => 'required|integer|min:1',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $schedule = $this->findSchedule($context, $arguments['schedule_id']);

        $this->processService->handle($schedule, true);

        return [
            'schedule_id' => $schedule->id,
            'name' => $schedule->name,
            'message' => "Schedule \"{$schedule->name}\" has been triggered for immediate execution.",
        ];
    }

    private function findSchedule(ToolContext $context, int $id): Schedule
    {
        $schedule = $this->scheduleRepository
            ->getBuilder()
            ->where('server_id', $context->server->id)
            ->where('id', $id)
            ->first();

        if (! $schedule) {
            throw new ChatbotException("Schedule #{$id} not found on this server.");
        }

        return $schedule;
    }
}
