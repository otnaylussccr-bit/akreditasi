<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'APU Accreditation' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="auth-body" style="--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')">
    {{ $slot }}
    @livewireScripts
</body>
</html>
