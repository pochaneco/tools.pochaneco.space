<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Messages\Message as SdkMessage;

/**
 * Immutable outcome of a {@see HistoryTruncator::truncate()} call.
 *
 * Holds the SDK-ready message list that fit into the model budget, plus
 * bookkeeping about how many older turns had to be dropped and the
 * estimated token count of what survived. The controller consumes this
 * both to build the `AnonymousAgent` context and to decide whether the
 * SSE stream needs to emit a `truncation` notice to the browser.
 */
final readonly class TruncationResult
{
    /**
     * @param  array<int, SdkMessage>  $keptMessages  SDK message objects the agent will actually see.
     * @param  int  $droppedCount  Number of oldest messages excluded from context.
     * @param  int  $tokensUsed  Approximate prompt tokens used by $keptMessages.
     */
    public function __construct(
        public array $keptMessages,
        public int $droppedCount,
        public int $tokensUsed,
    ) {}

    public function wasTruncated(): bool
    {
        return $this->droppedCount > 0;
    }
}
