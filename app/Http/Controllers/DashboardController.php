<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // ユーザーの全チーム（オーナー + メンバー）を取得
        $ownedTeams = $user->ownedTeams()
            ->withCount('members')
            ->latest()
            ->get();

        $memberTeams = $user->teams()
            ->withCount('members')
            ->with('owner')
            ->latest()
            ->get();

        // チーム一覧をマージ
        $teams = $ownedTeams->merge($memberTeams)->unique('id')->map(function ($team) use ($user) {
            return [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'members_count' => $team->members_count,
                'is_owner' => $team->owner_id === $user->id,
                'role' => $team->owner_id === $user->id ? 'owner' : $team->pivot?->role ?? 'member',
                'created_at' => $team->created_at->toDateTimeString(),
            ];
        });

        return Inertia::render('Dashboard', [
            'teams' => $teams->values(),
        ]);
    }
}
