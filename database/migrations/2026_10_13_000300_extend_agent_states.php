<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full agent state machine (Plan §32) and pause bookkeeping for runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // WAITING_FOR_AGENT is 17 characters.
            $table->string('status', 24)->default('IDLE')->change();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            // Who paused a run: "owner" or "emergency_stop" — resuming operations only resumes the latter.
            $table->string('pause_reason', 24)->nullable()->after('status');
            $table->string('activity', 500)->nullable()->after('pause_reason');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', fn (Blueprint $table) => $table->dropColumn(['pause_reason', 'activity']));
        Schema::table('agents', fn (Blueprint $table) => $table->string('status', 16)->default('IDLE')->change());
    }
};
