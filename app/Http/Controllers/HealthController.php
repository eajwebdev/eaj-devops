<?php

namespace App\Http\Controllers;

use App\Reliability\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Public health endpoint for external monitoring (Plan §90). Reports only
 * component status — no tenant data, paths or secrets.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json([
            'status' => $result['status'],
            'checks' => collect($result['checks'])->mapWithKeys(fn ($c) => [$c['key'] => $c['status']]),
            'time' => now()->toIso8601String(),
        ], $result['status'] === 'down' ? 503 : 200);
    }
}
