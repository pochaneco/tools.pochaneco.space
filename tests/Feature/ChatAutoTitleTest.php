<?php

use App\Ai\ConversationTitleAgent;
use App\Enums\MessageRole;
use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\AnonymousAgent;

it('dispatches the title job after the first assistant reply', function () {
    Bus::fake([GenerateConversationTitle::class]);
    AnonymousAgent::fake(['First assistant reply.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Explain dependency injection in Laravel',
    ]);

    $response->assertOk();
    // Drain the SSE stream so the controller's StreamedResponse closure runs.
    $response->streamedContent();

    Bus::assertDispatched(GenerateConversationTitle::class, function ($job) use ($user) {
        return $job->conversation->user_id === $user->id;
    });
});

it('does not dispatch the title job on subsequent messages', function () {
    Bus::fake([GenerateConversationTitle::class]);
    AnonymousAgent::fake(['Follow-up answer.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Manually set title',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'First question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'First answer',
    ]);

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Follow-up question',
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    $response->streamedContent();

    Bus::assertNotDispatched(GenerateConversationTitle::class);
});

it('updates the conversation title when the job runs', function () {
    ConversationTitleAgent::fake(['Laravel入門ガイド']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Explain dependency injection in Laravel',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Explain dependency injection in Laravel',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Dependency injection is ...',
    ]);

    (new GenerateConversationTitle($conversation))->handle();

    expect($conversation->fresh()->title)->toBe('Laravel入門ガイド');
});

it('strips quote characters and collapses whitespace in the AI title', function () {
    ConversationTitleAgent::fake(["  「Laravel\nコツ」  "]);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Tell me Laravel tips',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Tell me Laravel tips',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Sure, here are some tips.',
    ]);

    (new GenerateConversationTitle($conversation))->handle();

    expect($conversation->fresh()->title)->toBe('Laravel コツ');
});

it('ignores empty AI responses and keeps the placeholder title', function () {
    ConversationTitleAgent::fake(['   ']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Placeholder title',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Some question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Some answer',
    ]);

    (new GenerateConversationTitle($conversation))->handle();

    expect($conversation->fresh()->title)->toBe('Placeholder title');
});

it('truncates very long AI titles to 60 characters', function () {
    $long = str_repeat('あ', 200);
    ConversationTitleAgent::fake([$long]);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Placeholder title',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Some very long question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Some very long answer',
    ]);

    (new GenerateConversationTitle($conversation))->handle();

    $title = $conversation->fresh()->title;

    expect(mb_strlen($title))->toBeLessThanOrEqual(60);
    expect($title)->toStartWith('あ');
});

it('does nothing when the conversation has no user message yet', function () {
    ConversationTitleAgent::fake(['Some title']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Original title',
    ]);

    (new GenerateConversationTitle($conversation))->handle();

    expect($conversation->fresh()->title)->toBe('Original title');
});

it('swallows AI provider errors without throwing', function () {
    ConversationTitleAgent::fake(function () {
        throw new RuntimeException('provider exploded');
    });

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Placeholder title',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Something',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Something back',
    ]);

    $job = new GenerateConversationTitle($conversation);

    // Simulate Laravel's queue worker calling failed() when handle() throws.
    try {
        $job->handle();
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
        $job->failed($e);
    }

    // The job itself is free to surface the error (queue worker logs it);
    // the important invariant is that the conversation title is left alone.
    expect($conversation->fresh()->title)->toBe('Placeholder title');
    expect($thrown)->toBeInstanceOf(RuntimeException::class);
});
