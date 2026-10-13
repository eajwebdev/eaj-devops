<?php

namespace App\Enums;

enum RunnerCommandStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Expired, self::Cancelled], true);
    }
}
