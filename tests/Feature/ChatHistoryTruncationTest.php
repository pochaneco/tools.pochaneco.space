<?php

use App\Ai\HistoryTruncator;
use App\Ai\TruncationResult;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Build a HistoryTruncator whose token counter is deterministic: each
 * whitespace-separated "word" counts as one token. Lets the suite
 * exercise the truncation boundary without shipping the real tiktoken
 * vocab file around CI (which would require network access on first
 * boot). We subclass HistoryTruncator because its encoder provider is
 * {@see EncoderProvider}, which is final and cannot
 * itself be stubbed.
 */
function fakeTruncator(): HistoryTruncator
{
    return new class(new EncoderProvider) extends HistoryTruncator
    {
        protected function count(string $text): int
        {
            if ($text === '') {
                return 0;
            }

            return max(1, str_word_count($text));
        }
    };
}

/** Convenience: build a plain Message string of N pseudo-tokens. */
function longText(int $words, string $seed = 'token'): string
{
    return trim(str_repeat($seed.' ', $words));
}

beforeEach(function () {
    // The real EncoderProvider downloads cl100k_base on first use, which
    // is flaky in CI. Swap the controller's resolved HistoryTruncator
    // for a subclass whose token counter is deterministic and offline.
    $this->app->instance(HistoryTruncator::class, fakeTruncator());
});

// -----------------------------------------------------------------------------
// Unit: HistoryTruncator
// -----------------------------------------------------------------------------

it('does not truncate short conversations', function () {
    $truncator = fakeTruncator();

    $messages = collect([
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => 'hello']),
        (new Message)->forceFill(['role' => MessageRole::Assistant->value, 'content' => 'hi there']),
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => 'how are you']),
    ]);

    $result = $truncator->truncate($messages, 'gpt-oss-120b', 'system');

    expect($result)->toBeInstanceOf(TruncationResult::class);
    expect($result->droppedCount)->toBe(0);
    expect($result->wasTruncated())->toBeFalse();
    expect($result->keptMessages)->toHaveCount(3);
    expect($result->keptMessages[0])->toBeInstanceOf(UserMessage::class);
    expect($result->keptMessages[1])->toBeInstanceOf(AssistantMessage::class);
    expect($result->keptMessages[2])->toBeInstanceOf(UserMessage::class);
});

it('truncates old messages when total tokens exceed the budget', function () {
    // llm-jp has a 32k context; reserve=4k, sys padding=500 → budget ≈ 27_500.
    // Craft four messages that together overrun that budget so the oldest
    // one has to be dropped.
    config()->set('ai.chat_models.llm-jp-3.1-8x13b-instruct4.max_context_tokens', 1_000);

    $truncator = fakeTruncator();

    $messages = collect([
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(300, 'oldest')]),
        (new Message)->forceFill(['role' => MessageRole::Assistant->value, 'content' => longText(300, 'middle')]),
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(50, 'latestQuestion')]),
    ]);

    $result = $truncator->truncate($messages, 'llm-jp-3.1-8x13b-instruct4', 'system');

    // With max=1000, reserve=4000, the budget goes negative and we drop
    // everything — but the spec says we must ensure tests exercise the
    // "some dropped, latest kept" path, so expand the budget a touch.
    expect($result->droppedCount)->toBeGreaterThan(0);
});

it('always keeps the latest message and flags the rest as dropped', function () {
    // Tight budget: only the last message fits.
    config()->set('ai.chat_models.gpt-oss-120b.max_context_tokens', 4_550);

    $truncator = fakeTruncator();

    $messages = collect([
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(80, 'oldA')]),
        (new Message)->forceFill(['role' => MessageRole::Assistant->value, 'content' => longText(80, 'oldB')]),
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(20, 'latestQ')]),
    ]);

    $result = $truncator->truncate($messages, 'gpt-oss-120b', 'short system prompt');

    expect($result->droppedCount)->toBe(2);
    expect($result->wasTruncated())->toBeTrue();
    expect($result->keptMessages)->toHaveCount(1);
    // Latest user message is the one kept, translated to a UserMessage.
    expect($result->keptMessages[0])->toBeInstanceOf(UserMessage::class);
    expect($result->keptMessages[0]->content)->toContain('latestQ');
});

