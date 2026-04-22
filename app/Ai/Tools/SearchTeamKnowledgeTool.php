<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Team;
use App\Services\Knowledge\KnowledgeSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tool surfaced to the chat agent so it can pull relevant passages from
 * the team's knowledge base on demand. The agent decides when to call
 * it; we don't prefetch every turn because most messages don't benefit
 * from RAG and calls are not free.
 */
class SearchTeamKnowledgeTool implements Tool
{
    private const DEFAULT_LIMIT = 5;

    private const MAX_LIMIT = 10;

    public function __construct(
        private readonly KnowledgeSearchService $search,
        private readonly Team $team,
    ) {}

    public function description(): string
    {
        return 'Search the current team\'s knowledge base for passages relevant to a natural-language question. '
            .'Call this whenever the user asks about team-specific documentation, runbooks, policies, or internal decisions. '
            .'Returns the top matching passages with their source titles so you can cite them.';
    }

    public function handle(Request $request): string
    {
        $query = trim((string) $request->string('query'));
        if ($query === '') {
            return 'query is required.';
        }

        $limit = $request->integer('limit', self::DEFAULT_LIMIT);

        // `limit <= 0` is a caller asking for nothing. Return the same
        // not-found sentinel rather than silently clamping to 1 — the
        // tool's public contract should honour the argument's intent.
        if ($limit <= 0) {
            return 'No relevant knowledge found.';
        }

        $limit = min(self::MAX_LIMIT, $limit);

        $hits = $this->search->search($this->team, $query, $limit);

        if ($hits === []) {
            return 'No relevant knowledge found.';
        }

        $payload = array_map(fn (array $h) => [
            'title' => $h['title'],
            'heading' => $h['heading_path'],
            'content' => $h['content'],
            'score' => round($h['score'], 4),
            'knowledge_id' => $h['knowledge_id'],
        ], $hits);

        return json_encode([
            'results' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural-language question or keywords to search for.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of passages to return (1-'.self::MAX_LIMIT.', default '.self::DEFAULT_LIMIT.').'),
        ];
    }
}
