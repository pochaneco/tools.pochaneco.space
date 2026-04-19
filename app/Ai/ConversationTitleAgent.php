<?php

namespace App\Ai;

use App\Jobs\GenerateConversationTitle;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * Agent used by {@see GenerateConversationTitle} to summarise the
 * first user message of a conversation into a very short title.
 *
 * The agent is intentionally cheap: it asks the model for at most a handful
 * of words and caps generation at 20 tokens so that title generation never
 * contributes meaningful cost, even if the model happens to ignore the
 * natural-language instruction.
 */
#[Provider('sakura')]
#[MaxTokens(20)]
class ConversationTitleAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public const INSTRUCTIONS = 'Summarize the following user question in 5 words or less, in Japanese. Output only the title, no quotes or punctuation.';

    public function instructions(): string
    {
        return self::INSTRUCTIONS;
    }

    /**
     * @return iterable<int, Message>
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return iterable<int, mixed>
     */
    public function tools(): iterable
    {
        return [];
    }
}
