<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEvents;
use Illuminate\Console\Command;

class PublishOutbox extends Command
{
    protected $signature = 'eaj:publish-outbox';

    protected $description = 'Broadcast any outbox events that have not been published yet';

    public function handle(): int
    {
        PublishOutboxEvents::dispatchSync();

        return self::SUCCESS;
    }
}
