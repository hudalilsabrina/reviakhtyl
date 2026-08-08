<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One router → sub-agent delegation. The row captures the sub-agent's private
 * provider-shaped exchange (its transcript) so a paused run can be resumed
 * after the user decides, and everything an agent did is auditable without
 * polluting the conversation's visible message history.
 *
 * The `uuid` is supplied by the creating service rather than a model hook: the
 * base model validates on `saving`, which fires before `creating` would run.
 *
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property int|null $parent_run_id
 * @property string $agent_key
 * @property string $request
 * @property array|null $transcript
 * @property string|null $result
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property ChatbotConversation $conversation
 */
class ChatbotAgentRun extends Model
{
    public const RESOURCE_NAME = 'chatbot_agent_run';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    /** The user refused the proposed tool calls and the run is closed. */
    public const STATUS_DENIED = 'denied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    protected $table = 'chatbot_agent_runs';

    protected bool $immutableDates = true;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'id' => 'int',
        'conversation_id' => 'int',
        'parent_run_id' => 'int',
        'transcript' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_RUNNING,
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid',
        'conversation_id' => 'required|numeric|exists:chatbot_conversations,id',
        'parent_run_id' => 'nullable|numeric|exists:chatbot_agent_runs,id',
        'agent_key' => 'required|string|max:64',
        'request' => 'required|string',
        'transcript' => 'nullable|array',
        'result' => 'nullable|string',
        'status' => 'required|string',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }
}
