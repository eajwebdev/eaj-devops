<?php

namespace App\Enums;

/**
 * Agent state machine (Plan §32). The War Room motion system (§7.6) animates
 * an agent only while it is in an active state, and only because a persisted
 * event put it there.
 */
enum AgentStatus: string
{
    case Idle = 'IDLE';
    case Queued = 'QUEUED';
    case Initializing = 'INITIALIZING';
    case Planning = 'PLANNING';
    case Reading = 'READING';
    case Working = 'WORKING';
    case Testing = 'TESTING';
    case WaitingForTool = 'WAITING_FOR_TOOL';
    case WaitingForAgent = 'WAITING_FOR_AGENT';
    case Reviewing = 'REVIEWING';
    case Fixing = 'FIXING';
    case Blocked = 'BLOCKED';
    case Paused = 'PAUSED';
    case Failed = 'FAILED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::WaitingForTool => 'Waiting for tool',
            self::WaitingForAgent => 'Waiting for agent',
            default => ucfirst(strtolower($this->value)),
        };
    }

    /** States that animate (§7.6): the agent is doing real work right now. */
    public function isActive(): bool
    {
        return in_array($this, [self::Initializing, self::Planning, self::Reading, self::Working, self::Testing, self::Reviewing, self::Fixing], true);
    }

    /**
     * What an executor may report during a run. Lifecycle states (queued,
     * blocked, paused, failed, completed, cancelled) are set by AgentRunService only.
     *
     * @return list<self>
     */
    public static function activities(): array
    {
        return [self::Planning, self::Reading, self::Working, self::Testing, self::WaitingForTool, self::WaitingForAgent, self::Reviewing, self::Fixing];
    }
}
