<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTool;
use App\Tools\ToolCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The project's approved command set (Plan §84): the owner decides which detected tools agents may run. */
class ProjectToolController extends Controller
{
    public function update(Request $request, Project $project, ProjectTool $tool, ToolCatalog $catalog): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        if ($data['enabled'] && $tool->blocked) {
            return back()->withErrors(['tools' => "{$tool->label} is blocked by the runner's safety rules: {$tool->blocked}"]);
        }

        $catalog->setEnabled($tool->setRelation('project', $project), (bool) $data['enabled'], $request->user());

        return back()->with('status', "{$tool->label} ".($data['enabled'] ? 'may now be run by agents.' : 'is switched off.'));
    }
}
