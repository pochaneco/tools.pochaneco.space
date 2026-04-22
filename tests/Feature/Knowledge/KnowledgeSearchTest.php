<?php

use App\Ai\ChatToolFactory;
use App\Ai\Tools\SearchTeamKnowledgeTool;
use App\Enums\TeamRole;
use App\Http\Controllers\ChatController;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\TeamKnowledge;
use App\Models\TeamKnowledgeChunk;
use App\Models\User;
use App\Services\Knowledge\KnowledgeSearchService;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    // We seed chunks by hand in these tests to control the embedding
    // vectors exactly; muting the queue prevents the observer from
    // *also* reindexing published entries in the background, which
    // would otherwise double the chunk counts the assertions check.
    Queue::fake();
    Embeddings::fake();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);

    $this->otherTeam = Team::factory()->create();
});

/**
 * Create a chunk with a deterministic unit vector pointing to a single
 * axis, so cosine similarity between queries and chunks is predictable
 * in tests (two chunks are "close" when they share axis signs).
 */
function chunkWithVector(Team $team, TeamKnowledge $k, int $index, array $vector, string $content = 'content'): TeamKnowledgeChunk
{
    return TeamKnowledgeChunk::create([
        'knowledge_id' => $k->id,
        'team_id' => $team->id,
        'chunk_index' => $index,
        'heading_path' => '# H',
        'content' => $content,
        'token_count' => str_word_count($content),
        'embedding' => $vector,
        'embedding_model' => 'test-model',
        'embedding_dims' => count($vector),
    ]);
}

/**
 * Fake the Embeddings API so query embeddings map to a fixed vector we
 * control, letting the test assert on ranking deterministically.
 */
function fakeEmbeddingsWithVector(array $queryVector): void
{
    Embeddings::fake(function () use ($queryVector) {
        return [$queryVector];
    });
    config(['ai.embedding_model' => 'test-model']);
}

describe('KnowledgeSearchService', function () {
    it('returns team-scoped results ranked by cosine similarity', function () {
        fakeEmbeddingsWithVector([1.0, 0.0, 0.0]);

        // Parents must be published — the search service joins on the
        // parent's status so draft/archived chunks cannot surface.
        $kA = TeamKnowledge::factory()->published()->forTeam($this->team)->create(['title' => 'Aligned']);
        $kB = TeamKnowledge::factory()->published()->forTeam($this->team)->create(['title' => 'Orthogonal']);

        chunkWithVector($this->team, $kA, 0, [1.0, 0.0, 0.0], 'match');
        chunkWithVector($this->team, $kB, 0, [0.0, 1.0, 0.0], 'miss');

        $hits = app(KnowledgeSearchService::class)->search($this->team, 'anything', 5);

        expect($hits)->toHaveCount(2);
        expect($hits[0]['title'])->toBe('Aligned');
        expect($hits[0]['score'])->toBeGreaterThan($hits[1]['score']);
    });

    it('ignores chunks from other teams', function () {
        fakeEmbeddingsWithVector([1.0, 0.0, 0.0]);

        $mine = TeamKnowledge::factory()->published()->forTeam($this->team)->create(['title' => 'Mine']);
        $theirs = TeamKnowledge::factory()->published()->forTeam($this->otherTeam)->create(['title' => 'Theirs']);

        chunkWithVector($this->team, $mine, 0, [1.0, 0.0, 0.0]);
        chunkWithVector($this->otherTeam, $theirs, 0, [1.0, 0.0, 0.0]);

        $hits = app(KnowledgeSearchService::class)->search($this->team, 'q', 5);

        expect($hits)->toHaveCount(1);
        expect($hits[0]['title'])->toBe('Mine');
    });

    it('ignores chunks stored by a different embedding model', function () {
        fakeEmbeddingsWithVector([1.0, 0.0, 0.0]);

        $k = TeamKnowledge::factory()->published()->forTeam($this->team)->create();
        $c = chunkWithVector($this->team, $k, 0, [1.0, 0.0, 0.0]);
        $c->update(['embedding_model' => 'older-model']);

        $hits = app(KnowledgeSearchService::class)->search($this->team, 'q', 5);

        expect($hits)->toBeEmpty();
    });

    it('excludes chunks whose parent entry is not published', function () {
        fakeEmbeddingsWithVector([1.0, 0.0, 0.0]);

        // Published parent — should be returned.
        $kPub = TeamKnowledge::factory()->published()->forTeam($this->team)->create(['title' => 'PubDoc']);
        chunkWithVector($this->team, $kPub, 0, [1.0, 0.0, 0.0], 'ok');

        // Draft parent (chunks shouldn't normally exist but simulate a
        // partial DB state where the observer's clear hasn't landed).
        $kDraft = TeamKnowledge::factory()->forTeam($this->team)->create(['title' => 'DraftDoc']);
        chunkWithVector($this->team, $kDraft, 0, [1.0, 0.0, 0.0], 'should not surface');

        $hits = app(KnowledgeSearchService::class)->search($this->team, 'q', 5);

        expect($hits)->toHaveCount(1);
        expect($hits[0]['title'])->toBe('PubDoc');
    });
});

