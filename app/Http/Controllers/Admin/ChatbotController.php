<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DisplayException;
use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\Chatbot\AdminChatbotService;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Session-authenticated JSON API backing the admin panel's chatbot page.
 *
 * The shape of every payload mirrors the client chat API so the same
 * conversation flow (streaming events, confirmation decisions, message
 * shapes) applies. Only root administrators can reach these routes; every
 * conversation here is admin-scoped (server_id null) and private to the
 * administrator who started it.
 */
class ChatbotController extends Controller
{
    public function __construct(private AdminChatbotService $service) {}

    public function config(Request $request): array
    {
        $settings = $this->service->settings();

        if (! $settings->isAdminEnabled()) {
            return ['enabled' => false, 'model' => null, 'requires_confirmation' => true, 'tools' => []];
        }

        $tools = $this->service->adminToolsFor($request->user());

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
     * The requesting administrator's conversations, newest first.
     */
    public function index(Request $request): array
    {
        $conversations = $this->forUser($request)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ['data' => $conversations->map(fn (ChatbotConversation $c) => $this->conversation($c))->all()];
    }

    public function store(Request $request): array
    {
        return ['data' => $this->conversation(
            $this->service->startAdminConversation($request->user())
        )];
    }

    public function view(Request $request, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        return ['data' => array_merge(
            $this->conversation($chatbotConversation),
            ['messages' => $this->messages($chatbotConversation->messages()->get())],
        )];
    }

    public function message(Request $request, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $validated = $request->validate(['content' => 'required|string|min:1|max:8000']);

        $messages = $this->service->sendMessage($chatbotConversation, $validated['content']);

        return ['data' => ['messages' => $this->messages($messages)]];
    }

    public function stream(Request $request, ChatbotConversation $chatbotConversation): StreamedResponse
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $validated = $request->validate(['content' => 'required|string|min:1|max:8000']);

        return $this->sse(
            $chatbotConversation,
            fn (callable $emit) => $this->service->sendMessage($chatbotConversation, $validated['content'], $emit),
        );
    }

    public function confirm(Request $request, ChatbotConversation $chatbotConversation): array
    {
        $this->authorizeConversation($request, $chatbotConversation);

        [$message, $decisions] = $this->resolveConfirmationInputs($request, $chatbotConversation);

        $messages = $this->service->resolveConfirmation($chatbotConversation, $message, $decisions);

        return ['data' => ['messages' => $this->messages(
            collect([$message->refresh()])->concat($messages)
        )]];
    }

    public function confirmStream(Request $request, ChatbotConversation $chatbotConversation): StreamedResponse
    {
        $this->authorizeConversation($request, $chatbotConversation);

        [$message, $decisions] = $this->resolveConfirmationInputs($request, $chatbotConversation);

        return $this->sse($chatbotConversation, function (callable $emit) use ($chatbotConversation, $message, $decisions) {
            $messages = $this->service->resolveConfirmation($chatbotConversation, $message, $decisions, $emit);

            return collect([$message->refresh()])->concat($messages);
        });
    }

    public function delete(Request $request, ChatbotConversation $chatbotConversation): Response
    {
        $this->authorizeConversation($request, $chatbotConversation);

        $chatbotConversation->delete();

        return response()->noContent();
    }

    /**
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
                Log::warning('Admin chatbot stream failed', ['error' => $e->getMessage()]);

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
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array{0: ChatbotMessage, 1: array<string, bool>}
     */
    private function resolveConfirmationInputs(Request $request, ChatbotConversation $conversation): array
    {
        $validated = $request->validate([
            'message_uuid' => 'required|uuid',
            'decisions' => 'required|array|min:1',
            'decisions.*.id' => 'required|string',
            'decisions.*.approved' => 'required|boolean',
            'confirmation' => 'nullable|string|max:64',
        ]);

        $message = $conversation->messages()->where('uuid', $validated['message_uuid'])->first();

        if (! $message) {
            throw new NotFoundHttpException('The requested message was not found in this conversation.');
        }

        $confirmation = trim(strtolower((string) ($validated['confirmation'] ?? '')));

        $decisions = [];

        foreach ($validated['decisions'] as $decision) {
            $id = (string) ($decision['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $approved = (bool) ($decision['approved'] ?? false);
            $decisions[$id] = $approved;

            // Destructive admin tools must be confirmed by typing the tool's
            // verb. This is enforced server-side so a direct API call cannot
            // bypass the UI's typed-confirmation gate.
            if ($approved && $this->isDestructiveCall($request, $message, $id)) {
                $verb = $this->destructiveVerb($request, $message, $id);

                if ($verb === null || $confirmation !== $verb) {
                    throw new DisplayException('Destructive actions require typing the confirmation verb to approve.');
                }
            }
        }

        return [$message, $decisions];
    }

    /**
     * Whether the named pending call on the message is a destructive admin tool.
     */
    private function isDestructiveCall(Request $request, ChatbotMessage $message, string $callId): bool
    {
        return $this->destructiveVerb($request, $message, $callId) !== null;
    }

    /**
     * The confirmation verb an admin must type to approve the given pending
     * destructive call, or null when the call is not a destructive admin tool.
     */
    private function destructiveVerb(Request $request, ChatbotMessage $message, string $callId): ?string
    {
        foreach ($message->tool_calls ?? [] as $call) {
            if ((string) ($call['id'] ?? '') !== $callId) {
                continue;
            }

            $name = (string) ($call['name'] ?? '');

            foreach ($this->service->adminToolsFor($request->user()) as $tool) {
                if ($tool->name() === $name && $tool->isDestructive()) {
                    return str_replace('_', ' ', $tool->name());
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return Builder<ChatbotConversation>
     */
    private function forUser(Request $request): Builder
    {
        return ChatbotConversation::query()
            ->where('server_id', null)
            ->where('user_id', $request->user()->id);
    }

    private function authorizeConversation(Request $request, ChatbotConversation $conversation): void
    {
        if ($conversation->server_id !== null || $conversation->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('The requested conversation was not found.');
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
     * @param  Collection<int, ChatbotMessage>  $messages
     */
    private function messages(Collection $messages): array
    {
        $results = $messages
            ->filter(fn (ChatbotMessage $m) => $m->role === ChatbotMessage::ROLE_TOOL)
            ->mapWithKeys(fn (ChatbotMessage $m) => [
                $m->tool_call_id => is_array($m->content) ? $m->content : null,
            ]);

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
                    'arguments' => $call['arguments'] ?? [],
                    'summary' => $call['summary'] ?? ($call['name'] ?? ''),
                    'destructive' => (bool) ($call['destructive'] ?? false),
                    'status' => $call['status'] ?? 'pending',
                    'ok' => $call['ok'] ?? null,
                    'result' => $results->get($call['id'] ?? ''),
                ])->values()->all(),
                'created_at' => $message->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
