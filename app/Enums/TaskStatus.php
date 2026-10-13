<?php

namespace App\Enums;

/**
 * Task state machine (Plan §31). Allowed moves live in App\Tasks\TaskStateMachine.
 */
enum TaskStatus: string
{
    case Backlog = 'BACKLOG';
    case Ready = 'READY';
    case Planning = 'PLANNING';
    case InProgress = 'IN_PROGRESS';
    case Testing = 'TESTING';
    case Review = 'REVIEW';
    case SecurityReview = 'SECURITY_REVIEW';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Blocked = 'BLOCKED';
    case ReleaseReady = 'RELEASE_READY';
    case Done = 'DONE';
    case Cancelled = 'CANCELLED';

    /** Kanban column titles (Plan §30). */
    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Ready => 'Ready',
            self::Planning => 'Planning',
            self::InProgress => 'In Development',
            self::Testing => 'Testing',
            self::Review => 'Senior Review',
            self::SecurityReview => 'Security Review',
            self::ChangesRequested => 'Changes Requested',
            self::Blocked => 'Blocked',
            self::ReleaseReady => 'Release Ready',
            self::Done => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /** States that only the agent workflow may enter: they claim work is happening (G6). */
    public function isAgentWork(): bool
    {
        return in_array($this, [self::Planning, self::InProgress, self::Testing, self::Review, self::SecurityReview, self::ChangesRequested], true);
    }

    /**
     * @return list<self> board order
     */
    public static function board(): array
    {
        return [
            self::Backlog, self::Ready, self::Planning, self::InProgress, self::Testing, self::Review,
            self::SecurityReview, self::ChangesRequested, self::Blocked, self::ReleaseReady, self::Done,
        ];
    }
}
