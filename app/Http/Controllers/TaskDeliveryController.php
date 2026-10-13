<?php

namespace App\Http\Controllers;

use App\Delivery\DeliveryWorkflow;
use App\Enums\WorkflowStatus;
use App\Models\Project;
use App\Models\Task;
use App\Reliability\AuditLogger;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Tasks\TaskException;
use App\Workflow\Exceptions\OperationsHaltedException;
use App\Workflow\WorkflowEngine;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI delivery of a task (§33, §56 "start AI work" is the owner's approval):
 * start, stop, and the owner's decision to continue after a block (§2.6).
 */
class TaskDeliveryController extends Controller
{
    public function store(Request $request, Project $project, Task $task, DeliveryWorkflow $delivery): RedirectResponse
    {
        $this->authorize('manage', $task);

        try {
            $delivery->start($task->setRelation('project', $project), $request->user());
        } catch (DomainException|TaskException|RunnerUnavailableException|OperationsHaltedException $e) {
            return back()->withErrors(['delivery' => $e->getMessage()]);
        }

        return back()->with('status', "AI delivery of {$task->key()} started.");
    }

    public function cancel(Request $request, Project $project, Task $task, DeliveryWorkflow $delivery, WorkflowEngine $engine): RedirectResponse
    {
        $this->authorize('manage', $task);

        $workflow = $delivery->latest($task);
        if (! $workflow || $workflow->status->isTerminal()) {
            return back()->with('status', 'No AI delivery is running.');
        }

        $engine->cancel($workflow, "Cancelled by {$request->user()->name}");

        return back()->with('status', 'AI delivery cancelled. The workspace keeps every change made so far.');
    }

    public function resume(Request $request, Project $project, Task $task, DeliveryWorkflow $delivery, WorkflowEngine $engine, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage', $task);

        $workflow = $delivery->latest($task);
        if (! $workflow || ! in_array($workflow->status, [WorkflowStatus::Blocked, WorkflowStatus::Paused], true)) {
            return back()->withErrors(['delivery' => 'There is no stopped AI delivery to resume.']);
        }

        try {
            $engine->resume($workflow, "Resumed by {$request->user()->name}");
        } catch (DomainException|TaskException $e) {
            return back()->withErrors(['delivery' => $e->getMessage()]);
        }

        $audit->log('delivery.resumed', $request->user(), $project->organization, $project, $task, ['workflow_id' => $workflow->id]);

        return back()->with('status', 'AI delivery resumed.');
    }
}
