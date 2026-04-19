<?php

namespace App\Jobs;

use App\Ai\ConversationTitleAgent;
use App\Enums\MessageRole;
use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Replace a conversation's placeholder title (the first user message truncated
 * to 60 chars) with a concise AI-generated summary.
 *
 * The job is dispatched from {@see ChatController::message()}
 * right after the first assistant reply has been persisted, and is intentionally
 * best-effort: if the AI call fails for any reason we keep the placeholder
 * title rather than bubble the failure up to the user.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Do not retry. A failing title generation is not worth blocking the
     * queue or spending additional AI tokens on — the UI already has a
     * sensible placeholder title.
     */
    public int $tries = 1;

    /**
     * The AI call itself is cheap and fast, but give it a bit of headroom in
     * case the upstream provider is slow.
     */
    public int $timeout = 30;

    /**
     * Upper bound for the stored title. Prevents runaway output from a
     * misbehaving model from ballooning the conversations list UI.
     */
    private const TITLE_MAX_LEN = 60;

    public function __construct(public Conversation $conversation) {}

    public function handle(): void
    {
        // Re-fetch the conversation so we see any updates made after the
        // job was queued (e.g. the user renaming it manually).
        $conversation = Conversation::query()
            ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
            ->find($this->conversation->id);

        if ($conversation === null) {
            return;
        }

        $firstUserMessage = $conversation->messages
            ->firstWhere('role', MessageRole::User);

        if ($firstUserMessage === null) {
            return;
        }

        $agent = new ConversationTitleAgent;

        $response = $agent->prompt(
            $firstUserMessage->content,
            model: config('ai.default_chat_model'),
        );

        $title = $this->normaliseTitle((string) $response);

        if ($title === '') {
            return;
        }

        $conversation->update(['title' => $title]);
    }

    /**
     * Swallow failures intentionally. The UI has a usable placeholder title
     * already, so the only action left is to log for observability.
     */
    public function failed(Throwable $e): void
    {
        Log::warning('GenerateConversationTitle failed', [
            'conversation_id' => $this->conversation->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * Clean up whatever the model returned so it is safe to show in the UI.
     */
    private function normaliseTitle(string $raw): string
    {
        $title = trim($raw);

        if ($title === '') {
            return '';
        }

        // Strip common quote-like characters the model tends to wrap output in
        // despite being told not to.
        $title = str_replace(['"', "'", '「', '」', '『', '』', '“', '”'], '', $title);

        // Collapse any whitespace (including internal newlines) to single
        // spaces so the sidebar renders on a single line.
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return (string) Str::of($title)->limit(self::TITLE_MAX_LEN, '');
    }
}
