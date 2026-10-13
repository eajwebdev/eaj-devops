<?php

namespace App\Console\Commands;

use App\Workflow\WorkflowEngine;
use Illuminate\Console\Command;

class RecoverWorkflows extends Command
{
    protected $signature = 'eaj:recover-workflows';

    protected $description = 'Recover stalled workflow steps, enforce wall-time limits and re-deliver lost step messages';

    public function handle(WorkflowEngine $engine): int
    {
        $result = $engine->recover();

        $this->components->info(sprintf(
            'Recovered %d stalled step(s), blocked %d timed-out workflow(s), re-delivered %d step(s).',
            $result['recovered'],
            $result['timed_out'],
            $result['redelivered'],
        ));

        return self::SUCCESS;
    }
}
