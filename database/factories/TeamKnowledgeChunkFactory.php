<?php

namespace Database\Factories;

use App\Models\TeamKnowledge;
use App\Models\TeamKnowledgeChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamKnowledgeChunk>
 */
class TeamKnowledgeChunkFactory extends Factory
{
    protected $model = TeamKnowledgeChunk::class;

    public function definition(): array
    {
        $content = fake()->paragraphs(2, true);
        // Deterministic unit vector so factory-built chunks have a valid
        // embedding shape without triggering a network call.
        $dims = 8;
        $raw = array_map(fn () => fake()->randomFloat(4, -1, 1), range(1, $dims));
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $raw))) ?: 1.0;
        $embedding = array_map(fn ($v) => $v / $norm, $raw);

        return [
            'knowledge_id' => TeamKnowledge::factory(),
            'team_id' => fn (array $attrs) => TeamKnowledge::find($attrs['knowledge_id'])?->team_id
                ?? TeamKnowledge::factory()->create()->team_id,
            'chunk_index' => 0,
            'heading_path' => '# Section',
            'content' => $content,
            'token_count' => max(1, (int) round(str_word_count($content) * 1.3)),
            'embedding' => $embedding,
            'embedding_model' => 'test-embedding-model',
            'embedding_dims' => $dims,
        ];
    }
}
