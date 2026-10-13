<?php

namespace App\Enums;

enum BlockedReason: string
{
    case LimitReached = 'LIMIT_REACHED';
    case RunnerOffline = 'RUNNER_OFFLINE';
    case NeedsDecision = 'NEEDS_DECISION';
}
