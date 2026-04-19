<?php

namespace App\Models;

use App\Enums\MessageRole;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'model',
        'prompt_tokens',
        'completion_tokens',
    ];

    /**
     * Parent relationships whose timestamps should be updated when this
     * model is saved or destroyed. Keeping the conversation's updated_at
     * fresh lets us sort the sidebar list by most-recent activity using a
     * single column.
     *
     * @var array<int, string>
     */
    protected $touches = ['conversation'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    /**
     * Conversation this message belongs to
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
