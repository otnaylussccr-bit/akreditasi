<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            $locale = $request->session()->get(config('localization.session_key'));
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if (is_string($locale) && array_key_exists($locale, config('localization.supported'))) {
                $request->session()->put(config('localization.session_key'), $locale);
            }

            return redirect()->route('login')->with(
                'status',
                __('auth.inactive'),
            );
        }

        return $next($request);
    }
}
