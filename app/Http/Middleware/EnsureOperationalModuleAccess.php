<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->hasExactRoles('Viewer')) {
            $hasLedAssignment = $request->routeIs('led-assessment.index')
                && $user->ledAssessorAssignments()
                    ->whereNull('revoked_at')
                    ->exists();
            abort_unless($hasLedAssignment, 403);
        }

        return $next($request);
    }
}
