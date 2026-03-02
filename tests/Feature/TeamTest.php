<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->nonMember = User::factory()->create();

    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);
    $this->team->members()->attach($this->member->id, ['role' => TeamRole::MEMBER->value]);
});

describe('Team creation', function () {
    it('can create a team', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/teams', [
            'name' => 'Test Team',
            'description' => 'A test team description',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('teams', [
            'name' => 'Test Team',
            'description' => 'A test team description',
            'owner_id' => $user->id,
        ]);
    });

    it('requires authentication to create a team', function () {
        $response = $this->post('/teams', [
            'name' => 'Test Team',
        ]);

        $response->assertRedirect('/login');
    });

    it('requires a name to create a team', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/teams', [
            'description' => 'A test team description',
        ]);

        $response->assertInvalid(['name']);
    });

    it('automatically adds creator as owner', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/teams', [
            'name' => 'Test Team',
        ]);

        $team = Team::where('name', 'Test Team')->first();

        expect($team->owner_id)->toBe($user->id);
        expect($team->members()->where('user_id', $user->id)->exists())->toBeTrue();
        expect($team->getUserRole($user))->toBe(TeamRole::OWNER);
    });
});

describe('Team viewing', function () {
    it('allows owner to view team', function () {
        $response = $this->actingAs($this->owner)->get("/teams/{$this->team->id}");

        $response->assertSuccessful();
    });

    it('allows member to view team', function () {
        $response = $this->actingAs($this->member)->get("/teams/{$this->team->id}");

        $response->assertSuccessful();
    });

    it('prevents non-member from viewing team', function () {
        $response = $this->actingAs($this->nonMember)->get("/teams/{$this->team->id}");

        $response->assertForbidden();
    });

    it('requires authentication to view team', function () {
        $response = $this->get("/teams/{$this->team->id}");

        $response->assertRedirect('/login');
    });

    it('shows team list to authenticated users', function () {
        $response = $this->actingAs($this->owner)->get('/teams');

        $response->assertSuccessful();
    });
});

describe('Team updating', function () {
    it('allows owner to update team', function () {
        $response = $this->actingAs($this->owner)->put("/teams/{$this->team->id}", [
            'name' => 'Updated Team Name',
            'description' => 'Updated description',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('teams', [
            'id' => $this->team->id,
            'name' => 'Updated Team Name',
            'description' => 'Updated description',
        ]);
    });

    it('prevents member from updating team', function () {
        $response = $this->actingAs($this->member)->put("/teams/{$this->team->id}", [
            'name' => 'Updated Team Name',
        ]);

        $response->assertForbidden();
    });

    it('prevents non-member from updating team', function () {
        $response = $this->actingAs($this->nonMember)->put("/teams/{$this->team->id}", [
            'name' => 'Updated Team Name',
        ]);

        $response->assertForbidden();
    });
});

describe('Team deletion', function () {
    it('allows owner to delete team', function () {
        $response = $this->actingAs($this->owner)->delete("/teams/{$this->team->id}");

        $response->assertRedirect();

        $this->assertSoftDeleted('teams', [
            'id' => $this->team->id,
        ]);
    });

    it('prevents member from deleting team', function () {
        $response = $this->actingAs($this->member)->delete("/teams/{$this->team->id}");

        $response->assertForbidden();
    });

    it('prevents non-member from deleting team', function () {
        $response = $this->actingAs($this->nonMember)->delete("/teams/{$this->team->id}");

        $response->assertForbidden();
    });
});

describe('Team member management', function () {
    it('allows owner to add member by email', function () {
        $newUserEmail = 'newuser@example.com';

        $response = $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $newUserEmail,
            'role' => 'member',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('team_invitations', [
            'email' => $newUserEmail,
            'team_id' => $this->team->id,
            'invited_by' => $this->owner->id,
            'role' => 'member',
        ]);
    });

    it('allows resending invitation to the same email', function () {
        $email = 'test@example.com';

        // First invitation
        $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $email,
            'role' => 'member',
        ]);

        $firstInvitation = TeamInvitation::where('email', $email)
            ->where('team_id', $this->team->id)
            ->first();

        // Second invitation (should delete first and create new one)
        $response = $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $email,
            'role' => 'member',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Check that old invitation was deleted
        $this->assertDatabaseMissing('team_invitations', [
            'id' => $firstInvitation->id,
        ]);

        // Check that new invitation exists
        expect(TeamInvitation::where('email', $email)
            ->where('team_id', $this->team->id)
            ->where('accepted_at', null)
            ->exists())->toBeTrue();
    });

    it('prevents inviting existing member', function () {
        $response = $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $this->member->email,
            'role' => 'member',
        ]);

        $response->assertSessionHas('error');
    });

    it('allows owner to remove member', function () {
        $response = $this->actingAs($this->owner)->delete("/teams/{$this->team->id}/members/{$this->member->id}");

        $response->assertRedirect();

        expect($this->team->members()->where('user_id', $this->member->id)->exists())->toBeFalse();
    });

    it('prevents member from removing other members', function () {
        $anotherMember = User::factory()->create();
        $this->team->members()->attach($anotherMember->id, ['role' => TeamRole::MEMBER->value]);

        $response = $this->actingAs($this->member)->delete("/teams/{$this->team->id}/members/{$anotherMember->id}");

        $response->assertForbidden();
    });

    it('prevents removing owner', function () {
        $response = $this->actingAs($this->owner)->delete("/teams/{$this->team->id}/members/{$this->owner->id}");

        $response->assertSessionHas('error');
    });
});

describe('Team roles', function () {
    it('correctly identifies owner role', function () {
        expect($this->team->isOwner($this->owner))->toBeTrue();
        expect($this->team->isOwner($this->member))->toBeFalse();
    });

    it('correctly identifies team members', function () {
        expect($this->team->hasMember($this->owner))->toBeTrue();
        expect($this->team->hasMember($this->member))->toBeTrue();
        expect($this->team->hasMember($this->nonMember))->toBeFalse();
    });

    it('correctly retrieves user role', function () {
        expect($this->team->getUserRole($this->owner))->toBe(TeamRole::OWNER);
        expect($this->team->getUserRole($this->member))->toBe(TeamRole::MEMBER);
        expect($this->team->getUserRole($this->nonMember))->toBeNull();
    });
});
