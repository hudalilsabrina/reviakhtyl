<?php

namespace App\Services\Chatbot;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\User;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The admin-scope assistant: conversations with no server, tools limited to
 * panel administration, offered to root administrators only.
 *
 * It shares the whole turn machinery with the server assistant through
 * ChatbotService — same history windowing, confirmation flow, compaction and
 * streaming. Only the scope differs: conversations carry a null server_id and
 * the tool registry is the admin one, so the orchestrating router (which is
 * server-bound) is always skipped in favour of the flat loop.
 */
class AdminChatbotService extends ChatbotService
{
    public function __construct(
        ChatbotSettings $settings,
        OpenAiClient $client,
        AdminToolRegistry $registry,
        ToolExecutor $executor,
        SystemPromptBuilder $promptBuilder,
        RoutingService $routing,
    ) {
        parent::__construct($settings, $client, $registry, $executor, $promptBuilder, $routing);
    }

    public function startAdminConversation(User $user): ChatbotConversation
    {
        $this->assertAdminEnabled();

        return ChatbotConversation::create([
            'uuid' => Str::uuid()->toString(),
            'server_id' => null,
            'user_id' => $user->id,
        ]);
    }

    /**
     * The panel tools this administrator may use, keyed by tool name.
     *
     * @return array<string, ChatbotTool>
     */
    public function adminToolsFor(User $user): array
    {
        return $this->registry()->availableFor(new ToolContext(null, $user));
    }

    /**
     * The admin assistant always runs the flat loop: orchestration's router and
     * sub-agents are bound to a specific server, which an admin conversation
     * has none of.
     *
     * @return Collection<int, ChatbotMessage>
     */
    protected function run(ChatbotConversation $conversation, ?callable $emit = null): Collection
    {
        return $this->runFlatLoop($conversation, $emit);
    }

    /**
     * @throws ChatbotException
     */
    protected function assertEnabled(): void
    {
        $this->assertAdminEnabled();
    }

    /**
     * @throws ChatbotException
     */
    private function assertAdminEnabled(): void
    {
        if (! $this->settings()->isAdminEnabled()) {
            throw new ChatbotException('The administrative assistant is not enabled on this panel.');
        }
    }
}
