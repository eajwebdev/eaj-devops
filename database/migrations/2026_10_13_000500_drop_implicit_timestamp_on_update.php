<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MariaDB/MySQL with explicit_defaults_for_timestamp=OFF silently give the first NOT NULL
 * TIMESTAMP column of a table "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP".
 * That rewrote outbox_events.occurred_at on every publish (and would have extended a
 * pairing code's expires_at on every update). An explicit DEFAULT turns the behaviour off.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'outbox_events' => 'occurred_at',
        'agent_events' => 'occurred_at',
        'token_usage' => 'recorded_at',
        'runner_pairing_codes' => 'expires_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // Restoring the implicit ON UPDATE clause would reintroduce the bug.
    }
};
