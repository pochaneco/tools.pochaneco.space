<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\MessageRole;
use App\Models\Message;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message as SdkMessage;
use Laravel\Ai\Messages\UserMessage;
use Throwable;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Trims prior conversation turns so the prompt we send to the model fits
 * inside its context window, preserving the latest messages and falling
 * back to cheap length approximations when the tokenizer is unavailable
 * (e.g. CI without network access to OpenAI's vocab files).
 *
 * The strategy is intentionally simple: walk the history from newest to
 * oldest, accumulating tokens until the per-model budget is exhausted, and
 * drop anything older than that. The system prompt lives outside this
 * collection and is always sent by the controller, so we only reserve a
 * fixed headroom for it here.
 */
class HistoryTruncator
{
    /**
     * Tokens withheld from the budget to leave room for the model's
     * response. Completion tokens count against the same context window,
     * so we must reserve a generous slice up-front.
     */
    public const RESERVE_FOR_COMPLETION = 4_000;

    /**
     * Upper bound on system prompt tokens assumed by the budget math.
     * The real system prompt is short today (one sentence) but we keep a
     * conservative padding so tweaks don't accidentally blow the budget.
     */
    public const SYSTEM_PROMPT_BUDGET = 500;

    /**
     * Encoding used for approximation. `cl100k_base` is close enough for
     * Sakura-hosted models (Qwen / llm-jp / gpt-oss) — expected error is
     * 5-10%, well within the buffer we reserve above.
     */
    private const ENCODING = 'cl100k_base';

    public function __construct(
        private readonly EncoderProvider $encoders,
    ) {}

    /**
     * Fit the message history into the model's context budget.
     *
     * @param  iterable<Message>  $messages  Persisted history in chronological order.
     */
    public function truncate(
        iterable $messages,
        string $model,
        string $systemPrompt,
    ): TruncationResult {
        // Normalise to a Collection so callers can pass Eloquent queries,
        // plain arrays, or Support collections interchangeably.
        $history = Collection::make($messages)->values();

        $maxTokens = $this->maxTokensFor($model);
        $systemTokens = max(
            self::SYSTEM_PROMPT_BUDGET,
            $this->count($systemPrompt),
        );
        $budget = $maxTokens - self::RESERVE_FOR_COMPLETION - $systemTokens;

        if ($budget <= 0 || $history->isEmpty()) {
            return new TruncationResult(
                keptMessages: [],
                droppedCount: $history->count(),
                tokensUsed: 0,
            );
        }

        // Walk newest -> oldest so the most recent turns are guaranteed
        // to survive even if older turns are huge.
        $reversed = $history->reverse()->values();
        $kept = [];
        $tokensUsed = 0;

        foreach ($reversed as $message) {
            /** @var Message $message */
            $tokens = $this->count((string) $message->content);

            if ($tokensUsed + $tokens > $budget && $kept !== []) {
                break;
            }

            // Accept the latest message even when it *alone* exceeds the
            // budget — we can't honor "always keep the most recent turn"
            // otherwise. Provider-side limits will catch it if it's truly
            // catastrophic, which is better than silently dropping the
            // user's just-sent question.
            $kept[] = $message;
            $tokensUsed += $tokens;

            if ($tokensUsed >= $budget) {
                break;
            }
        }

        $keptMessages = array_reverse($kept);
        $droppedCount = $history->count() - count($keptMessages);

        return new TruncationResult(
            keptMessages: array_values(array_map(
                fn (Message $m) => $this->toSdkMessage($m),
                $keptMessages,
            )),
            droppedCount: $droppedCount,
            tokensUsed: $tokensUsed,
        );
    }

    /**
     * Resolve the model's configured context window, falling back to the
     * app-wide default when the id is unknown (e.g. a stale selector or
     * an experimental model not yet catalogued).
     */
    private function maxTokensFor(string $model): int
    {
        $configured = config("ai.chat_models.{$model}.max_context_tokens");

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        $fallback = config('ai.chat_fallback_max_context_tokens', 32_000);

        return is_int($fallback) && $fallback > 0 ? $fallback : 32_000;
    }

    /**
     * Approximate the token count of a string via tiktoken. When the
     * vocab file isn't available (offline test runners, first boot before
     * download) we fall back to a crude character-based estimate instead
     * of bubbling an exception — truncation should never break the
     * primary chat flow. Marked {@see protected} so test doubles can
     * substitute a deterministic counter.
     */
    protected function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        try {
            $encoder = $this->encoders->get(self::ENCODING);

            return count($encoder->encode($text));
        } catch (Throwable) {
            // ~4 characters per token is the widely cited OpenAI heuristic.
            // Mixed-script (Japanese) content is usually denser, so we
            // round up with ceil() to stay on the safe side.
            return (int) ceil(mb_strlen($text) / 4);
        }
    }

    /**
     * Translate a persisted {@see Message} into the SDK's wire format.
     * System messages collapse to user messages because the SDK already
     * handles the real system prompt via the agent's `instructions`.
     */
    private function toSdkMessage(Message $message): SdkMessage
    {
        $role = $message->role instanceof MessageRole
            ? $message->role
            : MessageRole::tryFrom((string) $message->role);

        return match ($role) {
            MessageRole::Assistant => new AssistantMessage((string) $message->content),
            default => new UserMessage((string) $message->content),
        };
    }
}
