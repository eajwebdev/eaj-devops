<?php

namespace App\Console\Commands;

use App\Runner\RunnerGateway;
use Illuminate\Console\Command;

class RecoverRunnerCommands extends Command
{
    protected $signature = 'eaj:recover-runner-commands';

    protected $description = 'Re-offer runner commands whose lease expired, expire unclaimed ones and report disconnected runners';

    public function handle(RunnerGateway $gateway): int
    {
        $r = $gateway->recover();

        $this->components->info(sprintf(
            'Re-queued %d, failed %d, expired %d command(s); %d runner(s) disconnected.',
            $r['requeued'],
            $r['failed'],
            $r['expired'],
            $r['disconnected'],
        ));

        return self::SUCCESS;
    }
}
