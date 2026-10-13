<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catch-up API (Plan §48.6). Clients track the last cursor they saw and
 * replay from here on reconnect or on a detected gap, so the UI can always
 * be rebuilt from persisted events.
 */
class EventController extends Controller
{
    private const MAX_LIMIT = 200;

    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * Organization-wide feed. Cursor: event id (monotonic, not gapless).
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::ActivityView), 403);

        ['after' => $after, 'limit' => $limit] = $this->cursor($request);

        $events = OutboxEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('id', '>', $after)
            ->with(['actor:id,name', 'project:id,key,name,slug'])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        return $this->respond($events, $limit, fn (OutboxEvent $e) => $e->id, $after);
    }

    /**
     * One project's stream. Cursor: sequence (gapless, so clients can detect missed events).
     */
    public function project(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        ['after' => $after, 'limit' => $limit] = $this->cursor($request);

        $events = OutboxEvent::query()
            ->where('stream', $project->eventStream())
            ->where('sequence', '>', $after)
            ->with('actor:id,name')
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();

        return $this->respond($events, $limit, fn (OutboxEvent $e) => $e->sequence, $after);
    }

    /**
     * @return array{after: int, limit: int}
     */
    private function cursor(Request $request): array
    {
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        return ['after' => (int) ($validated['after'] ?? 0), 'limit' => (int) ($validated['limit'] ?? 100)];
    }

    /**
     * @param  Collection<int, OutboxEvent>  $events
     * @param  Closure(OutboxEvent): int  $cursorOf
     */
    private function respond(Collection $events, int $limit, Closure $cursorOf, int $after): JsonResponse
    {
        $hasMore = $events->count() > $limit;
        $page = $events->take($limit);

        return response()->json([
            'data' => $page->map->toClientPayload()->values(),
            'cursor' => $page->isEmpty() ? $after : $cursorOf($page->last()),
            'has_more' => $hasMore,
        ]);
    }
}
