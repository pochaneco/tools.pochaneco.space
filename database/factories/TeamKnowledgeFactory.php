<?php

namespace Database\Factories;

use App\Enums\KnowledgeStatus;
use App\Models\Team;
use App\Models\TeamKnowledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamKnowledge>
 */
class TeamKnowledgeFactory extends Factory
{
    protected $model = TeamKnowledge::class;

    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'team_id' => Team::factory(),
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'body' => "# {$title}\n\n".fake()->paragraphs(3, true),
            'status' => KnowledgeStatus::Draft,
            'published_at' => null,
            'indexed_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeStatus::Archived,
        ]);
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn () => [
            'team_id' => $team->id,
        ]);
    }
}
