<?php

namespace App\Http\Controllers;

use App\Enums\WorkflowStatus;
use App\Models\Project;
use App\Models\Task;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Tools\QualityChecks;
use App\Workflow\Exceptions\OperationsHaltedException;
use App\Workflow\WorkflowEngine;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Runs the deterministic quality gates on a task workspace (Phase 7, §33). */
class TaskQualityController extends Controller
{
    public function store(Request $request, Project $project, Task $task, QualityChecks $checks): RedirectResponse
    {
        $this->authorize('manage', $task);

        try {
            $workflow = $checks->start($task->setRelation('project', $project), $request->user());
        } catch (DomainException|RunnerUnavailableException|OperationsHaltedException $e) {
            return back()->withErrors(['quality' => $e->getMessage()]);
        }

        $tools = $workflow->steps()->where('name', 'tool.run')->count();

        return back()->with('status', "Quality checks started: {$tools} ".str('tool')->plural($tools).' queued on the runner.');
    }

    public function cancel(Request $request, Project $project, Task $task, QualityChecks $checks, WorkflowEngine $engine): RedirectResponse
    {
        $this->authorize('manage', $task);

        $workflow = $checks->latest($task);

        if (! $workflow || $workflow->status->isTerminal()) {
            return back()->with('status', 'No quality check is running.');
        }

        $engine->cancel($workflow, "Cancelled by {$request->user()->name}");

        return back()->with('status', $workflow->status === WorkflowStatus::Cancelled ? 'Quality checks cancelled; the running tool is being stopped on the PC.' : 'Quality checks cancelled.');
    }
}
