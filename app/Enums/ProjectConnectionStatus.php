<?php

namespace App\Enums;

enum ProjectConnectionStatus: string
{
    case PendingScan = 'pending_scan';
    case Scanning = 'scanning';
    case Indexing = 'indexing';
    case Connected = 'connected';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::PendingScan => 'Waiting for scan',
            self::Scanning => 'Scanning',
            self::Indexing => 'Building code index',
            self::Connected => 'Connected',
            self::Error => 'Connection error',
        };
    }
}
