<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    private array $trackMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), $this->trackMethods) && $request->user()) {
            AuditLog::create([
                'user_id'         => $request->user()->id,
                'organization_id' => $request->user()->organization_id,
                'action'          => strtolower($request->method()),
                'url'             => $request->fullUrl(),
                'method'          => $request->method(),
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
                'new_values'      => $request->except(['password', 'password_confirmation', 'current_password']),
                'created_at'      => now(),
            ]);
        }

        return $response;
    }
}
