<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

describe('Conversation model', function () {
    it('belongs to a user', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->forUser($user)->create();

        expect($conversation->user)->toBeInstanceOf(User::class);
        expect($conversation->user->id)->toBe($user->id);
    });

    it('has many messages ordered by created_at', function () {
        $conversation = Conversation::factory()->create();

        $second = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'second',
            'created_at' => now()->addMinute(),
        ]);

        $first = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'first',
            'created_at' => now()->subMinute(),
        ]);

        $third = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'third',
            'created_at' => now()->addMinutes(2),
        ]);

        $messages = $conversation->messages()->get();

        expect($messages)->toHaveCount(3);
        expect($messages->pluck('content')->all())->toBe(['first', 'second', 'third']);
    });
});

describe('Message model', function () {
    it('belongs to a conversation', function () {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
        ]);

        expect($message->conversation)->toBeInstanceOf(Conversation::class);
        expect($message->conversation->id)->toBe($conversation->id);
    });

    it('casts role to MessageRole enum', function () {
        $message = Message::factory()->create([
            'role' => MessageRole::Assistant->value,
        ]);

        expect($message->role)->toBeInstanceOf(MessageRole::class);
        expect($message->role)->toBe(MessageRole::Assistant);
    });
});

describe('Cascade deletes', function () {
    it('cascades conversations when a user is deleted', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->forUser($user)->create();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);

        $user->delete();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    });

    it('cascades messages when a conversation is deleted', function () {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
        ]);

        $this->assertDatabaseHas('messages', ['id' => $message->id]);

        $conversation->delete();

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    });
});
