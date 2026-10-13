<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every set of file changes an agent made, as applied by the runner (Plan §48.8, §53, §95 "view diff").
        Schema::create('change_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 8);
            $table->string('mode', 12); // implement | fix | changes
            $table->unsignedSmallInteger('cycle')->default(0);
            $table->string('status', 12); // applied | empty
            $table->text('summary')->nullable();
            $table->string('confidence', 8)->nullable();
            $table->json('files')->nullable();
            $table->json('out_of_scope')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->longText('diff')->nullable();
            $table->char('base_head_sha', 40)->nullable();
            $table->timestamps();
            $table->index(['task_id', 'id']);
        });

        // One review gate result (Plan §36, §74.1, §98, §99), scored deterministically from the reviewer's findings.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gate', 16); // qa | senior | security
            $table->unsignedSmallInteger('cycle')->default(0);
            $table->char('head_sha', 40)->nullable();
            $table->boolean('passed');
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('minimum_score');
            $table->text('summary')->nullable();
            $table->string('confidence', 8)->nullable();
            $table->json('issues')->nullable();
            $table->json('acceptance')->nullable();
            $table->json('blocking')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('change_sets');
    }
};
