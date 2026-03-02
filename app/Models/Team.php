<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'owner_id',
    ];

    /**
     * Owner of the team
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * All members (including owner) with their roles
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Team settings (API keys, AI configuration, etc.)
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TeamSettings::class);
    }

    /**
     * Check if user is a member of this team
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Get user's role in this team
     */
    public function getUserRole(User $user): ?TeamRole
    {
        $member = $this->members()->where('user_id', $user->id)->first();

        return $member ? TeamRole::from($member->pivot->role) : null;
    }

    /**
     * Check if user is owner of this team
     */
    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }
}
