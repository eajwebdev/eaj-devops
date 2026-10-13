<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Reliability\AuditLogger;
use Illuminate\Console\Command;

/**
 * Super Admin is granted only from the server console — never through the
 * web UI or registration — so it cannot be obtained remotely.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'eaj:super-admin {email} {--revoke : Remove super admin instead of granting it}';

    protected $description = 'Grant (or revoke) platform Super Admin for an existing user';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error('No user with that email address.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();

        $audit->log($grant ? 'user.super_admin.granted' : 'user.super_admin.revoked', subject: $user, metadata: ['via' => 'console']);

        $this->components->info(($grant ? 'Granted' : 'Revoked')." Super Admin for {$user->email}.");

        return self::SUCCESS;
    }
}
