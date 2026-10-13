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
     * Only connection types whose engine exists can be selected. GitHub arrives
     * in Phase 10; archive import and new-project scaffolding come later.
     */
    public function isAvailable(): bool
    {
        return $this === self::Local;
    }
}
