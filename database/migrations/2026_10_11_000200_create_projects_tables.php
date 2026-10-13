<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Short uppercase key used for task IDs, e.g. "EAJ" in EAJ-114.
            $table->string('key', 10);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('project_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // local | github | new | archive
            $table->string('status', 20)->default('pending_scan');
            // Local path is read-only to agents; only the Local Runner touches it (Plan §13, §89.2).
            $table->string('local_path', 1024)->nullable();
            $table->string('repository_url', 1024)->nullable();
            $table->string('default_branch')->nullable();
            $table->boolean('git_enabled')->nullable();
            $table->json('detected_stack')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_connections');
        Schema::dropIfExists('projects');
    }
};
