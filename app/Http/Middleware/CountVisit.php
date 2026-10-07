<?php

namespace App\Http\Middleware;

use App\Support\Visits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts the visit for the admin page's daily totals (see App\Support\Visits). Counting must never
 * break a page, so any failure is only reported.
 */
class CountVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful() && $request->hasSession()) {
            try {
                Visits::record($request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
