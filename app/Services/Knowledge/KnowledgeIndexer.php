<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\TeamKnowledge;
use App\Models\TeamKnowledgeChunk;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the chunk-then-embed pipeline for a single knowledge
 * entry. Designed to be idempotent: re-running it replaces all chunks
 * with a fresh set, so callers can safely retry on partial failures.
 */
class KnowledgeIndexer
{
    public function __construct(
        private readonly MarkdownChunker $chunker,
        private readonly EmbeddingService $embeddings,
    ) {}

    public function reindex(TeamKnowledge $knowledge): int
    {
        $chunks = $this->chunker->chunk((string) $knowledge->body);

        if ($chunks === []) {
            $this->clear($knowledge);

            return 0;
        }

        $texts = array_map(fn (array $c) => $c['content'], $chunks);
        $result = $this->embeddings->embed($texts);

        if (count($result['vectors']) !== count($chunks)) {
            // Provider returned a mismatched number of vectors — abort the
            // transaction instead of writing misaligned embeddings that
            // would silently corrupt retrieval.
            throw new \RuntimeException(sprintf(
                'Embedding count (%d) did not match chunk count (%d) for knowledge #%d.',
                count($result['vectors']),
                count($chunks),
                $knowledge->id,
            ));
        }

        $now = now();

        DB::transaction(function () use ($knowledge, $chunks, $result, $now) {
            TeamKnowledgeChunk::where('knowledge_id', $knowledge->id)->delete();

            $rows = [];
            foreach ($chunks as $i => $chunk) {
                $rows[] = [
                    'knowledge_id' => $knowledge->id,
                    'team_id' => $knowledge->team_id,
                    'chunk_index' => $i,
                    'heading_path' => $chunk['heading_path'] !== '' ? $chunk['heading_path'] : null,
                    'content' => $chunk['content'],
                    'token_count' => $chunk['token_count'],
                    'embedding' => json_encode($result['vectors'][$i], JSON_UNESCAPED_UNICODE),
                    'embedding_model' => $result['model'],
                    'embedding_dims' => $result['dims'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            TeamKnowledgeChunk::insert($rows);

            $knowledge->forceFill(['indexed_at' => $now])->saveQuietly();
        });

        return count($chunks);
    }

    /**
     * Remove every chunk for this entry. Used when transitioning out of
     * the published state so the AI search tool stops surfacing it
     * immediately, without waiting for a queue worker.
     */
    public function clear(TeamKnowledge $knowledge): void
    {
        DB::transaction(function () use ($knowledge) {
            TeamKnowledgeChunk::where('knowledge_id', $knowledge->id)->delete();
            $knowledge->forceFill(['indexed_at' => null])->saveQuietly();
        });
    }
}
