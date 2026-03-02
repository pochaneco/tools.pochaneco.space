<?php

use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
});

describe('Team invitation creation', function () {
    it('creates invitation with valid data', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->invitedBy($this->owner)
            ->forEmail('test@example.com')
            ->create();

        expect($invitation->email)->toBe('test@example.com');
        expect($invitation->team_id)->toBe($this->team->id);
        expect($invitation->invited_by)->toBe($this->owner->id);
        expect($invitation->role)->toBe('member');
        expect($invitation->token)->not->toBeNull();
        expect($invitation->expires_at)->not->toBeNull();
        expect($invitation->accepted_at)->toBeNull();
    });

    it('generates unique tokens', function () {
        $token1 = TeamInvitation::generateToken();
        $token2 = TeamInvitation::generateToken();

        expect($token1)->not->toBe($token2);
        expect(strlen($token1))->toBe(64);
    });

    it('sends invitation email for new user', function () {
        Mail::fake();

        $email = 'newuser@example.com';

        $response = $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $email,
            'role' => 'member',
        ]);

        $response->assertRedirect();

        Mail::assertQueued(TeamInvitationMail::class, function ($mail) use ($email) {
            return $mail->hasTo($email) && $mail->isNewUser === true;
        });
    });

    it('sends invitation email for existing user', function () {
        Mail::fake();

        $existingUser = User::factory()->create();

        $response = $this->actingAs($this->owner)->post("/teams/{$this->team->id}/members", [
            'email' => $existingUser->email,
            'role' => 'member',
        ]);

        $response->assertRedirect();

        Mail::assertQueued(TeamInvitationMail::class, function ($mail) use ($existingUser) {
            return $mail->hasTo($existingUser->email) && $mail->isNewUser === false;
        });
    });
});

describe('Team invitation expiration', function () {
    it('correctly identifies expired invitations', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->expired()
            ->create();

        expect($invitation->isExpired())->toBeTrue();
    });

    it('correctly identifies valid invitations', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->create();

        expect($invitation->isExpired())->toBeFalse();
    });
});

describe('Team invitation acceptance', function () {
    it('allows existing user to accept invitation', function () {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->invitedBy($this->owner)
            ->forEmail($user->email)
            ->create();

        $response = $this->actingAs($user)->post("/teams/invitations/{$invitation->token}/accept");

        $response->assertRedirect("/teams/{$this->team->id}");

        expect($this->team->hasMember($user))->toBeTrue();
        $invitation->refresh();
        expect($invitation->isAccepted())->toBeTrue();
    });

    it('prevents accepting expired invitation', function () {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($user->email)
            ->expired()
            ->create();

        $response = $this->actingAs($user)->post("/teams/invitations/{$invitation->token}/accept");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error');

        expect($this->team->hasMember($user))->toBeFalse();
    });

    it('prevents accepting invitation with wrong email', function () {
        $user1 = User::factory()->create(['email' => 'user1@example.com']);
        $user2 = User::factory()->create(['email' => 'user2@example.com']);

        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($user1->email)
            ->create();

        $response = $this->actingAs($user2)->post("/teams/invitations/{$invitation->token}/accept");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error');
    });

    it('marks invitation as accepted only once', function () {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($user->email)
            ->create();

        // First acceptance
        $this->actingAs($user)->post("/teams/invitations/{$invitation->token}/accept");

        // Second acceptance attempt
        $response = $this->actingAs($user)->post("/teams/invitations/{$invitation->token}/accept");

        $response->assertRedirect("/teams/{$this->team->id}");
    });
});

describe('Team invitation registration', function () {
    it('shows registration page for new users', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail('newuser@example.com')
            ->create();

        $response = $this->get("/teams/invitations/{$invitation->token}/register");

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page
            ->component('auth/RegisterWithTeamInvitation')
            ->has('token')
            ->has('email')
            ->has('team')
        );
    });

    it('allows new user to register with invitation', function () {
        $email = 'newuser@example.com';
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($email)
            ->create();

        $response = $this->post("/teams/invitations/{$invitation->token}/register", [
            'name' => 'New User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect("/teams/{$this->team->id}");

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'name' => 'New User',
        ]);

        $user = User::where('email', $email)->first();
        expect($this->team->hasMember($user))->toBeTrue();

        $invitation->refresh();
        expect($invitation->isAccepted())->toBeTrue();
    });

    it('prevents registration with expired invitation', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail('newuser@example.com')
            ->expired()
            ->create();

        $response = $this->post("/teams/invitations/{$invitation->token}/register", [
            'name' => 'New User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
    });

    it('redirects existing users to login', function () {
        $existingUser = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($existingUser->email)
            ->create();

        $response = $this->get("/teams/invitations/{$invitation->token}/register");

        $response->assertRedirect('/login');
    });
});

describe('Team invitation decline', function () {
    it('allows user to decline invitation', function () {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($user->email)
            ->create();

        $response = $this->actingAs($user)->post("/teams/invitations/{$invitation->token}/decline");

        $response->assertRedirect('/dashboard');

        $this->assertDatabaseMissing('team_invitations', [
            'id' => $invitation->id,
        ]);
    });
});

describe('Team invitation viewing', function () {
    it('shows invitation details to authenticated user with matching email', function () {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail($user->email)
            ->create();

        $response = $this->actingAs($user)->get("/teams/invitations/{$invitation->token}");

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page
            ->component('Teams/AcceptInvitation')
            ->has('invitation')
        );
    });

    it('redirects unauthenticated user to login', function () {
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->create();

        $response = $this->get("/teams/invitations/{$invitation->token}");

        $response->assertRedirect('/login');
    });

    it('prevents user with different email from viewing invitation', function () {
        $user = User::factory()->create(['email' => 'different@example.com']);
        $invitation = TeamInvitation::factory()
            ->forTeam($this->team)
            ->forEmail('invited@example.com')
            ->create();

        $response = $this->actingAs($user)->get("/teams/invitations/{$invitation->token}");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error');
    });
});
