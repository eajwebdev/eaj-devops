<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task Engine (Plan Phase 3, §30–31, §54, §66–67, §95, §97).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Per-project task counter: BOOK-1, BOOK-2, ... (allocated under a row lock).
            $table->unsignedInteger('task_sequence')->default(0)->after('index_build_id');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->string('type', 20);
            $table->string('priority', 10);
            $table->string('status', 24);
            // Where a BLOCKED task returns to when unblocked.
            $table->string('blocked_from', 24)->nullable();
            $table->string('blocked_reason', 500)->nullable();
            // Kanban order within a column.
            $table->double('position')->default(0);
            $table->json('impact')->nullable();
            $table->timestamp('impact_analyzed_at')->nullable();
            $table->unsignedInteger('token_budget')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status', 'position']);
            $table->index(['organization_id', 'status']);
        });

        // §54: a DAG of tasks within one project.
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        // §95 "Send new instruction" / "Request changes": the human conversation on a task.
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('comment'); // comment | instruction | changes_requested
            $table->text('body');
            $table->timestamps();

            $table->index(['task_id', 'id']);
        });

        // §31 "Store meaningful transitions."
        Schema::create('task_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor', 10); // user | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'id']);
        });

        Schema::table('repository_workspaces', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repository_workspaces', fn (Blueprint $table) => $table->dropConstrainedForeignId('task_id'));
        Schema::dropIfExists('task_transitions');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('task_sequence'));
    }
};
