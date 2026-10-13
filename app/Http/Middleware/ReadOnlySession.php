<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * For read-only polling endpoints (the realtime catch-up stream). The session
 * still authenticates the request, but is never written back: the database
 * session driver has no locking, so a poll that started before a form post
 * would otherwise save its stale copy over the post's flash message.
 */
class ReadOnlySession
{
    private const ORIGINAL = 'eaj.session.handler';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession()) {
            $request->attributes->set(self::ORIGINAL, $request->session()->getHandler());
            $request->session()->setHandler(new NullSessionHandler);
        }

        return $response;
    }

    /** Runs after StartSession has "saved" into the null handler; the store may be reused (tests, Octane). */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->hasSession() && ($original = $request->attributes->get(self::ORIGINAL))) {
            $request->session()->setHandler($original);
        }
    }
}
