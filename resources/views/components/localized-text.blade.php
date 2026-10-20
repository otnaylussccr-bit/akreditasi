@props(['key', 'replace' => []])

<span
    data-locale-en="{{ __($key, $replace, 'en') }}"
    data-locale-id="{{ __($key, $replace, 'id') }}"
>{{ __($key, $replace) }}</span>
