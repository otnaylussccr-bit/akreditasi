<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

final class LocaleResolver
{
    public function resolve(?User $user, ?Session $session): string
    {
        $userLocale = $user?->getAttribute('locale');

        if ($this->isSupported($userLocale)) {
            return $userLocale;
        }

        $sessionLocale = $session?->get(config('localization.session_key'));

        if ($this->isSupported($sessionLocale)) {
            return $sessionLocale;
        }

        $default = config('app.locale', 'en');

        return $this->isSupported($default) ? $default : 'en';
    }

    public function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, config('localization.supported', []));
    }
}