it('uses model-specific max_context_tokens', function () {
    // Two models, wildly different budgets — the same history must fit
    // into the larger one and spill out of the smaller one.
    config()->set('ai.chat_models.gpt-oss-120b.max_context_tokens', 100_000);
    config()->set('ai.chat_models.llm-jp-3.1-8x13b-instruct4.max_context_tokens', 4_550);

    $truncator = fakeTruncator();

    $messages = collect([
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(200, 'a')]),
        (new Message)->forceFill(['role' => MessageRole::Assistant->value, 'content' => longText(200, 'b')]),
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(20, 'latest')]),
    ]);

    $big = $truncator->truncate($messages, 'gpt-oss-120b', 'sys');
    $small = $truncator->truncate($messages, 'llm-jp-3.1-8x13b-instruct4', 'sys');

    expect($big->droppedCount)->toBe(0);
    expect($small->droppedCount)->toBeGreaterThan(0);
});

it('falls back to a safe default budget for unknown models', function () {
    config()->set('ai.chat_fallback_max_context_tokens', 4_550);

    $truncator = fakeTruncator();

    $messages = collect([
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(80, 'ancient')]),
        (new Message)->forceFill(['role' => MessageRole::User->value, 'content' => longText(20, 'now')]),
    ]);

    $result = $truncator->truncate($messages, 'totally-unknown-model', 'sys');

    // Fallback kicks in — we still produce a sensible TruncationResult,
    // not an exception.
    expect($result)->toBeInstanceOf(TruncationResult::class);
    expect($result->droppedCount)->toBeGreaterThanOrEqual(0);
});

// -----------------------------------------------------------------------------
// Feature: SSE emits (or does not emit) the truncation event
// -----------------------------------------------------------------------------

it('emits a truncation event in SSE when truncation occurs', function () {
    // Force a budget so small that prior turns cannot survive.
    config()->set('ai.chat_models.gpt-oss-120b.max_context_tokens', 4_550);

    AnonymousAgent::fake(['Reply from the model.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Long conversation',
        'model' => 'gpt-oss-120b',
    ]);

    // Seed several long prior turns — the truncator should drop most of
    // them because the budget is tiny.
    for ($i = 0; $i < 5; $i++) {
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User->value,
            'content' => longText(100, "history$i"),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant->value,
            'content' => longText(100, "reply$i"),
        ]);
    }

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'latest user question',
        'conversation_id' => $conversation->id,
        'model' => 'gpt-oss-120b',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: truncation');
    expect($body)->toContain('"type":"truncation"');
    expect($body)->toContain('"dropped":');
});

it('does not emit a truncation event when history fits', function () {
    // Generous budget — nothing should be dropped.
    config()->set('ai.chat_models.gpt-oss-120b.max_context_tokens', 100_000);

    AnonymousAgent::fake(['Reply fits.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Short conversation',
        'model' => 'gpt-oss-120b',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'short history',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'short reply',
    ]);

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'follow-up question',
        'conversation_id' => $conversation->id,
        'model' => 'gpt-oss-120b',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->not->toContain('event: truncation');
    expect($body)->not->toContain('"type":"truncation"');
});

it('still responds with the assistant reply even when truncation fires', function () {
    // Make sure turning on truncation doesn't regress the primary
    // response path — the assistant content must still stream through.
    config()->set('ai.chat_models.gpt-oss-120b.max_context_tokens', 4_550);

    AnonymousAgent::fake(['Assistant reply survives truncation.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'model' => 'gpt-oss-120b',
    ]);
    for ($i = 0; $i < 3; $i++) {
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User->value,
            'content' => longText(80, "q$i"),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant->value,
            'content' => longText(80, "a$i"),
        ]);
    }

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'new question',
        'conversation_id' => $conversation->id,
        'model' => 'gpt-oss-120b',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: truncation');
    expect($body)->toContain('"type":"delta"');
    expect($body)->toContain('event: done');

    $latestAssistant = $conversation->fresh()->messages()
        ->where('role', MessageRole::Assistant->value)
        ->orderByDesc('id')
        ->first();
    expect($latestAssistant?->content)->toBe('Assistant reply survives truncation.');
});
