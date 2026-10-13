<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Nightly database backup (Plan §48.12). Writes a gzipped mysqldump to
 * storage/app/backups and prunes old ones. Copying backups off-server is a
 * deployment concern — a backup on the same disk is not a backup.
 */
class BackupDatabase extends Command
{
    protected $signature = 'eaj:backup-database {--keep=14 : Number of days of backups to keep}';

    protected $description = 'Dump the Studio database to storage/app/backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->components->warn("Backups support MySQL/MariaDB only (current driver: {$config['driver']}).");

            return self::FAILURE;
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$config['database'].'-'.now()->format('Ymd-His').'.sql.gz';

        $process = new Process([
            config('eaj.mysqldump_path', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            $config['database'],
        ], env: ['MYSQL_PWD' => (string) $config['password']], timeout: 3600);

        $gz = gzopen($path, 'wb6');
        $process->run(function (string $type, string $buffer) use ($gz) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            }
        });
        gzclose($gz);

        if (! $process->isSuccessful()) {
            File::delete($path);
            $this->components->error('Backup failed: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->prune($directory, (int) $this->option('keep'));
        $this->components->info('Backup written to '.$path.' ('.round(filesize($path) / 1024).' KB).');

        return self::SUCCESS;
    }

    private function prune(string $directory, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        foreach (File::glob($directory.DIRECTORY_SEPARATOR.'*.sql.gz') as $file) {
            if (filemtime($file) < $cutoff) {
                File::delete($file);
            }
        }
    }
}
