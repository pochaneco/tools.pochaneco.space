<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::User->value,
            'content' => fake()->paragraph(),
            'model' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
        ];
    }

    /**
     * Indicate the model identifier recorded on the message. Assistant
     * messages store the model that produced the reply; user messages
     * conventionally leave this NULL.
     */
    public function withModel(string $model): static
    {
        return $this->state(fn (array $attributes) => [
            'model' => $model,
        ]);
    }

    /**
     * Seed explicit prompt/completion token counts for tests that
     * exercise cumulative usage calculations. Leaves other attributes
     * untouched so it composes with `assistant()` / `withModel()`.
     */
    public function withTokens(int $prompt, int $completion): static
    {
        return $this->state(fn (array $attributes) => [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
        ]);
    }

    /**
     * Indicate that the message is from the assistant.
     */
    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Assistant->value,
        ]);
    }

    /**
     * Indicate that the message is a system message.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::System->value,
        ]);
    }
}
