<?php

namespace App\Services\Chatbot\Tools\Schedules;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Schedule;
use App\Repositories\Eloquent\ScheduleRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;

class DeleteScheduleTool extends ChatbotTool
{
    public function __construct(private ScheduleRepository $scheduleRepository) {}

    public function name(): string
    {
        return 'delete_schedule';
    }

    public function description(): string
    {
        return 'Permanently delete a schedule and all its tasks. This cannot be undone.';
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
                'schedule_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the schedule to delete.',
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

    public function permissions(): array
    {
        return [Permission::ACTION_SCHEDULE_DELETE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete schedule #'.($arguments['schedule_id'] ?? '(id)');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $schedule = $this->findSchedule($context, $arguments['schedule_id']);

        $name = $schedule->name;
        $schedule->delete();

        return [
            'schedule_id' => $schedule->id,
            'message' => "Schedule \"{$name}\" and all its tasks have been permanently deleted.",
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

        /** @var Schedule $schedule */
        return $schedule;
    }
}
