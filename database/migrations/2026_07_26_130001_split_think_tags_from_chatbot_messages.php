<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves inline `<think>…</think>` blocks out of stored assistant content and
 * into the `reasoning` column.
 *
 * Messages written before reasoning was handled separately have the model's
 * chain-of-thought embedded in the answer, which the chat page would keep
 * rendering verbatim. The regex is duplicated from ChatCompletion rather than
 * called through it so this migration keeps working if that class changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chatbot_messages')
            ->where('role', 'assistant')
            ->where('content', 'like', '%<think>%')
            ->orderBy('id')
            ->each(function ($message) {
                preg_match_all('/<think>(.*?)<\/think>/s', $message->content, $matches);
                $thoughts = array_map('trim', $matches[1]);

                $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $message->content);

                // An unterminated block means the model was cut off mid-thought.
                if (str_contains($content, '<think>')) {
                    [$content, $unterminated] = explode('<think>', $content, 2);
                    $thoughts[] = trim($unterminated);
                }

                $thoughts = array_values(array_filter($thoughts, fn (string $t) => $t !== ''));
                $content = trim($content);

                DB::table('chatbot_messages')->where('id', $message->id)->update([
                    'content' => $content === '' ? null : $content,
                    'reasoning' => $thoughts === [] ? null : implode("\n\n", $thoughts),
                ]);
            });
    }

    /**
     * Re-embeds the reasoning so the rows look exactly as they did before.
     */
    public function down(): void
    {
        DB::table('chatbot_messages')
            ->where('role', 'assistant')
            ->whereNotNull('reasoning')
            ->orderBy('id')
            ->each(function ($message) {
                DB::table('chatbot_messages')->where('id', $message->id)->update([
                    'content' => '<think>'.$message->reasoning.'</think>'.$message->content,
                    'reasoning' => null,
                ]);
            });
    }
};
