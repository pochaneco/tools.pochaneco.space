<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamKnowledge;
use App\Models\User;

class TeamKnowledgePolicy
{
    /**
     * Any member of the team can list the team's knowledge entries
     * (including drafts/archived).
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $team->hasMember($user);
    }

    public function view(User $user, TeamKnowledge $knowledge): bool
    {
        return $knowledge->team->hasMember($user);
    }

    /**
     * Any member with editData permission can create new entries.
     * Viewers/guests can't seed the knowledge base.
     */
    public function create(User $user, Team $team): bool
    {
        if (! $team->hasMember($user)) {
            return false;
        }

        $role = $team->getUserRole($user);

        return $role !== null && $role->can('editData');
    }

    /**
     * Authors may always edit their own entries; otherwise editData is
     * required. Team owners inherit editData via TeamRole::OWNER.
     */
    public function update(User $user, TeamKnowledge $knowledge): bool
    {
        if (! $knowledge->team->hasMember($user)) {
            return false;
        }

        if ($knowledge->author_id === $user->id) {
            return true;
        }

        $role = $knowledge->team->getUserRole($user);

        return $role !== null && $role->can('editData');
    }

    public function delete(User $user, TeamKnowledge $knowledge): bool
    {
        return $this->update($user, $knowledge);
    }

    public function restore(User $user, TeamKnowledge $knowledge): bool
    {
        return $this->update($user, $knowledge);
    }

    public function forceDelete(User $user, TeamKnowledge $knowledge): bool
    {
        return $knowledge->team->isOwner($user);
    }
}
