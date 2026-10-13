<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AgentTeamController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\CommandCenterController;
use App\Http\Controllers\CostDashboardController;
use App\Http\Controllers\GithubConnectionController;
use App\Http\Controllers\GithubRepositoryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRunnerController;
use App\Http\Controllers\ProjectToolController;
use App\Http\Controllers\TaskAiController;
use App\Http\Controllers\TaskBoardController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskDeliveryController;
use App\Http\Controllers\TaskGithubController;
use App\Http\Controllers\TaskQualityController;
use App\Http\Controllers\WarRoomController;
use App\Http\Middleware\ReadOnlySession;
use App\Models\GithubRepository;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Route;

// Tenant scoping (Plan §75): a project slug only resolves inside the current organization.
Route::bind('project', fn (string $slug) => app(CurrentOrganization::class)->get()
    ->projects()
    ->where('slug', $slug)
    ->firstOrFail());

// Tasks are addressed by their number inside the (already org-scoped) project: /projects/booking/tasks/12.
Route::bind('task', fn (string $number, Illuminate\Routing\Route $route) => $route->parameter('project')
    ->tasks()
    ->where('number', (int) $number)
    ->firstOrFail());

// Project tools resolve only inside their (already org-scoped) project.
Route::bind('tool', fn (string $id, Illuminate\Routing\Route $route) => $route->parameter('project')
    ->tools()
    ->whereKey((int) $id)
    ->firstOrFail());

Route::bind('agent', fn (string $id) => app(CurrentOrganization::class)->get()->agents()->whereKey($id)->firstOrFail());
Route::bind('skill', fn (string $id) => app(CurrentOrganization::class)->get()->skills()->whereKey($id)->firstOrFail());
// Model routes resolve only inside the current organization (cross-tenant ids are a 404).
Route::bind('modelRoute', fn (string $id) => app(CurrentOrganization::class)->get()->modelRoutes()->whereKey($id)->firstOrFail());
Route::bind('aiModel', fn (string $id) => app(CurrentOrganization::class)->get()->aiModels()->whereKey($id)->firstOrFail());
Route::bind('budget', fn (string $id) => app(CurrentOrganization::class)->get()->tokenBudgets()->whereKey($id)->firstOrFail());
Route::bind('githubRepository', fn (string $id) => GithubRepository::where('organization_id', app(CurrentOrganization::class)->get()->id)->findOrFail($id));
Route::bind('githubPullRequest', fn (string $id, Illuminate\Routing\Route $route) => $route->parameter('task')->pullRequests()->whereKey($id)->firstOrFail());

Route::get('health', HealthController::class)->name('health');

Route::redirect('/', '/command-center')->name('home');

