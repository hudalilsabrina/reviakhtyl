<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Http\Requests\Api\Client\Servers\Chatbot\ConfirmChatActionRequest;
use App\Http\Requests\Api\Client\Servers\Chatbot\SendChatMessageRequest;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Server;
use App\Services\Chatbot\ChatbotService;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChatbotController extends ClientApiController
{
    public function __construct(private ChatbotService $service)
    {
        parent::__construct();
    }

    /**
     * Tells the client whether the assistant is usable here, and which tools
     * this particular user has access to on this server.
     */
    public function config(ClientApiRequest $request, Server $server): array
    {
        $settings = $this->service->settings();

        if (! $settings->isEnabled()) {
            return ['enabled' => false, 'model' => null, 'requires_confirmation' => true, 'tools' => []];
        }

        $tools = $this->service->toolsFor($server, $request->user());

        return [
            'enabled' => true,
            'model' => $settings->model(),
            'requires_confirmation' => $settings->requiresConfirmation(),
            'tools' => array_values(array_map(fn (ChatbotTool $tool) => [
                'name' => $tool->name(),
                'group' => $tool->group()->value,
                'description' => $tool->description(),
                'destructive' => $tool->isDestructive(),
            ], $tools)),
        ];
    }

    /**
     * Lists the requesting user's conversations on this server, newest first.
     */
    public function index(ClientApiRequest $request, Server $server): array
    {
        $conversations = ChatbotConversation::query()
            ->where('server_id', $server->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ['data' => $conversations->map(fn (ChatbotConversation $c) => $this->conversation($c))->all()];
    }

    /**
     * @throws ChatbotException
     */
    public function store(ClientApiRequest $request, Server $server): array
    {
        return ['data' => $this->conversation(
            $this->service->startConversation($server, $request->user())
        )];
    }

    public function view(ClientApiRequest $request, Server $server, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        return ['data' => array_merge(
            $this->conversation($chatbotConversation),
            ['messages' => $this->messages($chatbotConversation->messages()->get())],
        )];
    }

    /**
     * @throws ChatbotException
     */
    public function message(SendChatMessageRequest $request, Server $server, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $messages = $this->service->sendMessage($chatbotConversation, $request->input('content'));

        return ['data' => ['messages' => $this->messages($messages)]];
    }

    /**
     * Approves or refuses the tool calls the assistant is waiting on.
     *
     * @throws ChatbotException
     */
    public function confirm(ConfirmChatActionRequest $request, Server $server, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $message = $chatbotConversation->messages()
            ->where('uuid', $request->input('message_uuid'))
            ->first();

        if (! $message) {
            throw new NotFoundHttpException('The requested message was not found in this conversation.');
        }

        $messages = $this->service->resolveConfirmation($chatbotConversation, $message, $request->boolean('approved'));

        // The message that was awaiting a decision is returned first: its status
        // and per-call outcomes have changed, so the client replaces its copy.
        return ['data' => ['messages' => $this->messages(
            collect([$message->refresh()])->concat($messages)
        )]];
    }

    public function delete(ClientApiRequest $request, Server $server, ChatbotConversation $chatbotConversation): Response
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $chatbotConversation->delete();

        return $this->returnNoContent();
    }

    /**
     * A conversation belongs to one user: a subuser must not be able to read
     * the server owner's chats even though they can see the server.
     */
    private function authorizeConversation(ClientApiRequest $request, ChatbotConversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('The requested conversation was not found for this server.');
        }
    }

    private function conversation(ChatbotConversation $conversation): array
    {
        return [
            'uuid' => $conversation->uuid,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at->toIso8601String(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * Shapes messages for the client. Tool messages are internal bookkeeping —
     * the client sees their outcome on the assistant message that requested them.
     *
     * @param  Collection<int, ChatbotMessage>  $messages
     */
    private function messages(Collection $messages): array
    {
        return $messages
            ->reject(fn (ChatbotMessage $message) => $message->role === ChatbotMessage::ROLE_TOOL)
            ->map(fn (ChatbotMessage $message) => [
                'uuid' => $message->uuid,
                'role' => $message->role,
                'content' => $message->content,
                'reasoning' => $message->reasoning,
                'status' => $message->status,
                'tool_calls' => collect($message->tool_calls ?? [])->map(fn (array $call) => [
                    'id' => $call['id'] ?? '',
                    'name' => $call['name'] ?? '',
                    'summary' => $call['summary'] ?? ($call['name'] ?? ''),
                    'destructive' => (bool) ($call['destructive'] ?? false),
                    'status' => $call['status'] ?? 'pending',
                    'ok' => $call['ok'] ?? null,
                ])->values()->all(),
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
