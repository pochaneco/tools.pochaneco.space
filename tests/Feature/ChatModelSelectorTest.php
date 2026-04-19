<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\AnonymousAgent;

it('exposes available chat models to the Inertia page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('chat.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Chat/Index')
        ->where('defaultModel', config('ai.default_chat_model'))
        ->has('availableModels.gpt-oss-120b', fn (Assert $model) => $model
            ->where('label', 'gpt-oss')
            ->etc()
        )
        ->has('availableModels.Qwen3-Coder-30B-A3B-Instruct', fn (Assert $model) => $model
            ->where('label', 'qwen-coder-30')
            ->etc()
        )
    );
});

it('persists the selected model on the assistant message', function () {
    AnonymousAgent::fake(['Reply from selected model.']);

    $user = User::factory()->create();
    $chosen = 'Qwen3-Coder-30B-A3B-Instruct';

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Write a fizzbuzz',
        'model' => $chosen,
    ]);

    $response->assertOk();
    $response->streamedContent();

    $conversation = Conversation::where('user_id', $user->id)->firstOrFail();
    expect($conversation->model)->toBe($chosen);

    $assistant = $conversation->messages()
        ->where('role', MessageRole::Assistant->value)
        ->first();
    expect($assistant)->not->toBeNull();
    expect($assistant->model)->toBe($chosen);

    $userMessage = $conversation->messages()
        ->where('role', MessageRole::User->value)
        ->first();
    // User rows never record a model — only the assistant reply does.
    expect($userMessage->model)->toBeNull();
});

it('falls back to the conversation default model when the request omits one', function () {
    AnonymousAgent::fake(['Reply.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Existing',
        'model' => 'llm-jp-3.1-8x13b-instruct4',
    ]);

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Hello',
        'conversation_id' => $conversation->id,
        // No explicit model — should fall back to conversation default.
    ]);

    $response->assertOk();
    $response->streamedContent();

    $assistant = $conversation->fresh()->messages()
        ->where('role', MessageRole::Assistant->value)
        ->orderByDesc('id')
        ->first();
    expect($assistant->model)->toBe('llm-jp-3.1-8x13b-instruct4');

    // Conversation default must remain untouched.
    expect($conversation->fresh()->model)->toBe('llm-jp-3.1-8x13b-instruct4');
});

it('falls back to the app default when neither request nor conversation provide a model', function () {
    AnonymousAgent::fake(['Reply.']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('chat.message'), [
        'message' => 'Hello without model',
    ]);

    $response->assertOk();
    $response->streamedContent();

    $conversation = Conversation::where('user_id', $user->id)->firstOrFail();
    expect($conversation->model)->toBe(config('ai.default_chat_model'));

    $assistant = $conversation->messages()
        ->where('role', MessageRole::Assistant->value)
        ->first();
    expect($assistant->model)->toBe(config('ai.default_chat_model'));
});

it('rejects unknown model values on /chat/message', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('chat.message'), [
            'message' => 'Hi',
            'model' => 'not-a-real-model',
        ])
        ->assertSessionHasErrors('model');
});

it('accepts per-message model override on regenerate and does not rewrite the conversation default', function () {
    AnonymousAgent::fake(['Regenerated with override.']);

    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create([
        'title' => 'Regen with override',
        'model' => 'gpt-oss-120b',
    ]);

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Original question',
    ]);
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant->value,
        'content' => 'Original answer',
        'model' => 'gpt-oss-120b',
    ]);

    $response = $this->actingAs($user)->post(route('chat.regenerate'), [
        'conversation_id' => $conversation->id,
        'model' => 'Qwen3-Coder-480B-A35B-Instruct-FP8',
    ]);

    $response->assertOk();
    $response->streamedContent();

    $fresh = $conversation->fresh();
    // Conversation-level default is preserved — regenerate never rewrites it.
    expect($fresh->model)->toBe('gpt-oss-120b');

    $newAssistant = $fresh->messages()
        ->where('role', MessageRole::Assistant->value)
        ->orderByDesc('id')
        ->first();
    expect($newAssistant->content)->toBe('Regenerated with override.');
    expect($newAssistant->model)->toBe('Qwen3-Coder-480B-A35B-Instruct-FP8');
});

it('rejects unknown model values on /chat/regenerate', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();
    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User->value,
        'content' => 'Prompt',
    ]);

    $this->actingAs($user)
        ->post(route('chat.regenerate'), [
            'conversation_id' => $conversation->id,
            'model' => 'bogus',
        ])
        ->assertSessionHasErrors('model');
});
