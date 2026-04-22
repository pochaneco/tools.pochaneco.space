<?php

namespace App\Models;

use App\Enums\KnowledgeStatus;
use Database\Factories\TeamKnowledgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeamKnowledge extends Model
{
    /** @use HasFactory<TeamKnowledgeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'author_id',
        'title',
        'slug',
        'body',
        'status',
        'published_at',
        'indexed_at',
    ];

    protected $casts = [
        'status' => KnowledgeStatus::class,
        'published_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(TeamKnowledgeChunk::class, 'knowledge_id')
            ->orderBy('chunk_index');
    }
}
