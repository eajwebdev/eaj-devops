<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\OutboxEvent;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::ActivityView), 403);

        $events = OutboxEvent::query()
            ->where('organization_id', $organization->getKey())
            ->whereNotIn('type', OutboxEvent::QUIET_TYPES)
            ->with(['actor:id,name', 'project:id,key,name,slug'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('activity', [
            'events' => $events->reverse()->values()->map->toClientPayload(),
        ]);
    }
}
