<?php

namespace App\Listeners;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Auth\Events\Login;

class EnsureUserHasDefaultTeam
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        // ユーザーがオーナーまたはメンバーとして所属しているチームがあるかチェック
        $hasTeams = $user->ownedTeams()->exists() || $user->teams()->exists();

        if (!$hasTeams) {
            // デフォルトチームを作成
            $team = Team::create([
                'name' => __('teams.default_team_name', ['name' => $user->name]),
                'description' => __('teams.default_team_description'),
                'owner_id' => $user->id,
            ]);

            // オーナーとしてチームに参加
            $team->members()->attach($user->id, [
                'role' => TeamRole::OWNER->value,
            ]);
        }
    }
}
