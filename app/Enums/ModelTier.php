<?php

namespace App\Enums;

enum ModelTier: string
{
    case Fast = 'fast';
    case Standard = 'standard';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Fast / Low-Cost',
            self::Standard => 'Standard Engineering',
            self::Advanced => 'Advanced Reasoning',
        };
    }
}
