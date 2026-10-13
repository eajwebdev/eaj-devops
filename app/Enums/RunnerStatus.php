<?php

namespace App\Enums;

/**
 * Derived from heartbeats (Plan §48.5): 15s interval, "stale" after 90s,
 * "offline" after 5 minutes.
 */
enum RunnerStatus: string
{
    case Online = 'online';
    case Stale = 'stale';
    case Offline = 'offline';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Stale => 'Not responding',
            self::Offline => 'Offline',
            self::Revoked => 'Revoked',
        };
    }
}
