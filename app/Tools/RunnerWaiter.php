<?php

namespace App\Tools;

use App\Models\RunnerCommand;
use App\Models\ToolRun;
use App\Workflow\StepContext;
use RuntimeException;

/**
 * Lets a workflow step wait for work the Local Runner does asynchronously.
 * While waiting it heartbeats the step (§48.5) and gives up cooperatively when
 * the workflow is paused or AI operations are stopped (§57), so the engine
 * retries the step from its checkpoint later.
 */
class RunnerWaiter
{
    protected int $pollMilliseconds = 1000;

    public function __construct(private readonly ToolRunService $tools) {}

    public function toolRun(ToolRun $run, StepContext $context, int $timeoutSeconds): ToolRun
    {
        $this->until(
            fn () => $run->refresh()->status->isTerminal(),
            $context,
            $timeoutSeconds,
            fn () => $this->tools->cancel($run, reason: 'The workflow step stopped waiting for it.'),
            "{$run->label} did not finish within ".intdiv($timeoutSeconds, 60).' minutes.',
        );

        return $run;
    }

    public function command(RunnerCommand $command, StepContext $context, int $timeoutSeconds): RunnerCommand
    {
        $this->until(
            fn () => $command->refresh()->status->isTerminal(),
            $context,
            $timeoutSeconds,
            fn () => null,
            "The runner did not finish \"{$command->type->label()}\" within ".intdiv($timeoutSeconds, 60).' minutes.',
        );

        return $command;
    }

    /**
     * @param  callable(): bool  $done
     * @param  callable(): mixed  $abandon
     */
    private function until(callable $done, StepContext $context, int $timeoutSeconds, callable $abandon, string $timeoutMessage): void
    {
        $started = microtime(true);
        $lastBeat = 0.0;

        while (! $done()) {
            if ($context->shouldStop()) {
                $abandon();

                throw new RuntimeException('The workflow was paused or AI operations were stopped; the step resumes from here.');
            }

            if (microtime(true) - $started > $timeoutSeconds) {
                $abandon();

                throw new RuntimeException($timeoutMessage);
            }

            if (microtime(true) - $lastBeat >= 10) {
                $context->heartbeat();
                $lastBeat = microtime(true);
            }

            $this->pause();
        }
    }

    protected function pause(): void
    {
        usleep($this->pollMilliseconds * 1000);
    }
}
