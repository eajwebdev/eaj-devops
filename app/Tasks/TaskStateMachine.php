<?php

namespace App\Tasks;

use App\Enums\TaskStatus as S;
use App\Models\Task;

/**
 * Task state machine (Plan §31): which moves exist, and which of them a person
 * may make. Moves into agent-work states (Planning … Changes Requested) are
 * system-only: they claim work is happening, so only the workflow that does
 * the work may enter them (guarantee G6).
 */
final class TaskStateMachine
{
    /**
     * @return list<S>
     */
    public static function next(S $from): array
    {
        return match ($from) {
            S::Backlog => [S::Ready, S::Blocked, S::Cancelled],
            S::Ready => [S::Backlog, S::Planning, S::Blocked, S::Cancelled],
            // Planning → Ready: the plan is finished and the task waits for implementation (system only).
            S::Planning => [S::Ready, S::InProgress, S::Blocked, S::Cancelled],
            S::InProgress => [S::Testing, S::Blocked, S::Cancelled],
            S::Testing => [S::Review, S::InProgress, S::Blocked, S::Cancelled],
            S::Review => [S::SecurityReview, S::ReleaseReady, S::ChangesRequested, S::Blocked, S::Cancelled],
            S::SecurityReview => [S::ReleaseReady, S::ChangesRequested, S::Blocked, S::Cancelled],
            S::ChangesRequested => [S::InProgress, S::Blocked, S::Cancelled],
            S::Blocked => [S::Backlog, S::Ready, S::Planning, S::InProgress, S::Testing, S::Review, S::SecurityReview, S::ChangesRequested, S::Cancelled],
            S::ReleaseReady => [S::Done, S::ChangesRequested, S::Cancelled],
            S::Done => [],
            S::Cancelled => [S::Backlog],
        };
    }

    public static function exists(S $from, S $to): bool
    {
        return in_array($to, self::next($from), true);
    }

    /**
     * Moves a person may make from the board or the task page (Plan §95).
     */
    public static function userMay(S $from, S $to, ?S $blockedFrom): bool
    {
        if (! self::exists($from, $to)) {
            return false;
        }

        return match (true) {
            // Triage.
            $from === S::Backlog && $to === S::Ready, $from === S::Ready && $to === S::Backlog => true,
            // Stop.
            $to === S::Cancelled => true,
            // Hold with a reason, and release back to where it was — except into agent work:
            // nothing would be running, so a task blocked out of agent work goes back to Ready.
            $to === S::Blocked => true,
            $from === S::Blocked => $blockedFrom?->isAgentWork() ? $to === S::Ready : $to === $blockedFrom,
            // Request changes after a review, or reject a release candidate.
            $to === S::ChangesRequested => in_array($from, [S::Review, S::SecurityReview, S::ReleaseReady], true),
            // Approve (checked against tasks.approve by the service).
            $from === S::ReleaseReady && $to === S::Done => true,
            // Reopen.
            $from === S::Cancelled && $to === S::Backlog => true,
            default => false,
        };
    }

    /**
     * @return list<S>
     */
    public static function userTargets(Task $task): array
    {
        return array_values(array_filter(self::next($task->status), fn (S $to) => self::userMay($task->status, $to, $task->blocked_from)));
    }
}
