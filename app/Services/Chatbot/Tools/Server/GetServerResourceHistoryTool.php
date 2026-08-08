<?php

namespace App\Services\Chatbot\Tools\Server;

use App\Enum\ChatbotToolGroup;
use App\Models\ServerStatsHistory;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetServerResourceHistoryTool extends ChatbotTool
{
    /** How many points the trend is reduced to before being handed to the model. */
    private const TREND_POINTS = 12;

    /** Upper bound on rows pulled for the trend, whatever the sampling rate turns out to be. */
    private const TREND_SOURCE_LIMIT = 400;

    public function name(): string
    {
        return 'get_resource_history';
    }

    public function description(): string
    {
        return 'Get recorded CPU, memory, disk and network usage for this server over the last 1, 3 or 7 days: the average and the peak of each, when the peak happened, and a short trend series. Use this for questions about behaviour over time — whether memory is creeping up, when load spiked, whether the server is near its limits — as opposed to get_server_resources, which reports only the current instant. Returns nothing if the panel has not recorded any samples yet.';
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
                'days' => [
                    'type' => 'integer',
                    'enum' => [1, 3, 7],
                    'description' => 'How far back to look. Defaults to 1.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['days' => 'sometimes|nullable|integer|in:1,3,7'];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;
        $days = (int) ($arguments['days'] ?? 1);
        $since = Carbon::now()->subDays($days);

        $base = fn () => ServerStatsHistory::query()
            ->where('server_id', $server->id)
            ->where('created_at', '>=', $since);

        // Aggregated in SQL so the averages and peaks cover the whole window
        // regardless of how many samples it holds.
        $summary = $base()->select([
            DB::raw('COUNT(*) as samples'),
            DB::raw('AVG(cpu_usage) as avg_cpu'),
            DB::raw('MAX(cpu_usage) as peak_cpu'),
            DB::raw('AVG(memory_bytes) as avg_memory'),
            DB::raw('MAX(memory_bytes) as peak_memory'),
            DB::raw('MAX(disk_bytes) as peak_disk'),
        ])->first();

        if (! $summary || (int) $summary->samples === 0) {
            return [
                'window_days' => $days,
                'samples' => 0,
                'note' => 'The panel has no recorded resource samples for this server in that window, so there is no history to report. Use get_server_resources for the current usage.',
            ];
        }

        $latest = $base()->orderByDesc('created_at')->first();

        return [
            'window_days' => $days,
            'window_start' => $since->toIso8601String(),
            'samples' => (int) $summary->samples,
            'limits' => [
                // 0 means unlimited in the panel, which reads as "no limit" rather than "none".
                'memory_bytes' => $server->memory > 0 ? $server->memory * 1024 * 1024 : null,
                'disk_bytes' => $server->disk > 0 ? $server->disk * 1024 * 1024 : null,
                'cpu_percent' => $server->cpu > 0 ? $server->cpu : null,
            ],
            'cpu_percent' => [
                'average' => round((float) $summary->avg_cpu),
                'peak' => round((float) $summary->peak_cpu),
                'peak_at' => $this->peakAt($server->id, $since, 'cpu_usage'),
            ],
            'memory_bytes' => [
                'average' => (int) $summary->avg_memory,
                'peak' => (int) $summary->peak_memory,
                'peak_at' => $this->peakAt($server->id, $since, 'memory_bytes'),
                'latest' => $latest?->memory_bytes,
            ],
            'disk_bytes' => [
                'peak' => (int) $summary->peak_disk,
                'latest' => $latest?->disk_bytes,
            ],
            'trend' => $this->trend($server->id, $since),
        ];
    }

    /**
     * When the highest reading in the window was taken — the difference between
     * "it peaked at 3 GB" and "it peaked at 3 GB during last night's restart".
     */
    private function peakAt(int $serverId, Carbon $since, string $column): ?string
    {
        $row = ServerStatsHistory::query()
            ->where('server_id', $serverId)
            ->where('created_at', '>=', $since)
            ->orderByDesc($column)
            ->first(['created_at']);

        return $row?->created_at?->toIso8601String();
    }

    /**
     * A handful of evenly spaced readings, enough to describe the shape of the
     * window without spending the context budget on hundreds of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trend(int $serverId, Carbon $since): array
    {
        $rows = ServerStatsHistory::query()
            ->where('server_id', $serverId)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(self::TREND_SOURCE_LIMIT)
            ->get(['created_at', 'cpu_usage', 'memory_bytes'])
            ->reverse()
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $step = max(1, (int) ceil($rows->count() / self::TREND_POINTS));

        return $rows
            ->filter(fn ($row, $index) => $index % $step === 0)
            ->map(fn ($row) => [
                'at' => $row->created_at->toIso8601String(),
                'cpu_percent' => round((float) $row->cpu_usage),
                'memory_bytes' => (int) $row->memory_bytes,
            ])
            ->values()
            ->all();
    }
}
