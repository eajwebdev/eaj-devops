<?php

namespace App\Enums;

enum ProjectConnectionType: string
{
    case Local = 'local';
    case GitHub = 'github';
    case New = 'new';
    case Archive = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local Repository',
            self::GitHub => 'GitHub Repository',
            self::New => 'New Project',
            self::Archive => 'Import Archive',
        };
    }

    /**
     * Only connection types whose engine exists can be selected.
     */
    public function isAvailable(): bool
    {
        return in_array($this, [self::Local, self::GitHub], true);
    }
}
