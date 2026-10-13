<?php

namespace Tests\Unit;

use App\Enums\TaskStatus as S;
use App\Tasks\TaskStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Plan §31 — every transition, valid and invalid (§48.11).
 */
class TaskStateMachineTest extends TestCase
{
    public function test_the_workflow_path_from_ready_to_done_exists()
    {
        $path = [S::Ready, S::Planning, S::InProgress, S::Testing, S::Review, S::SecurityReview, S::ReleaseReady, S::Done];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(TaskStateMachine::exists($path[$i], $path[$i + 1]), "{$path[$i]->value} → {$path[$i + 1]->value}");
        }
    }

    public function test_fix_and_review_loops_exist()
    {
        $this->assertTrue(TaskStateMachine::exists(S::Testing, S::InProgress));
        $this->assertTrue(TaskStateMachine::exists(S::Review, S::ChangesRequested));
        $this->assertTrue(TaskStateMachine::exists(S::ChangesRequested, S::InProgress));
    }

    public function test_impossible_jumps_are_rejected()
    {
        foreach ([[S::Backlog, S::Done], [S::Ready, S::Testing], [S::InProgress, S::ReleaseReady], [S::Done, S::Backlog], [S::Planning, S::Review]] as [$from, $to]) {
            $this->assertFalse(TaskStateMachine::exists($from, $to), "{$from->value} → {$to->value}");
        }
    }

    public function test_done_is_terminal_and_cancelled_can_only_reopen_to_backlog()
    {
        $this->assertSame([], TaskStateMachine::next(S::Done));
        $this->assertSame([S::Backlog], TaskStateMachine::next(S::Cancelled));
    }

    public function test_people_cannot_set_states_that_claim_ai_work_is_happening()
    {
        foreach ([S::Planning, S::InProgress, S::Testing, S::Review, S::SecurityReview, S::ReleaseReady] as $to) {
            foreach (S::cases() as $from) {
                $this->assertFalse(TaskStateMachine::userMay($from, $to, null), "user {$from->value} → {$to->value}");
            }
        }
    }

    public function test_people_can_triage_hold_stop_reopen_request_changes_and_approve()
    {
        $this->assertTrue(TaskStateMachine::userMay(S::Backlog, S::Ready, null));
        $this->assertTrue(TaskStateMachine::userMay(S::Ready, S::Backlog, null));
        $this->assertTrue(TaskStateMachine::userMay(S::InProgress, S::Blocked, null));
        $this->assertTrue(TaskStateMachine::userMay(S::Testing, S::Cancelled, null));
        $this->assertTrue(TaskStateMachine::userMay(S::Cancelled, S::Backlog, null));
        $this->assertTrue(TaskStateMachine::userMay(S::Review, S::ChangesRequested, null));
        $this->assertTrue(TaskStateMachine::userMay(S::ReleaseReady, S::Done, null));
    }

    public function test_unblocking_returns_to_the_previous_state()
    {
        $this->assertTrue(TaskStateMachine::userMay(S::Blocked, S::Backlog, S::Backlog));
        $this->assertFalse(TaskStateMachine::userMay(S::Blocked, S::Ready, S::Backlog));
        $this->assertFalse(TaskStateMachine::userMay(S::Blocked, S::Ready, null));
    }

    public function test_a_task_blocked_out_of_agent_work_is_released_to_ready_not_back_into_agent_work()
    {
        // Nothing would be running, so "Testing" by hand would claim work that isn't happening (G6).
        $this->assertFalse(TaskStateMachine::userMay(S::Blocked, S::Testing, S::Testing));
        $this->assertTrue(TaskStateMachine::userMay(S::Blocked, S::Ready, S::Testing));
        $this->assertTrue(TaskStateMachine::userMay(S::Blocked, S::Ready, S::Planning));
    }

    public function test_only_the_system_returns_a_planned_task_to_ready()
    {
        $this->assertTrue(TaskStateMachine::exists(S::Planning, S::Ready));
        $this->assertFalse(TaskStateMachine::userMay(S::Planning, S::Ready, null));
    }
}
