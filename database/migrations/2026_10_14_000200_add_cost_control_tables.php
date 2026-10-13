<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control (Plan Phase 9, Sections 47, 49 and 50).
 *
 * Model prices are per one million tokens. Each usage row snapshots the price
 * and calculated cost so editing a model never rewrites historical spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_budgets', function (Blueprint $table) {
            $table->decimal('max_cost', 14, 6)->nullable()->after('max_tokens');
            $table->boolean('hard_stop')->default(true)->after('max_cost');
        });

        Schema::table('token_usage', function (Blueprint $table) {
            $table->decimal('input_cost', 14, 6)->nullable()->after('total_tokens');
            $table->decimal('cached_input_cost', 14, 6)->nullable()->after('input_cost');
            $table->decimal('output_cost', 14, 6)->nullable()->after('cached_input_cost');
            $table->decimal('total_cost', 14, 6)->nullable()->after('output_cost');
            $table->json('pricing_snapshot')->nullable()->after('total_cost');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->decimal('total_cost', 14, 6)->default(0)->after('total_tokens');
        });

        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('token_budget_id')->nullable()->constrained('token_budgets')->cascadeOnDelete();
            $table->string('budget_key', 100);
            $table->string('scope', 80);
            $table->string('metric', 16); // tokens | cost
            $table->unsignedTinyInteger('threshold'); // 70 | 90 | 100
            $table->string('period_key', 32);
            $table->decimal('used', 20, 6);
            $table->decimal('limit_value', 20, 6);
            $table->boolean('hard_stop');
            $table->timestamp('emitted_at')->useCurrent();

            $table->unique(['organization_id', 'budget_key', 'metric', 'threshold', 'period_key'], 'budget_alerts_once');
            $table->index(['organization_id', 'emitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('total_cost');
        });

        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropColumn(['input_cost', 'cached_input_cost', 'output_cost', 'total_cost', 'pricing_snapshot']);
        });

        Schema::table('token_budgets', function (Blueprint $table) {
            $table->dropColumn(['max_cost', 'hard_stop']);
        });
    }
};
