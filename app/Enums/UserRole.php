<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case USER = 'user';
    case MODERATOR = 'moderator';
    case GUEST = 'guest';
    case VIP = 'vip';
    case MONITOR = 'monitor';

    /**
     * Get all role values
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Get role label
     */
    public function label(): string
    {
        return match($this) {
            self::ADMIN => 'Administrator',
            self::MODERATOR => 'Moderator',
            self::USER => 'User',
            self::GUEST => 'Guest',
            self::VIP => 'VIP',
            self::MONITOR => 'Monitor',
        };
    }

    /**
     * Check if role has admin privileges
     */
    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Check if role has moderator or higher privileges
     */
    public function isModerator(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR]);
    }

    /**
     * Check if role is at least user level
     */
    public function isUser(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR, self::USER, self::VIP, self::MONITOR]);
    }

    /**
     * サービスを無料で利用できるかどうかを判定する
     */
    public function canUseServiceForFree(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR, self::VIP, self::MONITOR]);
    }
}
