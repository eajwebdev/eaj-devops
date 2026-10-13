<?php

use App\AI\Orchestrator;
use App\AI\PlanningWorkflowListener;
use App\AI\Steps\FinishPlanningStep;
use App\AI\Steps\RunSpecialistStep;
use App\Delivery\DeliveryListener;
use App\Delivery\DeliveryWorkflow;
use App\Delivery\Steps\AfterChecksStep;
use App\Delivery\Steps\ImplementStep;
use App\Delivery\Steps\ReviewDecisionStep;
use App\Delivery\Steps\ReviewStep;
use App\Delivery\Steps\TaskTransitionStep;
use App\Tools\QualityChecks;
use App\Tools\QualityChecksListener;
use App\Tools\Steps\CheckpointWorkspaceStep;
use App\Tools\Steps\QualityReportStep;
use App\Tools\Steps\RunToolStep;

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Mode (Plan §58)
    |--------------------------------------------------------------------------
    |
    | When enabled, the UI shows a permanent DEMO pill and simulated data may be
    | rendered from the demo organization. Production mode never fakes events.
    |
    */

    'demo_mode' => (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Workspaces (Plan §13)
    |--------------------------------------------------------------------------
    |
    | Agents only ever write inside per-task worktrees under this path. The
    | original project folder is read-only to agents (guarantee G1).
    |
    */

    'workspaces_path' => env('EAJ_WORKSPACES_PATH', 'C:\\EAJ\\workspaces'),

    /*
    |--------------------------------------------------------------------------
    | Backups (Plan §48.12)
    |--------------------------------------------------------------------------
    */

    'mysqldump_path' => env('EAJ_MYSQLDUMP_PATH', 'mysqldump'),

    /*
    |--------------------------------------------------------------------------
    | Hard Limits (Plan §48.1)
    |--------------------------------------------------------------------------
    |
    | Enforced outside the AI. Hitting any limit moves the workflow to BLOCKED
    | with reason LIMIT_REACHED; it never silently continues (guarantee G5).
    |
    */

    'limits' => [
        'max_attempts' => 3,
        'max_fix_cycles' => 3,
        'max_review_cycles' => 2,
        'max_handoffs' => 12,
        'max_step_seconds' => 45 * 60,
        'max_workflow_seconds' => 4 * 60 * 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Back-off (Plan §48.2)
    |--------------------------------------------------------------------------
    */

    'backoff_seconds' => [5, 30, 120],

    /*
    |--------------------------------------------------------------------------
    | Heartbeats & Stall Detection (Plan §48.5)
    |--------------------------------------------------------------------------
    */

    'heartbeat' => [
        'interval_seconds' => 15,
        'stall_seconds' => (int) env('EAJ_HEARTBEAT_STALL_SECONDS', 90),
        'fail_seconds' => (int) env('EAJ_HEARTBEAT_FAIL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Step Handlers
    |--------------------------------------------------------------------------
    |
    | Map of step name => class implementing App\Workflow\Contracts\StepHandler.
    | Later phases (task engine, agent engine, deployments) register theirs here.
    |
    */

    'step_handlers' => [
        RunSpecialistStep::NAME => RunSpecialistStep::class,
        FinishPlanningStep::NAME => FinishPlanningStep::class,
        RunToolStep::NAME => RunToolStep::class,
        CheckpointWorkspaceStep::NAME => CheckpointWorkspaceStep::class,
        QualityReportStep::NAME => QualityReportStep::class,
        TaskTransitionStep::NAME => TaskTransitionStep::class,
        ImplementStep::NAME => ImplementStep::class,
        AfterChecksStep::NAME => AfterChecksStep::class,
        ReviewStep::NAME => ReviewStep::class,
        ReviewDecisionStep::NAME => ReviewDecisionStep::class,
    ],

    // Workflow type => App\Workflow\Contracts\WorkflowListener keeping its subject truthful (G6).
    'workflow_listeners' => [
        Orchestrator::WORKFLOW => PlanningWorkflowListener::class,
        QualityChecks::WORKFLOW => QualityChecksListener::class,
        DeliveryWorkflow::WORKFLOW => DeliveryListener::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review Gates (Plan §36, §98, §99)
    |--------------------------------------------------------------------------
    |
    | A review passes only with at least this score AND no blocking finding
    | (Blocker/Major; Critical/High for security). The reviewer's own opinion
    | of whether it passed is not used.
    |
    */

    'review' => [
        'minimum_score' => [
            'qa' => (int) env('EAJ_REVIEW_MIN_QA', 80),
            'senior' => (int) env('EAJ_REVIEW_MIN_SENIOR', 90),
            'security' => (int) env('EAJ_REVIEW_MIN_SECURITY', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Long-running work
    |--------------------------------------------------------------------------
    |
    | Workflow steps (AI calls, runner commands) can run for max_step_seconds,
    | far beyond a normal job's retry_after, so they use their own connection
    | whose retry_after is longer than any step. Otherwise the queue would
    | redeliver a step that is still running. Local: database-agents; production: redis-agents.
    |
    */

    'agent_queue_connection' => env('EAJ_AGENT_QUEUE_CONNECTION', 'database-agents'),

];
