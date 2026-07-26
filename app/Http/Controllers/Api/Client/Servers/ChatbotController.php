<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
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
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * The same exchange as message(), delivered as server-sent events.
     *
     * A turn that chains tool calls occupies a single request for anything up to
     * a few minutes; without this the user watches a spinner the whole time.
     * The final `done` event carries the authoritative message list so the client
     * reconciles against stored state rather than trusting what it accumulated.
     */
    public function stream(SendChatMessageRequest $request, Server $server, ChatbotConversation $chatbotConversation): StreamedResponse
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $content = $request->input('content');

        return $this->sse(
            $chatbotConversation,
            fn (callable $emit) => $this->service->sendMessage($chatbotConversation, $content, $emit),
        );
    }

    /**
     * Approving a destructive action re-enters the same loop, so it can take just
     * as long as sending a message and deserves the same live feedback.
     */
    public function confirmStream(ConfirmChatActionRequest $request, Server $server, ChatbotConversation $chatbotConversation): StreamedResponse
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $message = $chatbotConversation->messages()
            ->where('uuid', $request->input('message_uuid'))
            ->first();

        if (! $message) {
            throw new NotFoundHttpException('The requested message was not found in this conversation.');
        }

        $approved = $request->boolean('approved');

        return $this->sse($chatbotConversation, function (callable $emit) use ($chatbotConversation, $message, $approved) {
            $messages = $this->service->resolveConfirmation($chatbotConversation, $message, $approved, $emit);

            // The resolved message leads the authoritative list, as it does on the
            // blocking endpoint: its status and per-call outcomes have changed.
            return collect([$message->refresh()])->concat($messages);
        });
    }

    /**
     * Runs $work as an event stream, giving it an emitter and closing with the
     * turn's status and the authoritative message list.
     *
     * Both streaming endpoints share this so there is one place that knows the
     * framing, the flushing and how a mid-stream failure is reported.
     *
     * @param  \Closure(callable): Collection<int, ChatbotMessage>  $work
     */
    private function sse(ChatbotConversation $conversation, \Closure $work): StreamedResponse
    {
        return response()->stream(function () use ($conversation, $work) {
            $emit = function (string $event, array $data) {
                if ($event === 'message') {
                    $data['message'] = $this->messages(collect([$data['message']]))[0] ?? null;
                }

                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data)."\n\n";

                // Nothing downstream should buffer this, but PHP's own buffers
                // will hold the turn back on their own if not pushed each time.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            try {
                $messages = $work($emit);

                $emit('status', ['status' => $this->service->pendingConfirmation($conversation)
                    ? ChatbotMessage::STATUS_AWAITING_CONFIRMATION
                    : ChatbotMessage::STATUS_COMPLETE,
                ]);

                $emit('done', ['messages' => $this->messages($messages)]);
            } catch (\Throwable $e) {
                // The stream has already sent 200, so a failure can only be
                // reported in-band. DisplayException messages are written for
                // users; anything else would leak internals.
                Log::warning('Chatbot stream failed', ['error' => $e->getMessage()]);

                $emit('error', [
                    'message' => $e instanceof DisplayException
                        ? $e->getMessage()
                        : 'The assistant could not finish this response.',
                ]);
                $emit('done', ['messages' => []]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // nginx buffers proxied responses by default, which would hold every
            // event until the turn ended and defeat the point of streaming.
            'X-Accel-Buffering' => 'no',
        ]);
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
