<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\RunnerPresenter;
use App\Models\LocalRunner;
use App\Reliability\AuditLogger;
use App\Runner\RunnerGateway;
use App\Runner\RunnerPairing;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Local runners: pair developer machines, see their health, revoke them.
 */
class RunnerController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function index(Request $request): Response
    {
        $organization = $this->current->get();

        $runners = LocalRunner::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name')
            ->orderByRaw('revoked_at is null desc')
            ->orderByDesc('last_heartbeat_at')
            ->get();

        return Inertia::render('settings/runners', [
            'runners' => $runners->map(fn (LocalRunner $r) => RunnerPresenter::make($r)),
            'studioUrl' => rtrim(config('app.url'), '/'),
            // Single-user mode (Plan §89.2): the runner ships in this repository's runner/ folder.
            'runnerScript' => base_path('runner'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'eaj-runner.js'),
            'can' => ['manage' => $request->user()->hasPermissionIn($organization, Permission::RunnersManage)],
            'pairing' => fn () => $request->session()->get('pairing'),
        ]);
    }

    public function store(Request $request, RunnerPairing $pairing): RedirectResponse
    {
        $organization = $this->current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::RunnersManage), 403);

        $code = $pairing->createCode($organization, $request->user());

        // Shown once on the next page view; only its hash is stored.
        return back()->with('pairing', ['code' => $code['code'], 'expires_at' => $code['expires_at']->toIso8601String()]);
    }

    public function destroy(Request $request, LocalRunner $runner, RunnerGateway $gateway, AuditLogger $audit): RedirectResponse
    {
        $organization = $this->current->get();
        abort_unless($runner->organization_id === $organization->id, 404);
        abort_unless($request->user()->hasPermissionIn($organization, Permission::RunnersManage), 403);

        if (! $runner->revoked_at) {
            $gateway->revoke($runner, $request->user());
            $audit->log('runner.revoked', $request->user(), $organization, subject: $runner);
        }

        return back()->with('status', "Runner \"{$runner->name}\" revoked. It can no longer connect.");
    }
}
