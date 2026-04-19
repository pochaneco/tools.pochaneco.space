<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('lists current users conversations ordered by updated_at desc', function () {
    $user = User::factory()->create();

    $older = Conversation::factory()->forUser($user)->create([
        'title' => 'Older',
        'updated_at' => now()->subDay(),
    ]);
    $newest = Conversation::factory()->forUser($user)->create([
        'title' => 'Newest',
        'updated_at' => now(),
    ]);
    $middle = Conversation::factory()->forUser($user)->create([
        'title' => 'Middle',
        'updated_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.index'));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();
    expect($ids)->toBe([$newest->id, $middle->id, $older->id]);

    $payload = $response->json();
    expect($payload[0])->toHaveKeys(['id', 'title', 'model', 'updated_at', 'messages_count', 'usage']);
    expect($payload[0]['usage'])->toHaveKey('total');
});

it('does not include other users conversations in the list', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Conversation::factory()->forUser($user)->create(['title' => 'Mine']);
    Conversation::factory()->forUser($other)->create(['title' => 'Theirs']);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.index'));

    $response->assertOk();
    $titles = collect($response->json())->pluck('title')->all();
    expect($titles)->toContain('Mine');
    expect($titles)->not->toContain('Theirs');
});

it('shows a single conversation with its messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'Show me']);

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'hello',
        'created_at' => now()->subMinute(),
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'hi there',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', $conversation));

    $response->assertOk();
    $response->assertJsonPath('id', $conversation->id);
    $response->assertJsonPath('title', 'Show me');
    $response->assertJsonPath('messages.0.role', MessageRole::User->value);
    $response->assertJsonPath('messages.0.content', 'hello');
    $response->assertJsonPath('messages.1.role', MessageRole::Assistant->value);
    $response->assertJsonPath('messages.1.content', 'hi there');
});

it('rejects showing another users conversation', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $conversation = Conversation::factory()->forUser($owner)->create();

    $response = $this->actingAs($intruder)->getJson(route('chat.conversations.show', $conversation));

    expect($response->getStatusCode())->toBeIn([403, 404]);
});

it('renames a conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'old']);

    $response = $this->actingAs($user)->patchJson(
        route('chat.conversations.update', $conversation),
        ['title' => 'new title'],
    );

    $response->assertOk();
    expect($conversation->fresh()->title)->toBe('new title');
});

it('rejects renaming another users conversation', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $conversation = Conversation::factory()->forUser($owner)->create(['title' => 'old']);

    $response = $this->actingAs($intruder)->patchJson(
        route('chat.conversations.update', $conversation),
        ['title' => 'hijacked'],
    );

    expect($response->getStatusCode())->toBeIn([403, 404]);
    expect($conversation->fresh()->title)->toBe('old');
});

it('validates title on rename', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create(['title' => 'keep']);

    // missing title
    $this->actingAs($user)
        ->patchJson(route('chat.conversations.update', $conversation), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    // too long
    $this->actingAs($user)
        ->patchJson(route('chat.conversations.update', $conversation), [
            'title' => str_repeat('a', 201),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    expect($conversation->fresh()->title)->toBe('keep');
});

it('deletes a conversation and cascades messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();
    $message = Message::factory()->for($conversation)->create();

    $response = $this->actingAs($user)->deleteJson(route('chat.conversations.destroy', $conversation));

    $response->assertStatus(204);
    $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    $this->assertDatabaseMissing('messages', ['id' => $message->id]);
});

it('rejects deleting another users conversation', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $conversation = Conversation::factory()->forUser($owner)->create();

    $response = $this->actingAs($intruder)->deleteJson(route('chat.conversations.destroy', $conversation));

    expect($response->getStatusCode())->toBeIn([403, 404]);
    $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
});

it('bumps conversation updated_at when a new message is added', function () {
    $user = User::factory()->create();

    $conversation = Conversation::factory()->forUser($user)->create([
        'updated_at' => now()->subDay(),
    ]);
    $conversation->refresh();

    $before = $conversation->updated_at;

    // Simulate the controller creating an assistant message.
    Message::create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant->value,
        'content' => 'touched',
    ]);

    $conversation->refresh();

    expect($conversation->updated_at->greaterThan($before))->toBeTrue();
});
