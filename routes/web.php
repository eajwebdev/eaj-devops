<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AgentTeamController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\CommandCenterController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRunnerController;
use App\Http\Controllers\TaskAiController;
use App\Http\Controllers\TaskBoardController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WarRoomController;
use App\Http\Middleware\ReadOnlySession;
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

Route::bind('agent', fn (string $id) => app(CurrentOrganization::class)->get()->agents()->whereKey($id)->firstOrFail());
Route::bind('skill', fn (string $id) => app(CurrentOrganization::class)->get()->skills()->whereKey($id)->firstOrFail());
// Model routes resolve only inside the current organization (cross-tenant ids are a 404).
Route::bind('modelRoute', fn (string $id) => app(CurrentOrganization::class)->get()->modelRoutes()->whereKey($id)->firstOrFail());

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

    // Local Project Engine (Phase 2)
    Route::put('projects/{project}/runner', [ProjectRunnerController::class, 'assignRunner'])->name('projects.runner.assign');
    Route::post('projects/{project}/scan', [ProjectRunnerController::class, 'scan'])->name('projects.scan');
    Route::post('projects/{project}/index', [ProjectRunnerController::class, 'index'])->name('projects.index.rebuild');
    Route::post('projects/{project}/git-init', [ProjectRunnerController::class, 'initializeGit'])->name('projects.git-init');

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

    // Agent Engine (Phase 4)
    Route::get('ai-team', [AgentTeamController::class, 'index'])->name('agents.index');
    Route::patch('ai-team/agents/{agent}', [AgentTeamController::class, 'updateAgent'])->name('agents.update');
    Route::patch('ai-team/skills/{skill}', [AgentTeamController::class, 'updateSkill'])->name('skills.update');
    Route::post('ai-team/models', [AgentTeamController::class, 'storeModel'])->name('models.store');
    Route::patch('ai-team/routes/{modelRoute}', [AgentTeamController::class, 'updateRoute'])->name('model-routes.update');
    Route::get('war-room', WarRoomController::class)->name('war-room');

    Route::get('activity', ActivityController::class)->name('activity');

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
