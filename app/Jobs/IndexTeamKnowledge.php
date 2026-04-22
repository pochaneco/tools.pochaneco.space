<?php

namespace App\Jobs;

use App\Models\TeamKnowledge;
use App\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IndexTeamKnowledge implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly TeamKnowledge $knowledge) {}

    public function handle(KnowledgeIndexer $indexer): void
    {
        // Re-fetch to pick up any edits that happened after enqueue; the
        // job payload is a snapshot and `body` could be stale by now.
        $fresh = TeamKnowledge::find($this->knowledge->id);
        if ($fresh === null) {
            return;
        }

        $indexer->reindex($fresh);
    }

    public function failed(Throwable $e): void
    {
        // Swallow but report — the indexing pipeline must never crash the
        // primary CRUD flow. Caller's `indexed_at` stays null so the UI
        // can show an unindexed state and the user can retry by saving.
        report($e);
    }
}