Route::middleware(['auth'])->group(function () {
    // Kept as "dashboard" because the auth controllers redirect to it.
    Route::get('command-center', CommandCenterController::class)->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // GitHub provider (Phase 10)
    Route::get('repositories', [GithubRepositoryController::class, 'index'])->name('repositories.index');
    Route::get('github/connect', [GithubConnectionController::class, 'connect'])->name('github.connect');
    Route::get('github/callback', [GithubConnectionController::class, 'callback'])->name('github.callback');
    Route::delete('github/connection', [GithubConnectionController::class, 'disconnect'])->name('github.disconnect');
    Route::post('projects/{project}/github', [GithubRepositoryController::class, 'attach'])->name('projects.github.attach');
    Route::post('repositories/{githubRepository}/sync', [GithubRepositoryController::class, 'sync'])->name('repositories.sync');

    // Local Project Engine (Phase 2)
    Route::put('projects/{project}/runner', [ProjectRunnerController::class, 'assignRunner'])->name('projects.runner.assign');
    Route::post('projects/{project}/scan', [ProjectRunnerController::class, 'scan'])->name('projects.scan');
    Route::post('projects/{project}/index', [ProjectRunnerController::class, 'index'])->name('projects.index.rebuild');
    Route::post('projects/{project}/git-init', [ProjectRunnerController::class, 'initializeGit'])->name('projects.git-init');

    // Automated Engineering Tools (Phase 7)
    Route::patch('projects/{project}/tools/{tool}', [ProjectToolController::class, 'update'])->whereNumber('tool')->name('projects.tools.update');

    // Task Engine (Phase 3)
    Route::get('tasks', TaskBoardController::class)->name('tasks.board');
    Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('projects/{project}/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::post('projects/{project}/tasks/{task}/transition', [TaskController::class, 'transition'])->name('tasks.transition');
    Route::post('projects/{project}/tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
    Route::post('projects/{project}/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
    Route::post('projects/{project}/tasks/{task}/dependencies', [TaskController::class, 'addDependency'])->name('tasks.dependencies.store');
    Route::delete('projects/{project}/tasks/{task}/dependencies/{dependency}', [TaskController::class, 'removeDependency'])->whereNumber('dependency')->name('tasks.dependencies.destroy');
    Route::post('projects/{project}/tasks/{task}/impact', [TaskController::class, 'analyze'])->name('tasks.impact');
    Route::post('projects/{project}/tasks/{task}/workspace', [TaskController::class, 'prepareWorkspace'])->name('tasks.workspace');
    Route::post('projects/{project}/tasks/{task}/ai', [TaskAiController::class, 'store'])->name('tasks.ai');
    Route::post('projects/{project}/tasks/{task}/quality', [TaskQualityController::class, 'store'])->name('tasks.quality');
    Route::post('projects/{project}/tasks/{task}/quality/cancel', [TaskQualityController::class, 'cancel'])->name('tasks.quality.cancel');
    Route::post('projects/{project}/tasks/{task}/delivery', [TaskDeliveryController::class, 'store'])->name('tasks.delivery');
    Route::post('projects/{project}/tasks/{task}/delivery/cancel', [TaskDeliveryController::class, 'cancel'])->name('tasks.delivery.cancel');
    Route::post('projects/{project}/tasks/{task}/delivery/resume', [TaskDeliveryController::class, 'resume'])->name('tasks.delivery.resume');
    Route::post('projects/{project}/tasks/{task}/github', [TaskGithubController::class, 'store'])->name('tasks.github.store');
    Route::post('projects/{project}/tasks/{task}/github/{githubPullRequest}/sync', [TaskGithubController::class, 'sync'])->name('tasks.github.sync');
    Route::post('projects/{project}/tasks/{task}/github/{githubPullRequest}/merge', [TaskGithubController::class, 'merge'])->name('tasks.github.merge');

    // Agent Engine (Phase 4)
    Route::get('ai-team', [AgentTeamController::class, 'index'])->name('agents.index');
    Route::patch('ai-team/agents/{agent}', [AgentTeamController::class, 'updateAgent'])->name('agents.update');
    Route::patch('ai-team/skills/{skill}', [AgentTeamController::class, 'updateSkill'])->name('skills.update');
    Route::post('ai-team/models', [AgentTeamController::class, 'storeModel'])->name('models.store');
    Route::patch('ai-team/models/{aiModel}', [AgentTeamController::class, 'updateModel'])->name('models.update');
    Route::patch('ai-team/routes/{modelRoute}', [AgentTeamController::class, 'updateRoute'])->name('model-routes.update');
    Route::get('war-room', WarRoomController::class)->name('war-room');

    Route::get('activity', ActivityController::class)->name('activity');
    Route::get('costs', [CostDashboardController::class, 'index'])->name('costs.index');
    Route::post('costs/budgets', [CostDashboardController::class, 'storeBudget'])->name('costs.budgets.store');
    Route::delete('costs/budgets/{budget}', [CostDashboardController::class, 'destroyBudget'])->name('costs.budgets.destroy');

    Route::post('organizations/{organization}/switch', OrganizationSwitchController::class)->name('organizations.switch');

    Route::get('operations/resume-preview', [OperationsController::class, 'preview'])->name('operations.preview');
    Route::post('operations/halt', [OperationsController::class, 'halt'])->name('operations.halt');
    Route::post('operations/resume', [OperationsController::class, 'resume'])->name('operations.resume');

    // Catch-up API (Plan §48.6) — session-authenticated JSON for the Studio UI.
    // Read-only session: these are polled constantly and must never overwrite a concurrent form post's flash.
    Route::prefix('api')->name('api.')->middleware(['throttle:120,1', ReadOnlySession::class])->group(function () {
        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('projects/{project}/events', [EventController::class, 'project'])->name('projects.events');
        Route::get('projects/{project}/index/search', [ProjectRunnerController::class, 'search'])->name('projects.index.search');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
