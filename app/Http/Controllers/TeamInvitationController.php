<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    /**
     * Show the invitation acceptance page.
     */
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = TeamInvitation::with(['team', 'inviter'])
            ->where('token', $token)
            ->firstOrFail();

        // Check if expired
        if ($invitation->isExpired()) {
            return redirect()->route('dashboard')
                ->with('error', __('teams.invitation_expired'));
        }

        // Check if already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('teams.show', $invitation->team)
                ->with('info', __('teams.invitation_already_accepted'));
        }

        // If user not logged in, redirect to login
        if (! Auth::check()) {
            return redirect()->route('login')
                ->with('message', __('teams.login_to_accept_invitation'));
        }

        // Check if email matches authenticated user
        $user = Auth::user();
        if ($user->email !== $invitation->email) {
            return redirect()->route('dashboard')
                ->with('error', __('teams.invitation_email_mismatch'));
        }

        return Inertia::render('Teams/AcceptInvitation', [
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'token' => $invitation->token,
                'team' => [
                    'id' => $invitation->team->id,
                    'name' => $invitation->team->name,
                    'description' => $invitation->team->description,
                ],
                'inviter' => [
                    'name' => $invitation->inviter->name,
                    'email' => $invitation->inviter->email,
                ],
            ],
        ]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(string $token): RedirectResponse
    {
        $invitation = TeamInvitation::with('team')
            ->where('token', $token)
            ->firstOrFail();

        // Check if expired
        if ($invitation->isExpired()) {
            return redirect()->route('dashboard')
                ->with('error', __('teams.invitation_expired'));
        }

        // Check if already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('teams.show', $invitation->team)
                ->with('info', __('teams.invitation_already_accepted'));
        }

        $user = Auth::user();

        // Check if email matches
        if ($user->email !== $invitation->email) {
            return redirect()->route('dashboard')
                ->with('error', __('teams.invitation_email_mismatch'));
        }

        // Check if already a member
        if ($invitation->team->members()->where('user_id', $user->id)->exists()) {
            $invitation->markAsAccepted();

            return redirect()->route('teams.show', $invitation->team)
                ->with('info', __('teams.already_team_member'));
        }

        // Add user to team
        $invitation->team->members()->attach($user->id, [
            'role' => $invitation->role,
        ]);

        // Mark invitation as accepted
        $invitation->markAsAccepted();

        return redirect()->route('teams.show', $invitation->team)
            ->with('success', __('teams.invitation_accepted'));
    }

    /**
     * Decline the invitation.
     */
    public function decline(string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        $invitation->delete();

        return redirect()->route('dashboard')
            ->with('success', __('teams.invitation_declined'));
    }

    /**
     * Show registration page for new users with team invitation.
     */
    public function showRegister(string $token): Response|RedirectResponse
    {
        $invitation = TeamInvitation::with(['team', 'inviter'])
            ->where('token', $token)
            ->firstOrFail();

        // Check if expired
        if ($invitation->isExpired()) {
            return redirect()->route('home')
                ->with('error', __('teams.invitation_expired'));
        }

        // Check if already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('login')
                ->with('info', __('teams.invitation_already_accepted'));
        }

        // Check if user already exists
        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->route('login')
                ->with('message', 'アカウントが既に存在します。ログインして招待を承認してください。');
        }

        return Inertia::render('auth/RegisterWithTeamInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'team' => [
                'name' => $invitation->team->name,
                'description' => $invitation->team->description,
            ],
            'inviter' => [
                'name' => $invitation->inviter->name,
            ],
        ]);
    }

    /**
     * Handle registration for new users with team invitation.
     */
    public function storeRegister(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::with(['team'])
            ->where('token', $token)
            ->firstOrFail();

        // Verify invitation is still valid
        if ($invitation->isExpired()) {
            return redirect()->route('home')
                ->with('error', __('teams.invitation_expired'));
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('login')
                ->with('error', __('teams.invitation_already_accepted'));
        }

        // Check if user already exists
        if (User::where('email', $invitation->email)->exists()) {
            return redirect()->route('login')
                ->with('error', 'このメールアドレスは既に登録されています。');
        }

        // Validate
        $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Create user
        $user = User::create([
            'name' => $request->name,
            'email' => $invitation->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(), // Auto-verify
            'role' => UserRole::USER,
        ]);

        // Add user to team
        $invitation->team->members()->attach($user->id, [
            'role' => $invitation->role,
        ]);

        // Mark invitation as accepted
        $invitation->markAsAccepted();

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('teams.show', $invitation->team)
            ->with('success', 'アカウントを作成し、チームに参加しました！');
    }
}
