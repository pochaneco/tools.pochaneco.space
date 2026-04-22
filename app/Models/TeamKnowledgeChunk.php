<?php

namespace App\Models;

use Database\Factories\TeamKnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamKnowledgeChunk extends Model
{
    /** @use HasFactory<TeamKnowledgeChunkFactory> */
    use HasFactory;

    // Pin the table explicitly so the uncountable-noun pluralisation
    // quirk on `knowledge` can't surprise us the way it did on the
    // parent model.
    protected $table = 'team_knowledge_chunks';

    protected $fillable = [
        'knowledge_id',
        'team_id',
        'chunk_index',
        'heading_path',
        'content',
        'token_count',
        'embedding',
        'embedding_model',
        'embedding_dims',
    ];

    protected $casts = [
        'embedding' => 'array',
        'chunk_index' => 'integer',
        'token_count' => 'integer',
        'embedding_dims' => 'integer',
    ];

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(TeamKnowledge::class, 'knowledge_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
