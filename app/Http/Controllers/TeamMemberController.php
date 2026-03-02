<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    /**
     * Store a new team member (invite user by email).
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('invite', $team);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'role' => [
                'required',
                Rule::enum(TeamRole::class),
            ],
        ]);

        // Check if user already in team
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser && $team->members()->where('user_id', $existingUser->id)->exists()) {
            return redirect()->route('teams.show', $team)
                ->with('error', __('teams.user_already_member'));
        }

        // Check if invitation already sent and delete it to allow resending
        $existingInvitation = TeamInvitation::where('email', $validated['email'])
            ->where('team_id', $team->id)
            ->where('accepted_at', null)
            ->first();

        $isResending = $existingInvitation !== null;

        if ($existingInvitation) {
            $existingInvitation->delete();
        }

        // Create invitation
        $invitation = TeamInvitation::create([
            'email' => $validated['email'],
            'team_id' => $team->id,
            'invited_by' => Auth::id(),
            'token' => TeamInvitation::generateToken(),
            'role' => $validated['role'],
            'expires_at' => now()->addDays(7),
        ]);

        // Determine if new user or existing user
        $isNewUser = $existingUser === null;

        // Send invitation email
        Mail::to($validated['email'])->send(new TeamInvitationMail($invitation, $isNewUser));

        $message = $isResending ? __('teams.invitation_resent') : __('teams.invitation_sent');

        return redirect()->route('teams.show', $team)
            ->with('success', $message);
    }

    /**
     * Update a team member's role.
     */
    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        // Cannot change owner's role
        if ($team->isOwner($user)) {
            return redirect()->route('teams.show', $team)
                ->with('error', __('teams.cannot_change_owner_role'));
        }

        $validated = $request->validate([
            'role' => [
                'required',
                Rule::enum(TeamRole::class),
            ],
        ]);

        $team->members()->updateExistingPivot($user->id, [
            'role' => $validated['role'],
        ]);

        return redirect()->route('teams.show', $team)
            ->with('success', __('teams.member_role_updated'));
    }

    /**
     * Remove a team member.
     */
    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        // Cannot remove owner
        if ($team->isOwner($user)) {
            return redirect()->route('teams.show', $team)
                ->with('error', __('teams.cannot_remove_owner'));
        }

        $team->members()->detach($user->id);

        return redirect()->route('teams.show', $team)
            ->with('success', __('teams.member_removed_successfully'));
    }
}
