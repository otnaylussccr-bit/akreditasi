<?php

namespace App\Http\Middleware;

use App\Services\OperationalModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalModuleAccess
{
    public function __construct(private readonly OperationalModuleAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $this->access->allows($user, (string) $request->route()?->getName()), 403);

        return $next($request);
    }
}
