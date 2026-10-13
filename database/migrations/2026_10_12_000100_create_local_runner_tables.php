<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local Project Engine (Plan Phase 2, §11–18, §41, §89.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_runners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('hostname', 255)->nullable();
            $table->string('os', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('version', 20)->nullable();
            // Only a SHA-256 of the bearer token is stored; the token itself is shown once to the runner.
            $table->char('token_hash', 64)->unique();
            // Encrypted at rest: used to sign every command the Studio sends.
            $table->text('signing_secret');
            $table->json('allowed_roots')->nullable();
            $table->string('workspaces_root', 1024)->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
        });

        Schema::create('runner_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('local_runner_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('runner_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('local_runner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('payload');
            $table->string('status', 20);
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('progress', 500)->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['local_runner_id', 'status', 'created_at']);
            $table->index(['project_id', 'type', 'created_at']);
        });

        Schema::table('project_connections', function (Blueprint $table) {
            $table->foreignId('local_runner_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->json('git_state')->nullable()->after('detected_stack');
            $table->json('warnings')->nullable()->after('git_state');
            $table->timestamp('indexed_at')->nullable()->after('last_scanned_at');
            $table->json('index_stats')->nullable()->after('indexed_at');
            $table->string('last_error', 1000)->nullable()->after('index_stats');
        });

        // Scan history: one row per successful scan (Plan §12 "Create Project Profile").
        Schema::create('project_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('local_runner_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('runner_command_id')->nullable();
            $table->json('stack');
            $table->json('git');
            $table->json('warnings')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'id']);
        });

        // Code index (Plan §41). Rows carry the build that produced them, so a
        // rebuild swaps atomically and a failed build never leaves half an index.
        Schema::create('project_index_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('build_id');
            $table->string('path', 1024);
            $table->char('path_hash', 40);
            $table->string('language', 32);
            $table->unsignedInteger('size');
            $table->char('sha1', 40)->nullable();
            $table->unsignedInteger('symbol_count')->default(0);

            $table->unique(['project_id', 'build_id', 'path_hash']);
            $table->index(['project_id', 'build_id', 'language']);
        });

        Schema::create('project_index_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('project_index_files')->cascadeOnDelete();
            $table->ulid('build_id');
            $table->string('kind', 24);
            $table->string('name', 255);
            $table->unsignedInteger('line');
            $table->string('detail', 500)->nullable();

            $table->index(['project_id', 'build_id', 'kind']);
            $table->index(['project_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->ulid('index_build_id')->nullable()->after('status');
        });

        // Task workspaces (Plan §13): one Git worktree per task.
        Schema::create('repository_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('local_runner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_key', 32);
            $table->string('branch', 255);
            $table->string('path', 1024)->nullable();
            $table->string('base_branch', 255)->nullable();
            $table->string('base_sha', 64)->nullable();
            $table->string('safety_stash', 64)->nullable();
            $table->string('last_checkpoint_sha', 64)->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'task_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_workspaces');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('index_build_id'));
        Schema::dropIfExists('project_index_symbols');
        Schema::dropIfExists('project_index_files');
        Schema::dropIfExists('project_snapshots');
        Schema::table('project_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('local_runner_id');
            $table->dropColumn(['git_state', 'warnings', 'indexed_at', 'index_stats', 'last_error']);
        });
        Schema::dropIfExists('runner_commands');
        Schema::dropIfExists('runner_pairing_codes');
        Schema::dropIfExists('local_runners');
    }
};
