<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any teams.
     * All authenticated users can view teams list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the team.
     * Team members can view the team.
     */
    public function view(User $user, Team $team): bool
    {
        return $team->hasMember($user);
    }

    /**
     * Determine whether the user can create teams.
     * All authenticated users can create teams.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the team.
     * Only owner can update team details.
     */
    public function update(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can delete the team.
     * Only owner can delete the team.
     */
    public function delete(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can restore the team.
     * Only owner can restore soft-deleted team.
     */
    public function restore(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can permanently delete the team.
     * Only owner can force delete the team.
     */
    public function forceDelete(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can invite members to the team.
     * Only owner can invite members.
     */
    public function invite(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can remove members from the team.
     * Only owner can remove members.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can manage team settings.
     * Only owner can manage settings.
     */
    public function manageSettings(User $user, Team $team): bool
    {
        return $team->isOwner($user);
    }

    /**
     * Determine whether the user can view team data.
     * All team members can view data.
     */
    public function viewData(User $user, Team $team): bool
    {
        if (! $team->hasMember($user)) {
            return false;
        }

        $role = $team->getUserRole($user);

        return $role && $role->can('viewData');
    }

    /**
     * Determine whether the user can edit team data.
     * Members with editData permission can edit.
     */
    public function editData(User $user, Team $team): bool
    {
        if (! $team->hasMember($user)) {
            return false;
        }

        $role = $team->getUserRole($user);

        return $role && $role->can('editData');
    }
}
