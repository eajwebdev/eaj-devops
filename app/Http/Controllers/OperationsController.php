<?php

namespace App\Http\Controllers;

use App\Actions\Operations\HaltOperations;
use App\Actions\Operations\ResumeOperations;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Workflow;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Emergency stop (Plan §57). scope=organization stops the current
 * organization; scope=global stops the platform and needs Super Admin.
 */
class OperationsController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function preview(Request $request, ResumeOperations $resume): JsonResponse
    {
        $organization = $this->scope($request, Permission::OperationsResume);

        return response()->json([
            'workflows' => $resume->preview($organization)->map(fn (Workflow $w) => [
                'id' => $w->id,
                'type' => $w->type,
                'project_id' => $w->project_id,
                'current_step' => $w->current_step,
            ]),
        ]);
    }

    public function halt(Request $request, HaltOperations $halt): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $organization = $this->scope($request, Permission::OperationsHalt);
        $paused = $halt->handle($request->user(), $organization, $validated['reason']);

        return back()->with('status', 'AI operations stopped. '.count($paused).' workflow(s) paused at their last checkpoint.');
    }

    public function resume(Request $request, ResumeOperations $resume): RedirectResponse
    {
        $organization = $this->scope($request, Permission::OperationsResume);
        $resumed = $resume->handle($request->user(), $organization);

        return back()->with('status', 'AI operations resumed. '.count($resumed).' workflow(s) continuing.');
    }

    private function scope(Request $request, Permission $permission): ?Organization
    {
        $request->validate(['scope' => ['sometimes', 'in:organization,global']]);

        if ($request->input('scope') === 'global') {
            abort_unless($request->user()->is_super_admin, 403, 'Only a Super Admin can stop or resume the whole platform.');

            return null;
        }

        $organization = $this->current->get();
        abort_unless($request->user()->hasPermissionIn($organization, $permission), 403);

        return $organization;
    }
}
