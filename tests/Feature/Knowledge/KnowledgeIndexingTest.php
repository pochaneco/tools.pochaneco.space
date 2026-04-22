<?php

use App\Enums\KnowledgeStatus;
use App\Enums\TeamRole;
use App\Jobs\IndexTeamKnowledge;
use App\Models\Team;
use App\Models\TeamKnowledge;
use App\Models\TeamKnowledgeChunk;
use App\Models\User;
use App\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);
});

describe('observer → job dispatch', function () {
    it('queues indexing when an entry is created as published', function () {
        Queue::fake();

        TeamKnowledge::factory()
            ->forTeam($this->team)
            ->published()
            ->state(['author_id' => $this->owner->id])
            ->create();

        Queue::assertPushed(IndexTeamKnowledge::class);
    });

    it('queues indexing when a draft transitions to published', function () {
        Queue::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        Queue::assertNothingPushed();

        $k->status = KnowledgeStatus::Published;
        $k->published_at = now();
        $k->save();

        Queue::assertPushed(IndexTeamKnowledge::class, 1);
    });

    it('does not queue indexing for pure title edits on a draft', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        Queue::fake();

        $k->title = 'New title';
        $k->save();

        Queue::assertNothingPushed();
    });

    it('clears chunks synchronously when leaving published state', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->published()
            ->state(['author_id' => $this->owner->id])
            ->create();

        // Seed three chunks as if the indexer had run.
        TeamKnowledgeChunk::factory()->count(3)->create([
            'knowledge_id' => $k->id,
            'team_id' => $this->team->id,
        ]);
        $k->forceFill(['indexed_at' => now()])->save();

        $k->status = KnowledgeStatus::Archived;
        $k->save();

        expect(TeamKnowledgeChunk::where('knowledge_id', $k->id)->count())->toBe(0);
        expect($k->fresh()->indexed_at)->toBeNull();
    });
});

describe('indexer pipeline', function () {
    it('produces chunks with consistent embeddings via the fake gateway', function () {
        Embeddings::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state([
                'author_id' => $this->owner->id,
                'body' => "# Intro\n\nHello world.\n\n# Setup\n\nRun the installer.",
            ])
            ->create();

        app(KnowledgeIndexer::class)->reindex($k);

        $chunks = TeamKnowledgeChunk::where('knowledge_id', $k->id)->get();

        expect($chunks)->toHaveCount(2);
        foreach ($chunks as $chunk) {
            expect($chunk->embedding)->toBeArray();
            expect(count($chunk->embedding))->toBeGreaterThan(0);
            expect($chunk->embedding_model)->toBeString();
            expect($chunk->team_id)->toBe($this->team->id);
        }

        expect($k->fresh()->indexed_at)->not->toBeNull();
    });

    it('deletes old chunks on re-index', function () {
        Embeddings::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state([
                'author_id' => $this->owner->id,
                'body' => "# A\n\none\n\n# B\n\ntwo",
            ])
            ->create();

        $indexer = app(KnowledgeIndexer::class);
        $indexer->reindex($k);
        $firstChunkIds = TeamKnowledgeChunk::where('knowledge_id', $k->id)->pluck('id');

        $indexer->reindex($k);
        $secondChunkIds = TeamKnowledgeChunk::where('knowledge_id', $k->id)->pluck('id');

        expect($firstChunkIds->intersect($secondChunkIds))->toHaveCount(0);
    });

    it('clears chunks when the body becomes empty', function () {
        Embeddings::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state([
                'author_id' => $this->owner->id,
                'body' => '# Heading',
            ])
            ->create();

        $indexer = app(KnowledgeIndexer::class);
        $indexer->reindex($k);

        // Wipe content then re-index.
        $k->body = '   ';
        $k->save();
        $indexer->reindex($k);

        expect(TeamKnowledgeChunk::where('knowledge_id', $k->id)->count())->toBe(0);
        expect($k->fresh()->indexed_at)->toBeNull();
    });
});

describe('late-job safety', function () {
    it('clears instead of re-indexing when the entry is no longer published at job time', function () {
        Embeddings::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->published()
            ->state(['author_id' => $this->owner->id, 'body' => '# Live'])
            ->create();

        // Simulate prior indexing state.
        TeamKnowledgeChunk::factory()->count(2)->create([
            'knowledge_id' => $k->id,
            'team_id' => $this->team->id,
        ]);
        $k->forceFill(['indexed_at' => now()])->saveQuietly();

        // Demote *without* going through the observer — the scenario
        // we're protecting against is a stale job firing after a
        // retraction that has already happened elsewhere.
        $k->forceFill(['status' => KnowledgeStatus::Archived])->saveQuietly();

        (new IndexTeamKnowledge($k))->handle(app(KnowledgeIndexer::class));

        expect(TeamKnowledgeChunk::where('knowledge_id', $k->id)->count())->toBe(0);
    });
});

describe('published body emptied', function () {
    it('synchronously clears chunks when a published entry loses its body', function () {
        Embeddings::fake();

        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->published()
            ->state(['author_id' => $this->owner->id, 'body' => '# Live'])
            ->create();

        // Run the indexer once so we have real chunks on disk.
        app(KnowledgeIndexer::class)->reindex($k);
        expect(TeamKnowledgeChunk::where('knowledge_id', $k->id)->count())->toBeGreaterThan(0);

        // Now blank out the body while leaving the status at Published.
        // The observer should notice the empty body and clear chunks
        // synchronously — without queuing a no-op indexing job.
        Queue::fake();
        $k->body = "   \n\n  ";
        $k->save();

        expect(TeamKnowledgeChunk::where('knowledge_id', $k->id)->count())->toBe(0);
        expect($k->fresh()->indexed_at)->toBeNull();
        Queue::assertNothingPushed();
    });
});
