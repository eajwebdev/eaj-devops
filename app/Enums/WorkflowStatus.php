<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Paused = 'PAUSED';
    case Blocked = 'BLOCKED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Paused, self::Cancelled],
            self::Running => [self::Paused, self::Blocked, self::Completed, self::Failed, self::Cancelled],
            self::Paused => [self::Running, self::Cancelled],
            self::Blocked => [self::Running, self::Cancelled],
            self::Completed, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
