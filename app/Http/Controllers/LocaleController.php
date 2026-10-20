<?php

namespace App\Http\Controllers;

use App\Support\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request, LocaleResolver $resolver): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('localization.supported', [])))],
            'redirect' => ['nullable', 'string', 'max:2048'],
        ]);

        $locale = $validated['locale'];
        abort_unless($resolver->isSupported($locale), 422);

        $request->session()->put(config('localization.session_key'), $locale);
        App::setLocale($locale);

        if ($request->expectsJson()) {
            return response()->json(['locale' => $locale]);
        }

        return redirect()->to($this->safeRedirect($validated['redirect'] ?? null));
    }

    private function safeRedirect(?string $redirect): string
    {
        if (! is_string($redirect)
            || ! str_starts_with($redirect, '/')
            || str_starts_with($redirect, '//')
            || str_contains($redirect, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $redirect)) {
            return '/';
        }

        return $redirect;
    }
}
