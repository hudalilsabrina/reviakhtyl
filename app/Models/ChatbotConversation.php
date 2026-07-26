<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A single chat thread between one user and the assistant on one server.
 *
 * The `uuid` is supplied by the creating service rather than a model hook: the
 * base model validates on `saving`, which fires before `creating` would run.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $summary
 * @property int|null $summary_through_id
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Server $server
 * @property User $user
 * @property ChatbotMessage[] $messages
 */
class ChatbotConversation extends Model
{
    public const RESOURCE_NAME = 'chatbot_conversation';

    protected $table = 'chatbot_conversations';

    protected bool $immutableDates = true;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'id' => 'int',
        'server_id' => 'int',
        'user_id' => 'int',
        'summary_through_id' => 'int',
        'last_message_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid',
        'server_id' => 'required|numeric|exists:servers,id',
        'user_id' => 'required|numeric|exists:users,id',
        'title' => 'nullable|string|max:191',
        'summary' => 'nullable|string',
        'summary_through_id' => 'nullable|numeric',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChatbotMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id')->orderBy('id');
    }

    /**
     * Derives a thread title from its first user message, so the conversation
     * list is readable without asking the model for a summary.
     */
    public function titleFrom(string $message): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $message)) ?: 'New conversation', 60);
    }
}
