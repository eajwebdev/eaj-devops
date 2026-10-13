<?php

namespace App\Enums;

enum AgentRunStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Retrying = 'RETRYING';
    case Paused = 'PAUSED';
    case Blocked = 'BLOCKED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
