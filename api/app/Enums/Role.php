<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Numeric weight of the role, used to compare roles.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /**
     * Determine whether this role is equal to or higher than the given role.
     */
    public function atLeast(Role $min): bool
    {
        return $this->level() >= $min->level();
    }
}
