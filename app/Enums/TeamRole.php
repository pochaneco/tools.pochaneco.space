<?php

namespace App\Enums;

enum TeamRole: string
{
    case OWNER = 'owner';
    case MEMBER = 'member';

    /**
     * Get all role values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get role label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::MEMBER => 'Member',
        };
    }

    /**
     * Check if role is owner
     */
    public function isOwner(): bool
    {
        return $this === self::OWNER;
    }

    /**
     * Check if role is member
     */
    public function isMember(): bool
    {
        return $this === self::MEMBER;
    }

    /**
     * Check if role has permission for action
     */
    public function can(string $permission): bool
    {
        return match ($this) {
            self::OWNER => true, // Owner has all permissions
            self::MEMBER => in_array($permission, [
                'view',
                'viewData',
                'editData',
            ]),
        };
    }
}
