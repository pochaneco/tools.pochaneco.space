<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\AnonymousAgent;

it('renders chat page for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('chat.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Chat/Index'));
});

it('chat message endpoint responds with SSE headers', function () {
    AnonymousAgent::fake(['Hello from the assistant.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Hi there',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toContain('text/event-stream');
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-cache');
});

it('chat message creates a new conversation and saves both messages', function () {
    AnonymousAgent::fake(['This is a test response.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'What is Laravel?',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    $conversation = Conversation::where('user_id', $user->id)->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->title)->toBe('What is Laravel?');

    $messages = $conversation->messages()->orderBy('created_at')->get();
    expect($messages)->toHaveCount(2);
    expect($messages[0]->role)->toBe(MessageRole::User);
    expect($messages[0]->content)->toBe('What is Laravel?');
    expect($messages[1]->role)->toBe(MessageRole::Assistant);
    expect($messages[1]->content)->toBe('This is a test response.');

    expect($body)->toContain('"type":"delta"');
    expect($body)->toContain('event: done');
});

it('chat message appends to existing conversation', function () {
    AnonymousAgent::fake(['Second reply.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'Existing']);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'First question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'First answer',
    ]);

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Follow-up?',
        'conversation_id' => $conversation->id,
    ]);

    $response->assertOk();
    $response->streamedContent();

    $conversation->refresh();
    expect($conversation->title)->toBe('Existing');
    expect($conversation->messages()->count())->toBe(4);
});

it('chat message validates input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('chat.message'), ['message' => ''])
        ->assertSessionHasErrors('message');

    $this->actingAs($user)
        ->post(route('chat.message'), ['message' => 'Hi', 'conversation_id' => 99999])
        ->assertSessionHasErrors('conversation_id');
});

it('chat message rejects other users conversations', function () {
    AnonymousAgent::fake(['Denied scenario response.']);

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $conversation = Conversation::factory()->forUser($owner)->create();

    $response = $this->actingAs($intruder)->post(route('chat.message'), [
        'message' => 'Hello',
        'conversation_id' => $conversation->id,
    ]);

    $response->assertNotFound();
});

it('chat message requires authentication', function () {
    $this->post(route('chat.message'), ['message' => 'Hi'])
        ->assertRedirect(route('login'));
});