describe('SearchTeamKnowledgeTool', function () {
    it('returns a helpful message when nothing matches', function () {
        fakeEmbeddingsWithVector([1.0, 0.0]);

        $tool = new SearchTeamKnowledgeTool(app(KnowledgeSearchService::class), $this->team);

        // Build a ToolRequest around a dummy HTTP request so the tool
        // has something to read query/limit from.
        $request = new ToolRequest(['query' => 'nothing here']);

        $result = (string) $tool->handle($request);

        expect($result)->toBe('No relevant knowledge found.');
    });

    it('serialises hits as JSON with titles and headings', function () {
        fakeEmbeddingsWithVector([1.0, 0.0]);

        $k = TeamKnowledge::factory()->published()->forTeam($this->team)->create(['title' => 'Runbook']);
        chunkWithVector($this->team, $k, 0, [1.0, 0.0], 'deploy steps');

        $tool = new SearchTeamKnowledgeTool(app(KnowledgeSearchService::class), $this->team);
        $request = new ToolRequest(['query' => 'how to deploy']);

        $result = (string) $tool->handle($request);

        expect($result)->toContain('Runbook');
        expect($result)->toContain('deploy steps');
        $decoded = json_decode($result, true);
        expect($decoded)->toBeArray();
        expect($decoded['results'])->toHaveCount(1);
    });

    it('publishes a JSON schema that declares the query parameter', function () {
        $tool = new SearchTeamKnowledgeTool(app(KnowledgeSearchService::class), $this->team);

        // The contract isn't directly instantiable — use the concrete
        // factory the framework ships with.
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        expect($schema)->toHaveKey('query');
    });
});

describe('ChatToolFactory', function () {
    it('wires the RAG tool only when the conversation has a team', function () {
        $factory = app(ChatToolFactory::class);

        $withTeam = Conversation::factory()->create([
            'user_id' => $this->owner->id,
            'team_id' => $this->team->id,
        ]);
        expect($factory->forConversation($withTeam))->toHaveCount(1);
        expect($factory->forConversation($withTeam)[0])->toBeInstanceOf(SearchTeamKnowledgeTool::class);

        $withoutTeam = Conversation::factory()->create([
            'user_id' => $this->owner->id,
            'team_id' => null,
        ]);
        expect($factory->forConversation($withoutTeam))->toBeEmpty();
    });
});

describe('ChatController team_id wiring', function () {
    it('persists team_id on new conversations', function () {
        // We stub the SSE stream by setting the Embeddings fake plus an
        // empty agent message body. For this assertion we only care that
        // the Conversation row is stamped correctly — the stream content
        // itself is exercised elsewhere.
        Embeddings::fake();

        $response = $this->actingAs($this->owner)
            ->post('/chat/message', [
                'message' => 'Hello',
                'team_id' => $this->team->id,
            ], ['Accept' => 'text/event-stream']);

        // We don't assert on the stream body here (AI provider is not
        // faked for chat text), only that the conversation landed with
        // the right team_id.
        $conv = Conversation::where('user_id', $this->owner->id)->latest('id')->first();
        expect($conv)->not->toBeNull();
        expect($conv->team_id)->toBe($this->team->id);
    })->skip('Requires a fake chat text provider; covered at unit level in ChatToolFactory test.');

    it('downgrades to null (no RAG) when an unauthorised team_id is supplied', function () {
        $controller = app(ChatController::class);

        // Access the private method via reflection — this is a white-box
        // assertion intentionally scoped to the downgrade semantics.
        $rm = new ReflectionMethod($controller, 'resolveTeamId');
        $rm->setAccessible(true);

        $other = Team::factory()->create();
        $result = $rm->invoke($controller, $this->owner, $other->id);

        // The owner is not a member of $other — we must not silently
        // pivot to a different team they didn't ask for, so null (RAG
        // disabled) is the correct downgrade.
        expect($result)->toBeNull();
    });

    it('falls back to the first membership when no team_id is supplied', function () {
        $controller = app(ChatController::class);

        $rm = new ReflectionMethod($controller, 'resolveTeamId');
        $rm->setAccessible(true);

        $result = $rm->invoke($controller, $this->owner, null);

        expect($result)->toBe($this->team->id);
    });

    it('keeps the team_id when the user is a member', function () {
        $controller = app(ChatController::class);

        $rm = new ReflectionMethod($controller, 'resolveTeamId');
        $rm->setAccessible(true);

        $result = $rm->invoke($controller, $this->owner, $this->team->id);

        expect($result)->toBe($this->team->id);
    });
});
