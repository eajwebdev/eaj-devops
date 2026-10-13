<?php

namespace App\Providers;

use App\AI\Contracts\AgentRunnerInterface;
use App\AI\Providers\FakeAIProvider;
use App\AI\SpecialistRunner;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\ChangeSet;
use App\Models\LocalRunner;
use App\Models\ModelRoute;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectTool;
use App\Models\Review;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TokenBudget;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\SystemHalt;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->singleton(SystemHalt::class);
        $this->app->bind(AgentRunnerInterface::class, SpecialistRunner::class);
        // Scripted responses must survive container resolution within a test.
        $this->app->singleton(FakeAIProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stable names in polymorphic columns instead of PHP class names.
        Relation::enforceMorphMap([
            'local_runner' => LocalRunner::class,
            'organization' => Organization::class,
            'project' => Project::class,
            'task' => Task::class,
            'user' => User::class,
            'workflow' => Workflow::class,
            'agent' => Agent::class,
            'agent_run' => AgentRun::class,
            'ai_model' => AiModel::class,
            'model_route' => ModelRoute::class,
            'skill' => Skill::class,
            'project_tool' => ProjectTool::class,
            'tool_run' => ToolRun::class,
            'change_set' => ChangeSet::class,
            'review' => Review::class,
            'token_budget' => TokenBudget::class,
        ]);

        // Fail loudly in development when a mass-assigned attribute would be silently dropped.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
