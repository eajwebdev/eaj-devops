<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Github\GithubException;
use App\Github\GithubOAuth;
use App\Reliability\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GithubConnectionController extends Controller
{
    public function connect(Request $request, CurrentOrganization $current, GithubOAuth $oauth): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::SettingsManage), 403);

        try {
            return redirect()->away($oauth->authorizationUrl($request, $organization));
        } catch (GithubException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }
    }

    public function callback(Request $request, CurrentOrganization $current, GithubOAuth $oauth, AuditLogger $audit): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::SettingsManage), 403);

        try {
            $connection = $oauth->callback($request, $organization, $request->user());
            $audit->log('github.connected', $request->user(), $organization, metadata: ['login' => $connection->login]);
        } catch (GithubException $e) {
            return to_route('repositories.index')->withErrors(['github' => $e->getMessage()]);
        }

        return to_route('repositories.index')->with('status', "GitHub connected as @{$connection->login}.");
    }

    public function disconnect(Request $request, CurrentOrganization $current, AuditLogger $audit): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::SettingsManage), 403);
        $connection = $organization->githubConnection;
        if ($connection) {
            $connection->forceFill(['revoked_at' => now()])->save();
            $audit->log('github.disconnected', $request->user(), $organization, metadata: ['login' => $connection->login]);
        }

        return back()->with('status', 'GitHub disconnected. Imported projects and local files were kept.');
    }
}
