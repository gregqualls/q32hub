<?php

namespace App\Enums;

enum AllergenPresence: string
{
    case Contains = 'contains';
    case MayContain = 'may_contain';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Contains',
            self::MayContain => 'May contain',
        };
    }
}
