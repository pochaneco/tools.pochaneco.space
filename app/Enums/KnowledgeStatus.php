<?php

namespace App\Enums;

enum KnowledgeStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Only published entries are fed to the embedding index. Draft and
     * archived entries remain readable in the UI but are not discoverable
     * by the AI search tool.
     */
    public function isIndexable(): bool
    {
        return $this === self::Published;
    }
}
