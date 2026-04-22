<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\KnowledgeStatus;
use App\Jobs\IndexTeamKnowledge;
use App\Models\TeamKnowledge;
use App\Services\Knowledge\KnowledgeIndexer;

class TeamKnowledgeObserver
{
    public function __construct(
        private readonly KnowledgeIndexer $indexer,
    ) {}

    /**
     * After a knowledge entry is written, decide whether it should be in
     * the vector index and act on the delta. The three cases are:
     *
     *   1. Stays published & body/status dirty  → re-index (async).
     *   2. Becomes published for the first time → index (async).
     *   3. Leaves published (draft/archived)    → clear chunks (sync).
     *
     * We clear synchronously because retraction of published content
     * should be immediate — waiting for a queue worker to stop surfacing
     * archived or retracted knowledge would leak it through the AI tool.
     */
    public function saved(TeamKnowledge $knowledge): void
    {
        $status = $knowledge->status;
        $statusChanged = $knowledge->wasChanged('status');
        $bodyChanged = $knowledge->wasChanged('body');

        if ($status === KnowledgeStatus::Published) {
            // Special case: the entry is nominally published but has no
            // body to index. We clear synchronously instead of queuing
            // an indexing job so there's no window where stale chunks
            // from the previous body remain discoverable via the RAG
            // tool while we wait for the worker to notice the emptiness.
            if (trim((string) $knowledge->body) === '') {
                $this->indexer->clear($knowledge);

                return;
            }

            if ($bodyChanged || $statusChanged || $knowledge->indexed_at === null) {
                IndexTeamKnowledge::dispatch($knowledge)->afterResponse();
            }

            return;
        }

        // Left the published state. Only clear when the transition
        // actually happened; a plain title edit on an already-draft
        // entry doesn't need to touch the chunks table.
        if ($statusChanged) {
            $this->indexer->clear($knowledge);
        }
    }

    public function deleted(TeamKnowledge $knowledge): void
    {
        // Soft delete leaves the chunks orphaned from the user's view but
        // still team-scoped — drop them explicitly so the AI tool cannot
        // surface content that is no longer listed anywhere in the UI.
        $this->indexer->clear($knowledge);
    }
}
