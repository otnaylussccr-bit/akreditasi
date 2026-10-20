<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title data-locale-en="{{ __('auth.meta_title', [], 'en') }}" data-locale-id="{{ __('auth.meta_title', [], 'id') }}">{{ $title ?? __('auth.meta_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="auth-body" style="--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')">
    <div class="auth-locale-switcher"><x-locale-switcher /></div>
    {{ $slot }}
    @livewireScripts
</body>
</html>
