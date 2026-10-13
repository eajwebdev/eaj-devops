<?php

namespace App\Http\Middleware;

use App\Models\LocalRunner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-machine bearer token for the EAJ Local Runner API. Only the SHA-256 of
 * the token is stored; revoking a runner invalidates it immediately.
 */
class AuthenticateRunner
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $runner = $token && strlen($token) <= 256
            ? LocalRunner::query()->active()->where('token_hash', hash('sha256', $token))->first()
            : null;

        if (! $runner) {
            return response()->json(['message' => 'Unknown or revoked runner. Pair again with: eaj-runner pair <studio-url> <code>'], 401);
        }

        $request->attributes->set('runner', $runner);

        return $next($request);
    }
}
