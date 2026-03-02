<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->for($this->owner, 'owner')->create();
    $this->member = User::factory()->create();

    // Add member to team
    $this->team->members()->attach($this->member->id, ['role' => TeamRole::MEMBER->value]);
});

describe('Team member role management', function () {
    it('allows owner to change member role', function () {
        $response = $this->actingAs($this->owner)
            ->patch("/teams/{$this->team->id}/members/{$this->member->id}", [
                'role' => TeamRole::OWNER->value,
            ]);

        $response->assertRedirect(route('teams.show', $this->team));

        expect($this->team->members()->where('user_id', $this->member->id)->first()->pivot->role)
            ->toBe(TeamRole::OWNER->value);
    });

    it('prevents non-owner from changing member role', function () {
        $anotherMember = User::factory()->create();
        $this->team->members()->attach($anotherMember->id, ['role' => TeamRole::MEMBER->value]);

        $response = $this->actingAs($anotherMember)
            ->patch("/teams/{$this->team->id}/members/{$this->member->id}", [
                'role' => TeamRole::OWNER->value,
            ]);

        $response->assertForbidden();
    });

    it('validates role enum', function () {
        $response = $this->actingAs($this->owner)
            ->patch("/teams/{$this->team->id}/members/{$this->member->id}", [
                'role' => 'invalid_role',
            ]);

        $response->assertSessionHasErrors('role');
    });

    it('prevents changing owner role', function () {
        // Add owner as member first
        $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);

        $response = $this->actingAs($this->owner)
            ->patch("/teams/{$this->team->id}/members/{$this->owner->id}", [
                'role' => TeamRole::MEMBER->value,
            ]);

        $response->assertRedirect(route('teams.show', $this->team));
        $response->assertSessionHas('error');

        // Verify owner role hasn't changed
        $this->team->refresh();
        expect($this->team->getUserRole($this->owner))
            ->toBe(TeamRole::OWNER);
    });
});

describe('Team member removal', function () {
    it('allows owner to remove member', function () {
        $response = $this->actingAs($this->owner)
            ->delete("/teams/{$this->team->id}/members/{$this->member->id}");

        $response->assertRedirect(route('teams.show', $this->team));

        expect($this->team->members()->where('user_id', $this->member->id)->exists())
            ->toBeFalse();
    });

    it('prevents removing team owner', function () {
        $response = $this->actingAs($this->owner)
            ->delete("/teams/{$this->team->id}/members/{$this->owner->id}");

        $response->assertRedirect(route('teams.show', $this->team));
        $response->assertSessionHas('error');
    });

    it('prevents non-owner from removing members', function () {
        $anotherMember = User::factory()->create();
        $this->team->members()->attach($anotherMember->id, ['role' => TeamRole::MEMBER->value]);

        $response = $this->actingAs($anotherMember)
            ->delete("/teams/{$this->team->id}/members/{$this->member->id}");

        $response->assertForbidden();
    });
});

describe('Team invitation validation', function () {
    it('validates email is required for invitation', function () {
        $response = $this->actingAs($this->owner)
            ->post("/teams/{$this->team->id}/members", [
                'role' => TeamRole::MEMBER->value,
            ]);

        $response->assertSessionHasErrors('email');
    });

    it('validates email format for invitation', function () {
        $response = $this->actingAs($this->owner)
            ->post("/teams/{$this->team->id}/members", [
                'email' => 'invalid-email',
                'role' => TeamRole::MEMBER->value,
            ]);

        $response->assertSessionHasErrors('email');
    });

    it('validates role is required for invitation', function () {
        $response = $this->actingAs($this->owner)
            ->post("/teams/{$this->team->id}/members", [
                'email' => 'newuser@example.com',
            ]);

        $response->assertSessionHasErrors('role');
    });

    it('validates role enum for invitation', function () {
        $response = $this->actingAs($this->owner)
            ->post("/teams/{$this->team->id}/members", [
                'email' => 'newuser@example.com',
                'role' => 'invalid_role',
            ]);

        $response->assertSessionHasErrors('role');
    });
});
