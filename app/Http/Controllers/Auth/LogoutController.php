<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->session()->get(config('localization.session_key'));
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (is_string($locale) && array_key_exists($locale, config('localization.supported'))) {
            $request->session()->put(config('localization.session_key'), $locale);
        }

        return redirect()->route('login');
    }
}
