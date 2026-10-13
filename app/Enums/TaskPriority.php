<?php

namespace App\Enums;

/**
 * Plan §97. Influences queue order, model routing, review depth and notifications (later phases).
 */
enum TaskPriority: string
{
    case Critical = 'CRITICAL';
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
