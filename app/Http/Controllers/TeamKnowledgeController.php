<?php

namespace App\Http\Controllers;

use App\Enums\KnowledgeStatus;
use App\Models\Team;
use App\Models\TeamKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamKnowledgeController extends Controller
{
    private const LIST_LIMIT = 100;

    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('viewAny', [TeamKnowledge::class, $team]);

        $statusFilter = $request->string('status')->toString();
        $searchTerm = $request->string('q')->toString();

        $query = TeamKnowledge::query()
            ->where('team_id', $team->id)
            ->with('author:id,name')
            ->latest('updated_at');

        if ($statusFilter !== '' && in_array($statusFilter, KnowledgeStatus::values(), true)) {
            $query->where('status', $statusFilter);
        }

        if ($searchTerm !== '') {
            $query->where('title', 'like', '%'.$searchTerm.'%');
        }

        $knowledges = $query
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (TeamKnowledge $k) => $this->toListPayload($k));

        $user = Auth::user();

        return Inertia::render('Team/Knowledge/Index', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'knowledges' => $knowledges,
            'filters' => [
                'status' => $statusFilter,
                'q' => $searchTerm,
            ],
            'statuses' => $this->statusOptions(),
            'permissions' => [
                'can_create' => $user->can('create', [TeamKnowledge::class, $team]),
            ],
        ]);
    }

    public function create(Team $team): Response
    {
        Gate::authorize('create', [TeamKnowledge::class, $team]);

        return Inertia::render('Team/Knowledge/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'knowledge' => null,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('create', [TeamKnowledge::class, $team]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(KnowledgeStatus::values())],
        ]);

        $status = KnowledgeStatus::from($validated['status']);

        $knowledge = TeamKnowledge::create([
            'team_id' => $team->id,
            'author_id' => Auth::id(),
            'title' => $validated['title'],
            'slug' => $this->generateUniqueSlug($team, $validated['title']),
            'body' => $validated['body'],
            'status' => $status,
            'published_at' => $status === KnowledgeStatus::Published ? now() : null,
        ]);

        return redirect()
            ->route('team-knowledges.show', $knowledge)
            ->with('success', __('knowledge.created_successfully'));
    }

    public function show(TeamKnowledge $knowledge): Response
    {
        Gate::authorize('view', $knowledge);

        $knowledge->load(['author:id,name', 'team:id,name']);

        $user = Auth::user();

        return Inertia::render('Team/Knowledge/Show', [
            'knowledge' => $this->toDetailPayload($knowledge),
            'permissions' => [
                'can_update' => $user->can('update', $knowledge),
                'can_delete' => $user->can('delete', $knowledge),
            ],
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function edit(TeamKnowledge $knowledge): Response
    {
        Gate::authorize('update', $knowledge);

        $knowledge->load('team:id,name');

        return Inertia::render('Team/Knowledge/Edit', [
            'team' => [
                'id' => $knowledge->team->id,
                'name' => $knowledge->team->name,
            ],
            'knowledge' => $this->toDetailPayload($knowledge),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function update(Request $request, TeamKnowledge $knowledge): RedirectResponse
    {
        Gate::authorize('update', $knowledge);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(KnowledgeStatus::values())],
        ]);

        $newStatus = KnowledgeStatus::from($validated['status']);
        $wasPublished = $knowledge->status === KnowledgeStatus::Published;

        // Re-slug only when the title actually changes so URLs stay stable
        // across whitespace-only edits and save loops.
        $slug = $knowledge->title === $validated['title']
            ? $knowledge->slug
            : $this->generateUniqueSlug($knowledge->team, $validated['title'], $knowledge->id);

        $knowledge->fill([
            'title' => $validated['title'],
            'slug' => $slug,
            'body' => $validated['body'],
            'status' => $newStatus,
        ]);

        if ($newStatus === KnowledgeStatus::Published && ! $wasPublished) {
            $knowledge->published_at = now();
        }

        $knowledge->save();

        return redirect()
            ->route('team-knowledges.show', $knowledge)
            ->with('success', __('knowledge.updated_successfully'));
    }

    public function destroy(TeamKnowledge $knowledge): RedirectResponse
    {
        Gate::authorize('delete', $knowledge);

        $teamId = $knowledge->team_id;
        $knowledge->delete();

        return redirect()
            ->route('team-knowledges.index', ['team' => $teamId])
            ->with('success', __('knowledge.deleted_successfully'));
    }

    public function publish(TeamKnowledge $knowledge): RedirectResponse
    {
        Gate::authorize('update', $knowledge);

        if ($knowledge->status !== KnowledgeStatus::Published) {
            $knowledge->status = KnowledgeStatus::Published;
            $knowledge->published_at ??= now();
            $knowledge->save();
        }

        return back()->with('success', __('knowledge.published_successfully'));
    }

    public function unpublish(TeamKnowledge $knowledge): RedirectResponse
    {
        Gate::authorize('update', $knowledge);

        if ($knowledge->status === KnowledgeStatus::Published) {
            $knowledge->status = KnowledgeStatus::Draft;
            $knowledge->save();
        }

        return back()->with('success', __('knowledge.unpublished_successfully'));
    }

    public function archive(TeamKnowledge $knowledge): RedirectResponse
    {
        Gate::authorize('update', $knowledge);

        if ($knowledge->status !== KnowledgeStatus::Archived) {
            $knowledge->status = KnowledgeStatus::Archived;
            $knowledge->save();
        }

        return back()->with('success', __('knowledge.archived_successfully'));
    }

    /**
     * Ensure (team_id, slug) stays unique even when two entries share a
     * title. Collisions get a short random suffix; we intentionally do not
     * auto-increment because soft-deleted rows would cause gaps.
     */
    private function generateUniqueSlug(Team $team, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'knowledge';
        }

        $candidate = $base;
        $attempt = 0;

        while (true) {
            $exists = TeamKnowledge::query()
                ->where('team_id', $team->id)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if (! $exists) {
                return $candidate;
            }

            $attempt++;
            $candidate = $base.'-'.Str::lower(Str::random(4));

            if ($attempt > 5) {
                // Defensive — extremely unlikely but avoids an infinite loop.
                return $base.'-'.Str::lower(Str::random(8));
            }
        }
    }

    private function toListPayload(TeamKnowledge $k): array
    {
        return [
            'id' => $k->id,
            'slug' => $k->slug,
            'title' => $k->title,
            'status' => $k->status?->value,
            'author' => $k->author ? [
                'id' => $k->author->id,
                'name' => $k->author->name,
            ] : null,
            'published_at' => optional($k->published_at)->toIso8601String(),
            'updated_at' => optional($k->updated_at)->toIso8601String(),
        ];
    }

    private function toDetailPayload(TeamKnowledge $k): array
    {
        return [
            'id' => $k->id,
            'team_id' => $k->team_id,
            'slug' => $k->slug,
            'title' => $k->title,
            'body' => $k->body,
            'status' => $k->status?->value,
            'author' => $k->author ? [
                'id' => $k->author->id,
                'name' => $k->author->name,
            ] : null,
            'published_at' => optional($k->published_at)->toIso8601String(),
            'indexed_at' => optional($k->indexed_at)->toIso8601String(),
            'updated_at' => optional($k->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(fn (KnowledgeStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ], KnowledgeStatus::cases());
    }
}
