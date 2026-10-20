<?php

namespace App\Http\Middleware;

use App\Support\LocaleResolver;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolve(
            $request->user(),
            $request->hasSession() ? $request->session() : null,
        );

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
