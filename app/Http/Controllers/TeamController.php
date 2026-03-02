<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(): Response
    {
        $user = Auth::user();

        $teams = $user->teams()
            ->with('owner')
            ->withCount('members')
            ->latest()
            ->get()
            ->map(function ($team) use ($user) {
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'description' => $team->description,
                    'owner' => [
                        'id' => $team->owner->id,
                        'name' => $team->owner->name,
                    ],
                    'members_count' => $team->members_count,
                    'role' => $user->getTeamRole($team)->value,
                    'is_owner' => $user->ownsTeam($team),
                    'created_at' => $team->created_at->toIso8601String(),
                ];
            });

        return Inertia::render('Teams/Index', [
            'teams' => $teams,
        ]);
    }

    /**
     * Show the form for creating a new team.
     */
    public function create(): Response
    {
        Gate::authorize('create', Team::class);

        return Inertia::render('Teams/Create');
    }

    /**
     * Store a newly created team in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Team::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team = Team::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'owner_id' => Auth::id(),
        ]);

        // Add owner to members with owner role
        $team->members()->attach(Auth::id(), [
            'role' => TeamRole::OWNER->value,
        ]);

        return redirect()->route('teams.show', $team)
            ->with('success', __('teams.created_successfully'));
    }

    /**
     * Display the specified team.
     */
    public function show(Team $team): Response
    {
        Gate::authorize('view', $team);

        $team->load(['owner', 'members']);

        $user = Auth::user();

        return Inertia::render('Teams/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'owner' => [
                    'id' => $team->owner->id,
                    'name' => $team->owner->name,
                    'email' => $team->owner->email,
                ],
                'members' => $team->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'role' => $member->pivot->role,
                        'joined_at' => $member->pivot->created_at->toIso8601String(),
                    ];
                }),
                'created_at' => $team->created_at->toIso8601String(),
                'updated_at' => $team->updated_at->toIso8601String(),
            ],
            'permissions' => [
                'can_update' => $user->can('update', $team),
                'can_delete' => $user->can('delete', $team),
                'can_invite' => $user->can('invite', $team),
                'can_remove_member' => $user->can('removeMember', $team),
                'can_manage_settings' => $user->can('manageSettings', $team),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified team.
     */
    public function edit(Team $team): Response
    {
        Gate::authorize('update', $team);

        $user = Auth::user();

        return Inertia::render('Teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
            ],
            'permissions' => [
                'can_delete' => $user->can('delete', $team),
            ],
        ]);
    }

    /**
     * Update the specified team in storage.
     */
    public function update(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team->update($validated);

        return redirect()->route('teams.show', $team)
            ->with('success', __('teams.updated_successfully'));
    }

    /**
     * Remove the specified team from storage.
     */
    public function destroy(Team $team): RedirectResponse
    {
        Gate::authorize('delete', $team);

        $team->delete();

        return redirect()->route('teams.index')
            ->with('success', __('teams.deleted_successfully'));
    }
}
