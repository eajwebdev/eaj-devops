<?php

namespace Tests\Support;

use App\Models\WorkflowStep;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use RuntimeException;

/**
 * Test double for workflow steps: counts executions and fails a scripted
 * number of times before succeeding (Plan §48.11 — no paid calls in tests).
 */
class ScriptedStepHandler implements StepHandler
{
    /** @var array<string, int> executions per step name */
    public static array $runs = [];

    /** @var array<string, int> remaining failures per step name */
    public static array $failures = [];

    public static function reset(): void
    {
        static::$runs = [];
        static::$failures = [];
    }

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        static::$runs[$step->name] = (static::$runs[$step->name] ?? 0) + 1;
        $context->heartbeat();

        if ((static::$failures[$step->name] ?? 0) > 0) {
            static::$failures[$step->name]--;

            throw new RuntimeException("Scripted failure in {$step->name}");
        }

        return new StepResult(
            output: ['step' => $step->name, 'run' => static::$runs[$step->name]],
            commitSha: sha1($step->name.$step->attempt),
        );
    }
}
