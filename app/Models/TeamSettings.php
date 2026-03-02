<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamSettings extends Model
{
    protected $fillable = [
        'team_id',
        'openai_api_key',
        'claude_api_key',
        'google_api_key',
        'ai_model',
        'ai_settings',
    ];

    protected $casts = [
        'openai_api_key' => 'encrypted',
        'claude_api_key' => 'encrypted',
        'google_api_key' => 'encrypted',
        'ai_settings' => 'array',
    ];

    /**
     * Get the team that owns the settings.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Check if team has any API key configured.
     */
    public function hasAnyApiKey(): bool
    {
        return $this->openai_api_key || $this->claude_api_key || $this->google_api_key;
    }

    /**
     * Get the active API key based on the selected model.
     */
    public function getActiveApiKey(): ?string
    {
        if (! $this->ai_model) {
            return $this->openai_api_key ?? $this->claude_api_key ?? $this->google_api_key;
        }

        if (str_starts_with($this->ai_model, 'gpt-') || str_starts_with($this->ai_model, 'o1-')) {
            return $this->openai_api_key;
        }

        if (str_starts_with($this->ai_model, 'claude-')) {
            return $this->claude_api_key;
        }

        if (str_starts_with($this->ai_model, 'gemini-')) {
            return $this->google_api_key;
        }

        return null;
    }
}
