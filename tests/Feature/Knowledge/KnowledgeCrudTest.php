<?php

use App\Enums\KnowledgeStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamKnowledge;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->outsider = User::factory()->create();

    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);
    $this->team->members()->attach($this->member->id, ['role' => TeamRole::MEMBER->value]);
});

describe('list & access', function () {
    it('lets members see the knowledge index', function () {
        $response = $this->actingAs($this->member)
            ->get("/teams/{$this->team->id}/knowledges");

        $response->assertSuccessful();
    });

    it('forbids outsiders from listing knowledge', function () {
        $response = $this->actingAs($this->outsider)
            ->get("/teams/{$this->team->id}/knowledges");

        $response->assertForbidden();
    });
});

describe('create', function () {
    it('stores a draft entry and assigns the author', function () {
        $response = $this->actingAs($this->member)
            ->post("/teams/{$this->team->id}/knowledges", [
                'title' => 'Runbook',
                'body' => "# Runbook\n\nSteps here.",
                'status' => KnowledgeStatus::Draft->value,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('team_knowledges', [
            'team_id' => $this->team->id,
            'author_id' => $this->member->id,
            'title' => 'Runbook',
            'status' => KnowledgeStatus::Draft->value,
            'published_at' => null,
        ]);
    });

    it('stamps published_at when creating directly as published', function () {
        $this->actingAs($this->owner)
            ->post("/teams/{$this->team->id}/knowledges", [
                'title' => 'Live doc',
                'body' => '# Live',
                'status' => KnowledgeStatus::Published->value,
            ]);

        $k = TeamKnowledge::where('title', 'Live doc')->firstOrFail();
        expect($k->status)->toBe(KnowledgeStatus::Published);
        expect($k->published_at)->not->toBeNull();
    });

    it('rejects creation from outsiders', function () {
        $response = $this->actingAs($this->outsider)
            ->post("/teams/{$this->team->id}/knowledges", [
                'title' => 'Nope',
                'body' => '# Nope',
                'status' => KnowledgeStatus::Draft->value,
            ]);

        $response->assertForbidden();
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->member)
            ->post("/teams/{$this->team->id}/knowledges", []);

        $response->assertInvalid(['title', 'body', 'status']);
    });

    it('generates a unique slug when titles collide within a team', function () {
        $this->actingAs($this->member)
            ->post("/teams/{$this->team->id}/knowledges", [
                'title' => 'Same Title',
                'body' => '# A',
                'status' => KnowledgeStatus::Draft->value,
            ]);
        $this->actingAs($this->member)
            ->post("/teams/{$this->team->id}/knowledges", [
                'title' => 'Same Title',
                'body' => '# B',
                'status' => KnowledgeStatus::Draft->value,
            ]);

        $slugs = TeamKnowledge::where('team_id', $this->team->id)
            ->where('title', 'Same Title')
            ->pluck('slug');

        expect($slugs)->toHaveCount(2);
        expect($slugs->unique()->count())->toBe(2);
    });
});

describe('update', function () {
    it('lets the author edit their own entry even as a member', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->member->id])
            ->create();

        $response = $this->actingAs($this->member)->patch("/knowledges/{$k->id}", [
            'title' => 'Edited',
            'body' => '# Edited',
            'status' => KnowledgeStatus::Draft->value,
        ]);

        $response->assertRedirect();
        expect($k->fresh()->title)->toBe('Edited');
    });

    it('blocks outsiders from updating', function () {
        $k = TeamKnowledge::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->outsider)->patch("/knowledges/{$k->id}", [
            'title' => 'Hack',
            'body' => '# Hack',
            'status' => KnowledgeStatus::Draft->value,
        ]);

        $response->assertForbidden();
    });

    it('stamps published_at on first publish', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        expect($k->published_at)->toBeNull();

        $this->actingAs($this->owner)->patch("/knowledges/{$k->id}", [
            'title' => $k->title,
            'body' => $k->body,
            'status' => KnowledgeStatus::Published->value,
        ]);

        expect($k->fresh()->published_at)->not->toBeNull();
    });
});

describe('status transitions', function () {
    it('publishes via dedicated endpoint', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        $this->actingAs($this->owner)->post("/knowledges/{$k->id}/publish");

        expect($k->fresh()->status)->toBe(KnowledgeStatus::Published);
    });

    it('unpublishes back to draft', function () {
        $k = TeamKnowledge::factory()->published()->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        $this->actingAs($this->owner)->post("/knowledges/{$k->id}/unpublish");

        expect($k->fresh()->status)->toBe(KnowledgeStatus::Draft);
    });

    it('archives existing entries', function () {
        $k = TeamKnowledge::factory()->published()->forTeam($this->team)
            ->state(['author_id' => $this->owner->id])
            ->create();

        $this->actingAs($this->owner)->post("/knowledges/{$k->id}/archive");

        expect($k->fresh()->status)->toBe(KnowledgeStatus::Archived);
    });
});

describe('delete', function () {
    it('lets the author soft-delete their entry', function () {
        $k = TeamKnowledge::factory()
            ->forTeam($this->team)
            ->state(['author_id' => $this->member->id])
            ->create();

        $this->actingAs($this->member)->delete("/knowledges/{$k->id}");

        $this->assertSoftDeleted('team_knowledges', ['id' => $k->id]);
    });

    it('blocks outsiders from deletion', function () {
        $k = TeamKnowledge::factory()->forTeam($this->team)->create();

        $response = $this->actingAs($this->outsider)->delete("/knowledges/{$k->id}");

        $response->assertForbidden();
    });
});
