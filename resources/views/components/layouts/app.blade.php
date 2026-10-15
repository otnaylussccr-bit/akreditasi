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
<body class="app-body" style="--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')">
    @php
        $previewFromRepository = request()->routeIs('documents.preview') && request()->query('asal') === 'repository';
        $projectContext = app(\App\Services\AccreditationProjectContextService::class);
        $authorization = app(\App\Services\AccreditationAuthorizationService::class);
        $currentProject = $projectContext->resolve($authorization->accessibleProjects(auth()->user())->get());
        $projectRouteParams = $currentProject ? ['proyek' => $currentProject->id] : [];
        $verificationRouteParams = $currentProject
            && $authorization->reviewableProjects(auth()->user())->whereKey($currentProject->id)->exists()
                ? $projectRouteParams
                : [];
    @endphp
    <input class="nav-toggle" id="nav-toggle" type="checkbox" aria-hidden="true">
    <aside class="sidebar">
        <div class="brand">
            <img
                class="sidebar-official-logo"
                src="{{ asset('images/apu/Logo Univ White.png') }}"
                alt="Logo resmi Agung Putra University"
            >
        </div>

        <nav class="main-nav" aria-label="Navigasi utama">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard', $projectRouteParams) }}" wire:navigate>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>
                Dashboard
            </a>
            @can('project.view')
                <a class="nav-link {{ request()->routeIs('documents.*') && ! $previewFromRepository ? 'active' : '' }}" href="{{ route('documents.index', $projectRouteParams) }}" wire:navigate>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg>
                    Input Dokumen
                </a>
                @can('document.view')
                    <a class="nav-link {{ request()->routeIs('repository.*') || $previewFromRepository ? 'active' : '' }}" href="{{ route('repository.index', $projectRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v11H3V6Zm2 2v9h14V8H5Zm3 2h8v2H8v-2Zm0 4h6v2H8v-2Z"/></svg>
                        Repository Bukti
                    </a>
                @endcan
                @if ((auth()->user()->hasRole('Reviewer') && auth()->user()->can('document.review')) || (auth()->user()->hasRole('Super Admin') && auth()->user()->can('document.view')))
                    <a class="nav-link {{ request()->routeIs('verifications.*') ? 'active' : '' }}" href="{{ route('verifications.index', $verificationRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-4 7 1.4 1.4L15 8.8l1.4 1.4-5 5L8 11.8 9.4 10.4l.6.6Z"/></svg>
                        Verifikasi Dokumen
                    </a>
                @endif
                <a class="nav-link {{ request()->routeIs('follow-up-led.*') ? 'active' : '' }}" href="{{ route('follow-up-led.index', $projectRouteParams) }}" wire:navigate>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2Zm2 4v2h10V7H7Zm0 4v2h10v-2H7Zm0 4v2h6v-2H7Z"/></svg>
                    Follow Up LED
                </a>
                @if (app(\App\Services\AccreditationWorkflowActivityService::class)->canView(auth()->user()))
                    <a class="nav-link {{ request()->routeIs('team-activity.*') ? 'active' : '' }}" href="{{ route('team-activity.index', $projectRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3a3 3 0 1 0 0 6ZM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5a3 3 0 1 0 0 6Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z"/></svg>
                        Aktivitas Tim
                    </a>
                @endif
            @endcan
            @if (auth()->user()->can('user.manage') || auth()->user()->can('role.manage') || auth()->user()->can('assignment.manage'))
                <span class="nav-section">Administrasi</span>
                <span class="nav-link nav-link-muted" aria-disabled="true">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.6 7.6 0 0 0 .05-1 7.6 7.6 0 0 0-.05-1l2.1-1.64-2-3.46-2.48 1a7.5 7.5 0 0 0-1.72-1L15 3.25h-4L10.7 5.9a7.5 7.5 0 0 0-1.72 1l-2.48-1-2 3.46L6.6 11a7.6 7.6 0 0 0-.05 1c0 .34.02.67.05 1l-2.1 1.64 2 3.46 2.48-1a7.5 7.5 0 0 0 1.72 1l.3 2.65h4l.3-2.65a7.5 7.5 0 0 0 1.72-1l2.48 1 2-3.46L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/></svg>
                    Pengaturan
                </span>
            @endif
        </nav>

        <div class="sidebar-foot">
            <img
                class="sidebar-official-tagline"
                src="{{ asset('images/apu/Tagline White.png') }}"
                alt="Empowering Science Tech"
            >
        </div>
    </aside>

    <div class="app-shell">
        <header class="topbar">
            <label class="menu-button" for="nav-toggle" aria-label="Buka navigasi">
                <span></span><span></span><span></span>
            </label>
            <div class="topbar-title">
                <span>Sistem Manajemen Akreditasi</span>
                <strong>{{ request()->routeIs('verifications.*') ? 'Verifikasi Dokumen' : (request()->routeIs('repository.*') || $previewFromRepository ? 'Repository Bukti' : (request()->routeIs('documents.*') ? 'Input Dokumen' : (request()->routeIs('follow-up-led.*') ? 'Follow Up LED' : (request()->routeIs('team-activity.*') ? 'Aktivitas Tim' : 'Dashboard')))) }}</strong>
            </div>
            <div class="user-area">
                <div class="user-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                <div class="user-copy">
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>{{ auth()->user()->getRoleNames()->join(', ') ?: 'Tanpa role' }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="logout-button" type="submit">Keluar</button>
                </form>
            </div>
        </header>

        <main class="main-content">
            {{ $slot }}
        </main>
    </div>
    <label class="nav-backdrop" for="nav-toggle" aria-hidden="true"></label>
    @livewireScripts
</body>
</html>
