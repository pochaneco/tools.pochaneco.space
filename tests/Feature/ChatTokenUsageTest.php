<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Laravel\Ai\AnonymousAgent;

it('returns cumulative usage totals on the conversation show endpoint', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'first question',
    ]);
    Message::factory()->for($conversation)
        ->assistant()
        ->withTokens(prompt: 1200, completion: 800)
        ->create(['content' => 'first answer']);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'second question',
    ]);
    Message::factory()->for($conversation)
        ->assistant()
        ->withTokens(prompt: 50, completion: 80)
        ->create(['content' => 'second answer']);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', $conversation));

    $response->assertOk();
    $response->assertJsonPath('usage.prompt', 1250);
    $response->assertJsonPath('usage.completion', 880);
    $response->assertJsonPath('usage.total', 2130);
});

it('reports zero usage for conversations without assistant messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();

    // Only a user message, no assistant reply yet — token counts are NULL.
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'just asked',
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', $conversation));

    $response->assertOk();
    $response->assertJsonPath('usage.prompt', 0);
    $response->assertJsonPath('usage.completion', 0);
    $response->assertJsonPath('usage.total', 0);
});

it('ignores null token counts when computing usage', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();

    // Pre-Phase-6 assistant row with NULL token columns.
    Message::factory()->for($conversation)
        ->assistant()
        ->create(['content' => 'legacy reply']);
    // Modern assistant row with real counts.
    Message::factory()->for($conversation)
        ->assistant()
        ->withTokens(prompt: 100, completion: 200)
        ->create(['content' => 'new reply']);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', $conversation));

    $response->assertOk();
    $response->assertJsonPath('usage.prompt', 100);
    $response->assertJsonPath('usage.completion', 200);
    $response->assertJsonPath('usage.total', 300);
});

it('includes usage total on each item in the conversation list', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'Busy']);
    Message::factory()->for($conversation)
        ->assistant()
        ->withTokens(prompt: 400, completion: 300)
        ->create();

    $empty = Conversation::factory()->forUser($user)->create(['title' => 'Empty']);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.index'));

    $response->assertOk();
    $byTitle = collect($response->json())->keyBy('title');

    expect($byTitle['Busy']['usage']['total'])->toBe(700);
    expect($byTitle['Empty']['usage']['total'])->toBe(0);

    // Shape sanity check — the sidebar only needs the total.
    expect($byTitle['Busy'])->toHaveKey('usage');
    expect($byTitle['Busy']['usage'])->toHaveKey('total');
    // And the existing keys must still be present.
    expect($byTitle['Busy'])->toHaveKeys(['id', 'title', 'model', 'updated_at', 'messages_count']);
});

it('includes usage in the SSE done event on chat message', function () {
    AnonymousAgent::fake(['Streamed reply.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Please summarize AI',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    // The done frame carries the fresh cumulative usage snapshot. We match
    // the key loosely so the assertion doesn't depend on the exact token
    // numbers the fake agent returns — only that the shape is present.
    expect($body)->toContain('event: done');
    expect($body)->toMatch('/"usage":\{"prompt":\d+,"completion":\d+,"total":\d+\}/');
});

it('includes usage in the SSE done event on regenerate', function () {
    AnonymousAgent::fake(['Regenerated reply.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'Regen me']);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'original prompt',
    ]);
    Message::factory()->for($conversation)
        ->assistant()
        ->withTokens(prompt: 10, completion: 20)
        ->create(['content' => 'old reply']);

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: done');
    expect($body)->toMatch('/"usage":\{"prompt":\d+,"completion":\d+,"total":\d+\}/');
});
