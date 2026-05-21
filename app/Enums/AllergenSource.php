<?php

namespace App\Enums;

enum AllergenSource: string
{
    case AiAuto = 'ai_auto';
    case AiSuggested = 'ai_suggested';
    case HumanConfirmed = 'human_confirmed';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::AiAuto => 'AI tagged',
            self::AiSuggested => 'AI suggested',
            self::HumanConfirmed => 'Confirmed',
            self::Imported => 'From import',
        };
    }

    public function isConfirmed(): bool
    {
        return $this === self::HumanConfirmed;
    }
}
