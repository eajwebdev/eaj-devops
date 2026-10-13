<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('version', 32);
            $table->text('system_prompt');
            $table->json('output_schema');
            $table->boolean('active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key', 'version']);
            $table->index(['organization_id', 'key', 'active']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('prompt_version_id')->nullable()->after('ai_model_id')->constrained()->nullOnDelete();
        });

        Schema::create('ai_provider_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained()->restrictOnDelete();
            $table->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64);
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempt');
            $table->boolean('is_fallback')->default(false);
            $table->boolean('is_repair')->default(false);
            $table->string('idempotency_key', 191)->unique();
            $table->string('request_id')->nullable();
            $table->json('response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
            $table->index(['provider', 'status', 'created_at']);
            $table->index(['agent_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_calls');
        Schema::table('agent_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('prompt_version_id'));
        Schema::dropIfExists('prompt_versions');
    }
};
