<?php

namespace App\Console\Commands;

use App\Agents\AgentRunService;
use Illuminate\Console\Command;

class RecoverAgentRuns extends Command
{
    protected $signature = 'eaj:recover-agent-runs';

    protected $description = 'Retry agent runs whose heartbeat has expired';

    public function handle(AgentRunService $runs): int
    {
        $count = $runs->recoverStalled();
        $this->info("Recovered {$count} stalled agent run(s).");

        return self::SUCCESS;
    }
}
