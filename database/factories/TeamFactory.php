<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'description' => fake()->optional()->sentence(),
            'owner_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the team has a specific owner.
     */
    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Indicate that the team has no description.
     */
    public function withoutDescription(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => null,
        ]);
    }
}
