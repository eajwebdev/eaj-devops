<?php

namespace App\Workflow;

final class StepResult
{
    /**
     * @param  array<string, mixed>  $output  persisted on the step before anything is applied (§48.3)
     * @param  string|null  $commitSha  checkpoint commit created by the runner in the task worktree (§48.4)
     * @param  array<string, mixed>  $state  extra state needed to resume from this checkpoint
     */
    public function __construct(
        public readonly array $output = [],
        public readonly ?string $commitSha = null,
        public readonly array $state = [],
    ) {}
}
