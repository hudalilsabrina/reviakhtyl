<?php

namespace App\Services\Chatbot;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Validates, authorizes and runs a single tool call, always returning a result
 * the model can read — including for failures, so a bad call becomes feedback
 * rather than a dead conversation.
 */
class ToolExecutor
{
    /**
     * Tool output larger than this is truncated before it goes back to the
     * model, to keep a single directory listing or file read from consuming the
     * entire context window.
     */
    private const MAX_RESULT_LENGTH = 12000;

    /**
     * Argument values longer than this are replaced by a placeholder in the
     * activity log. Long enough for a path or a console command, far too short
     * for a file body.
     */
    private const MAX_LOGGED_VALUE_LENGTH = 512;

    public function __construct(private ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(ToolContext $context, string $name, array $arguments): array
    {
        $tool = $this->registry->resolveFor($context, $name);

        if (! $tool) {
            return $this->error("The tool \"$name\" is not available on this server for this user.");
        }

        // Re-check permissions at call time: the conversation may have been
        // started before a subuser's access was narrowed.
        if (! $tool->isAvailableFor($context)) {
            return $this->error('You do not have permission to perform this action on this server.');
        }

        $rules = $tool->rules();

        if ($rules !== []) {
            $validator = Validator::make($arguments, $rules);

            if ($validator->fails()) {
                return $this->error(
                    'Invalid arguments: '.implode(' ', $validator->errors()->all()),
                );
            }

            $arguments = array_replace($arguments, $validator->validated());
        }

        try {
            $result = $tool->handle($context, $arguments);
        } catch (\Throwable $e) {
            Log::warning('Chatbot tool execution failed', [
                'tool' => $name,
                'server' => $context->server->uuid,
                'exception' => $e,
            ]);

            // Only messages written to be shown to a user are passed on; a raw
            // database or filesystem error would otherwise land in both the
            // model's context and the user's chat window. DisplayException
            // subclasses are expected to keep their messages presentable —
            // DaemonConnectionException, for instance, sanitizes the node
            // address out before it ever reaches here.
            return $this->error($e instanceof DisplayException
                ? $e->getMessage()
                : 'The action could not be completed because of an internal error. It has been logged for the panel administrator.');
        }

        $this->logActivity($context, $tool, $arguments);

        return $this->truncate(array_merge(['ok' => true], $result));
    }

    /**
     * Records the tool call against the server's activity log so a panel
     * administrator can audit anything the assistant did.
     */
    private function logActivity(ToolContext $context, ChatbotTool $tool, array $arguments): void
    {
        try {
            Activity::event('server:chatbot.tool')
                ->actor($context->user)
                ->subject($context->server)
                ->property([
                    'tool' => $tool->name(),
                    'group' => $tool->group()->value,
                    'arguments' => $this->redact($arguments),
                ])
                ->log();
        } catch (\Throwable $e) {
            // Never let an audit-log failure break the conversation.
            Log::warning('Failed to log chatbot activity', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Trims bulky argument values before they are written to the activity log.
     *
     * Activity properties are returned in full to anyone holding activity.read,
     * so logging arguments verbatim would put the entire body of a written file
     * in front of a subuser who does not even have file.read-content. The
     * panel's own file endpoints log the path and never the contents.
     */
    private function redact(array $arguments): array
    {
        return array_map(function ($value) {
            if (is_string($value) && strlen($value) > self::MAX_LOGGED_VALUE_LENGTH) {
                return '('.strlen($value).' characters omitted)';
            }

            if (is_array($value)) {
                return $this->redact($value);
            }

            return $value;
        }, $arguments);
    }

    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function truncate(array $result): array
    {
        $encoded = json_encode($result);

        if ($encoded === false || strlen($encoded) <= self::MAX_RESULT_LENGTH) {
            return $result;
        }

        foreach (['content', 'output', 'files', 'entries'] as $key) {
            if (! isset($result[$key])) {
                continue;
            }

            if (is_string($result[$key])) {
                $result[$key] = substr($result[$key], 0, self::MAX_RESULT_LENGTH);
                $result['truncated'] = true;

                return $result;
            }

            if (is_array($result[$key])) {
                $result[$key] = array_slice($result[$key], 0, 100);
                $result['truncated'] = true;

                return $result;
            }
        }

        return [
            'ok' => $result['ok'] ?? true,
            'truncated' => true,
            'note' => 'The result was too large to return in full. Narrow the request and try again.',
        ];
    }
}
