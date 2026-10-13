<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Engine (Plan Phase 4, Sections 10, 27, 37-48 and 59).
 *
 * Provider calls deliberately do not live here. This phase persists the
 * provider-neutral definitions, routes, limits and append-only run evidence
 * that the Phase 6 provider adapters will execute against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 8)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('responsibilities');
            $table->boolean('may_implement')->default(true);
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_role_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('status', 16)->default('IDLE');
            $table->unsignedInteger('token_budget');
            $table->unsignedTinyInteger('retry_limit')->default(3);
            $table->unsignedInteger('max_wall_seconds')->default(2700);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->json('supported_frameworks')->nullable();
            $table->json('supported_versions')->nullable();
            $table->json('responsibilities');
            $table->json('rules');
            $table->json('forbidden_actions');
            $table->json('tools');
            $table->json('validation_expectations');
            $table->json('references')->nullable();
            $table->json('output_schema')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['skill_id', 'version']);
            $table->index(['skill_id', 'active']);
        });

        Schema::create('agent_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_version_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['agent_id', 'skill_id']);
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('model_key', 120);
            $table->string('display_name', 120);
            $table->string('tier', 24);
            $table->unsignedInteger('max_context')->nullable();
            // Pricing is configuration only in Phase 4; aggregation arrives in Phase 9.
            $table->decimal('input_cost', 12, 6)->nullable();
            $table->decimal('cached_input_cost', 12, 6)->nullable();
            $table->decimal('output_cost', 12, 6)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'provider', 'model_key']);
            $table->index(['organization_id', 'tier', 'enabled']);
        });

        Schema::create('model_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('route_key', 120);
            $table->string('purpose', 64)->default('default');
            $table->string('tier', 24);
            $table->foreignId('primary_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('fallback_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'route_key']);
            $table->index(['organization_id', 'purpose', 'enabled']);
        });

        Schema::create('token_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 24); // organization | project | task | agent
            $table->unsignedBigInteger('scope_id');
            $table->string('period', 16)->default('all_time'); // run | day | month | all_time
            $table->unsignedBigInteger('max_tokens');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'scope_type', 'scope_id', 'period'], 'token_budgets_scope_unique');
            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_role_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 64);
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedTinyInteger('max_attempts');
            $table->unsignedBigInteger('token_budget');
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->unsignedInteger('event_sequence')->default(0);
            $table->string('idempotency_key', 191);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'status']);
            $table->index(['task_id', 'id']);
        });

        Schema::create('agent_run_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_version_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_run_id', 'skill_version_id']);
        });

        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 64);
            $table->string('message', 500);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['agent_run_id', 'sequence']);
            $table->index(['organization_id', 'id']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('token_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('input_tokens');
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens');
            $table->unsignedBigInteger('total_tokens');
            $table->string('idempotency_key', 191);
            $table->timestamp('recorded_at');

            $table->unique(['agent_run_id', 'idempotency_key']);
            $table->index(['organization_id', 'recorded_at']);
            $table->index(['project_id', 'recorded_at']);
            $table->index(['task_id', 'recorded_at']);
            $table->index(['agent_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_usage');
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('agent_run_skills');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('token_budgets');
        Schema::dropIfExists('model_routes');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('agent_skills');
        Schema::dropIfExists('skill_versions');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_roles');
    }
};
