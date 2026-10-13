<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('github_user_id');
            $table->string('login');
            $table->string('avatar_url', 1024)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('github_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->string('owner');
            $table->string('name');
            $table->string('full_name');
            $table->string('default_branch');
            $table->string('clone_url', 1024);
            $table->string('html_url', 1024);
            $table->boolean('private')->default(false);
            $table->boolean('archived')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'github_id']);
            $table->unique(['organization_id', 'full_name']);
        });

        Schema::create('github_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_repository_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('head_sha', 40);
            $table->boolean('protected')->default(false);
            $table->timestamp('synced_at')->useCurrent();
            $table->unique(['github_repository_id', 'name']);
        });

        Schema::create('github_pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('github_repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('github_number');
            $table->string('node_id')->nullable();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('state', 16);
            $table->boolean('draft')->default(false);
            $table->string('head_branch');
            $table->string('base_branch');
            $table->char('head_sha', 40);
            $table->char('base_sha', 40)->nullable();
            $table->char('merge_commit_sha', 40)->nullable();
            $table->string('mergeable_state', 32)->nullable();
            $table->string('html_url', 1024);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();

            $table->unique(['github_repository_id', 'github_number']);
            $table->index(['task_id', 'id']);
        });

        Schema::create('github_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_pull_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->string('reviewer_login');
            $table->string('state', 32);
            $table->text('body')->nullable();
            $table->char('commit_id', 40)->nullable();
            $table->string('html_url', 1024)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['github_pull_request_id', 'github_id']);
        });

        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('event', 64);
            $table->string('action', 64)->nullable();
            $table->string('repository_full_name')->nullable();
            $table->unsignedInteger('pull_request_number')->nullable();
            $table->timestamp('processed_at')->useCurrent();
        });

        Schema::table('repository_workspaces', function (Blueprint $table) {
            $table->char('published_sha', 40)->nullable()->after('last_checkpoint_sha');
            $table->timestamp('published_at')->nullable()->after('published_sha');
        });
    }

    public function down(): void
    {
        Schema::table('repository_workspaces', function (Blueprint $table) {
            $table->dropColumn(['published_sha', 'published_at']);
        });
        Schema::dropIfExists('github_webhook_deliveries');
        Schema::dropIfExists('github_reviews');
        Schema::dropIfExists('github_pull_requests');
        Schema::dropIfExists('github_branches');
        Schema::dropIfExists('github_repositories');
        Schema::dropIfExists('github_connections');
    }
};
