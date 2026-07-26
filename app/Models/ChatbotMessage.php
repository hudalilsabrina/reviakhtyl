<?php

namespace App\Models;

use App\Services\Chatbot\Data\ToolCall;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a conversation. Tool results are stored as `tool` messages so the
 * whole exchange can be replayed to the provider verbatim.
 *
 * The `uuid` is supplied by the creating service rather than a model hook: the
 * base model validates on `saving`, which fires before `creating` would run.
 *
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property string $role
 * @property string|null $content
 * @property string|null $reasoning
 * @property array|null $tool_calls
 * @property string|null $tool_call_id
 * @property string|null $tool_name
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property ChatbotConversation $conversation
 */
class ChatbotMessage extends Model
{
    public const RESOURCE_NAME = 'chatbot_message';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /** The turn is finished and needs nothing from anybody. */
    public const STATUS_COMPLETE = 'complete';

    /** The assistant wants to run destructive tools and is waiting on the user. */
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /** The user refused the proposed tool calls. */
    public const STATUS_DENIED = 'denied';

    /** The turn ended in an error; the content holds the message shown to the user. */
    public const STATUS_FAILED = 'failed';

    protected $table = 'chatbot_messages';

    protected bool $immutableDates = true;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'id' => 'int',
        'conversation_id' => 'int',
        'tool_calls' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_COMPLETE,
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid',
        'conversation_id' => 'required|numeric|exists:chatbot_conversations,id',
        'role' => 'required|string|in:user,assistant,tool',
        'content' => 'nullable|string',
        'reasoning' => 'nullable|string',
        'tool_calls' => 'nullable|array',
        'tool_call_id' => 'nullable|string',
        'tool_name' => 'nullable|string',
        'status' => 'required|string',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    /**
     * The tool calls this message requested, as value objects.
     *
     * @return ToolCall[]
     */
    public function toolCalls(): array
    {
        return array_map(
            fn (array $call) => new ToolCall(
                id: (string) ($call['id'] ?? ''),
                name: (string) ($call['name'] ?? ''),
                arguments: (array) ($call['arguments'] ?? []),
            ),
            array_values(array_filter((array) $this->tool_calls, 'is_array')),
        );
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === self::STATUS_AWAITING_CONFIRMATION;
    }
}
