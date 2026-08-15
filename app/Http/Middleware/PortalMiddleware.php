<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalMiddleware
{
    /**
     * Restrict access based on user's portal type.
     * Usage in routes: middleware('portal:college')
     * (portal enum is college|student only — role-based access, e.g.
     * super_admin, is a separate check via Spatie roles/permissions.)
     */
    public function handle(Request $request, Closure $next, string ...$portals): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!in_array($user->portal, $portals)) {
            return response()->json([
                'message' => "Access denied. This endpoint requires [{$this->formatPortals($portals)}] portal access.",
            ], 403);
        }

        return $next($request);
    }

    private function formatPortals(array $portals): string
    {
        return implode(' | ', $portals);
    }
}
