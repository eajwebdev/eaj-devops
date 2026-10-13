<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reliability foundation (Plan §2.6, §48). Built before any feature so every
 * later phase inherits durable workflows, checkpoints, the transactional
 * outbox, idempotency, the emergency-stop flag and an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §48.2 — the database is the source of truth; queues only deliver "run step N".
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->morphs('subject'); // task, deployment, ...
            $table->string('type', 64);
            $table->string('status', 20);
            $table->string('blocked_reason', 64)->nullable();
            $table->unsignedInteger('current_step')->default(0);
            $table->json('limits');
            $table->unsignedInteger('handoffs')->default(0);
            $table->unsignedInteger('fix_cycles')->default(0);
            $table->unsignedInteger('review_cycles')->default(0);
            // Optimistic concurrency, checked together with SELECT ... FOR UPDATE.
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 64);
            $table->string('status', 20);
            $table->unsignedInteger('attempt')->default(0);
            $table->json('input')->nullable();
            // §48.3 — results are stored before they are applied, so a retry re-applies instead of re-paying.
            $table->json('output')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('resume_checkpoint_id')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['workflow_id', 'position']);
            $table->index(['status', 'heartbeat_at']);
        });

        // §48.4 — a checkpoint after every completed step; recovery resumes from the latest.
        Schema::create('workflow_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 64)->nullable();
            $table->json('state')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workflow_id', 'id']);
        });

        // §48.3 — every job, AI call and tool command carries an idempotency key.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('status', 20); // running | completed | failed
            $table->json('result')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        // §48.6 — gapless, per-stream sequence numbers for the catch-up API.
        Schema::create('event_streams', function (Blueprint $table) {
            $table->string('stream', 64)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });

        // §48.6 — written in the same DB transaction as the state change.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('stream', 64);
            $table->unsignedBigInteger('sequence');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('payload');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->text('last_publish_error')->nullable();

            $table->unique(['stream', 'sequence']);
            $table->index(['published_at', 'id']);
            $table->index(['organization_id', 'id']);
        });

        // §57 — the emergency stop flag lives in the database (and cache) so every worker sees it.
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // §59 — every important action is traceable.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('event_streams');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('workflow_checkpoints');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
