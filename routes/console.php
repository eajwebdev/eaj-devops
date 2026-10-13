<?php

use Illuminate\Support\Facades\Schedule;

// Reliability foundation (Plan §48). Run the scheduler with `php artisan schedule:work`
// locally, or a once-a-minute cron calling `php artisan schedule:run` on the server.

Schedule::command('eaj:recover-workflows')->everyMinute()->withoutOverlapping();
Schedule::command('eaj:recover-runner-commands')->everyMinute()->withoutOverlapping();
Schedule::command('eaj:recover-agent-runs')->everyMinute()->withoutOverlapping();

// The outbox is also published right after each commit; this sweep catches anything missed.
Schedule::command('eaj:publish-outbox')->everyTenSeconds()->withoutOverlapping();

Schedule::command('eaj:backup-database')->dailyAt('02:30')->withoutOverlapping();
