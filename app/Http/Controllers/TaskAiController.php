<?php

namespace App\Http\Controllers;

use App\AI\Orchestrator;
use App\Models\Project;
use App\Models\Task;
use App\Tasks\TaskException;
use App\Workflow\Exceptions\OperationsHaltedException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Hands a Ready task to the AI team (Phase 6 Orchestrator, §2.3). */
class TaskAiController extends Controller
{
    public function store(Request $request, Project $project, Task $task, Orchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('manage', $task);

        try {
            $workflow = $orchestrator->plan($task->setRelation('project', $project), $request->user());
        } catch (DomainException|TaskException|OperationsHaltedException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        $count = $workflow->steps()->count() - 1;

        return back()->with('status', "AI planning started with {$count} ".str('specialist')->plural($count).'.');
    }
}
