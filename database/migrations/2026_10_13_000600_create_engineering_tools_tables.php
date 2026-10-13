<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The project's approved command set (Plan §84): what the runner detected, and whether the owner allows it.
        Schema::create('project_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('kind', 20);
            $table->string('ecosystem', 16);
            $table->string('label', 120);
            $table->string('command', 500);
            $table->string('parser', 32);
            $table->boolean('supports_fix')->default(false);
            $table->boolean('scoped')->default(false);
            $table->boolean('filterable')->default(false);
            $table->string('blocked', 500)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('available')->default(true);
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'key']);
        });

        // One execution of a tool in a task workspace, with its parsed evidence (Plan §48.8).
        Schema::create('tool_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('repository_workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('runner_command_id')->nullable()->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->string('tool_key', 64);
            $table->string('kind', 20);
            $table->string('label', 120);
            $table->string('command', 500)->nullable();
            $table->string('mode', 8)->default('check');
            $table->string('scope', 8)->default('changed');
            $table->string('status', 20);
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Gate results are only valid for the commit they ran on (Plan §48.9).
            $table->char('head_sha', 40)->nullable();
            $table->json('tests')->nullable();
            $table->json('failures')->nullable();
            $table->json('issues')->nullable();
            $table->json('files_changed')->nullable();
            $table->json('flaky')->nullable();
            $table->json('database')->nullable();
            $table->json('notes')->nullable();
            $table->text('output_tail')->nullable();
            $table->string('progress', 500)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'id']);
            $table->index(['workflow_id', 'kind']);
        });

        Schema::table('runner_commands', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('runner_commands', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
        Schema::dropIfExists('tool_runs');
        Schema::dropIfExists('project_tools');
    }
};
