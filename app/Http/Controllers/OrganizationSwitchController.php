<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        // 404 rather than 403 so organization IDs cannot be probed.
        abort_unless($request->user()->belongsToOrganization($organization), 404);

        $request->user()->switchOrganization($organization);

        return to_route('dashboard');
    }
}
