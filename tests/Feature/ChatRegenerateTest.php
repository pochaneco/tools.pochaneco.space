<?php

use App\Enums\MessageRole;
use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\AnonymousAgent;

/**
 * Helper: seed a conversation with a user/assistant exchange so the
 * regenerate endpoint has something to replace.
 */
function seedConversationWithExchange(User $user, string $userContent = 'Explain closures', string $assistantContent = 'Original assistant answer'): Conversation
{
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Existing title',
    ]);

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => $userContent,
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => $assistantContent,
    ]);

    return $conversation;
}

it('returns SSE headers for regenerate', function () {
    AnonymousAgent::fake(['A fresh assistant reply.']);

    $user = User::factory()->create();
    $conversation = seedConversationWithExchange($user);

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toContain('text/event-stream');
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-cache');
});

it('deletes the latest assistant message and creates a new one', function () {
    AnonymousAgent::fake(['Regenerated answer.']);

    $user = User::factory()->create();
    $conversation = seedConversationWithExchange($user, 'Explain closures', 'Original assistant answer');
    $originalAssistantId = $conversation->messages()
        ->where('role', MessageRole::Assistant->value)
        ->value('id');

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    $response->streamedContent();

    // Original assistant row is gone.
    expect(Message::find($originalAssistantId))->toBeNull();

    // Exactly one user + one new assistant now, and the assistant content is the fake reply.
    $messages = $conversation->fresh()->messages()->orderBy('created_at')->get();
    expect($messages)->toHaveCount(2);
    expect($messages[0]->role)->toBe(MessageRole::User);
    expect($messages[0]->content)->toBe('Explain closures');
    expect($messages[1]->role)->toBe(MessageRole::Assistant);
    expect($messages[1]->content)->toBe('Regenerated answer.');
    expect($messages[1]->id)->not->toBe($originalAssistantId);
});

it('preserves the latest user message', function () {
    AnonymousAgent::fake(['Second try answer.']);

    $user = User::factory()->create();
    $conversation = seedConversationWithExchange($user, 'Preserved question');
    $originalUserId = $conversation->messages()
        ->where('role', MessageRole::User->value)
        ->value('id');

    $this->actingAs($user)
        ->post(route('chat.regenerate'), ['conversation_id' => $conversation->id])
        ->streamedContent();

    $userMessage = Message::find($originalUserId);
    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe('Preserved question');
});

it('uses conversation history when regenerating', function () {
    AnonymousAgent::fake(['Context-aware answer.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'Multi-turn']);
    // Earlier turns we want the SDK to see as context.
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'First question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'First answer',
    ]);
    // Latest turn — the assistant row here must be replaced.
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Follow-up question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Follow-up answer (to be replaced)',
    ]);

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    $response->streamedContent();

    $messages = $conversation->fresh()->messages()->orderBy('created_at')->get();
    // 3 original + 1 new assistant = 4. The old latest assistant is deleted
    // and a new one is appended.
    expect($messages)->toHaveCount(4);
    expect($messages->pluck('content')->all())->toBe([
        'First question',
        'First answer',
        'Follow-up question',
        'Context-aware answer.',
    ]);
});

it('rejects unauthenticated requests', function () {
    $user = User::factory()->create();
    $conversation = seedConversationWithExchange($user);

    $this->post(route('chat.regenerate'), ['conversation_id' => $conversation->id])
        ->assertRedirect(route('login'));
});

it('rejects other users conversations', function () {
    AnonymousAgent::fake(['Should not run.']);

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $conversation = seedConversationWithExchange($owner);

    $response = $this->actingAs($intruder)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertForbidden();
});

it('validates conversation_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('chat.regenerate'), [])
        ->assertSessionHasErrors('conversation_id');

    $this->actingAs($user)
        ->post(route('chat.regenerate'), ['conversation_id' => 99999])
        ->assertSessionHasErrors('conversation_id');
});

it('does not dispatch GenerateConversationTitle job', function () {
    Bus::fake([GenerateConversationTitle::class]);
    AnonymousAgent::fake(['Regenerated answer.']);

    $user = User::factory()->create();
    $conversation = seedConversationWithExchange($user);

    $this->actingAs($user)
        ->post(route('chat.regenerate'), ['conversation_id' => $conversation->id])
        ->streamedContent();

    Bus::assertNotDispatched(GenerateConversationTitle::class);
});

it('fails gracefully when there is no user message in the conversation', function () {
    $user = User::factory()->create();
    // Conversation with only an assistant message (edge case) — no user
    // prompt to replay.
    $conversation = Conversation::factory()->forUser($user)->create();
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Dangling assistant message',
    ]);

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
    ]);

    $response->assertStatus(422);
});
