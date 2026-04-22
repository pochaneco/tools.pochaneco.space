<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Tools\SearchTeamKnowledgeTool;
use App\Models\Conversation;
use App\Services\Knowledge\KnowledgeSearchService;
use Laravel\Ai\Contracts\Tool;

/**
 * Builds the tool list for a given conversation. Centralised here so the
 * controller stays agnostic of tool wiring: if we add more tools later
 * (team search over people, runbook executors, etc.) they slot in here.
 */
class ChatToolFactory
{
    public function __construct(
        private readonly KnowledgeSearchService $search,
    ) {}

    /**
     * @return array<int, Tool>
     */
    public function forConversation(Conversation $conversation): array
    {
        $tools = [];

        $conversation->loadMissing('team');
        $team = $conversation->team;

        if ($team !== null) {
            $tools[] = new SearchTeamKnowledgeTool($this->search, $team);
        }

        return $tools;
    }
}
