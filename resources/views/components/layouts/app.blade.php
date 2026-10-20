<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @php
        $pageTitleKey = match (true) {
            request()->routeIs('documents.preview') => 'documents.meta.preview_title',
            request()->routeIs('documents.upload') => 'documents.meta.upload_title',
            request()->routeIs('documents.index') => 'documents.meta.index_title',
            request()->routeIs('verifications.show') => 'verification.meta.show',
            request()->routeIs('verifications.index') => 'verification.meta.index',
            request()->routeIs('repository.index') => 'repository.meta_title',
            request()->routeIs('follow-up-led.index') => 'follow_up_led.meta_title',
            request()->routeIs('team-activity.index') => 'team_activity.meta_title',
            request()->routeIs('led-assessment.index') => 'led_assessment.meta.index',
            request()->routeIs('led-assessment.dashboard') => 'led_assessment.meta.dashboard',
            request()->routeIs('administration.led-setup.index') => 'led_setup.meta_title',
            request()->routeIs('administration.users.index') => 'administration.users.meta_title',
            request()->routeIs('administration.roles.index') => 'administration.roles.meta_title',
            request()->routeIs('profile.password') => 'profile.meta_title',
            default => 'dashboard.meta_title',
        };
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title data-locale-en="{{ __($pageTitleKey, [], 'en') }}" data-locale-id="{{ __($pageTitleKey, [], 'id') }}">{{ $title ?? __($pageTitleKey) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="app-body" style="--apu-campus-background: url('{{ asset('images/apu/background-campus.jpeg') }}')">
    @php
        $previewFromRepository = request()->routeIs('documents.preview') && request()->query('asal') === 'repository';
        $user = auth()->user();
        $viewerOnly = $user->hasExactRoles('Viewer');
        $projectContext = app(\App\Services\AccreditationProjectContextService::class);
        $authorization = app(\App\Services\AccreditationAuthorizationService::class);
        $currentProject = $projectContext->resolve($authorization->accessibleProjects($user)->get());
        $projectRouteParams = $currentProject ? ['proyek' => $currentProject->id] : [];
        $activeLedAssignment = $user->ledAssessorAssignments()
            ->whereNull('revoked_at')
            ->with('cycle:id,project_id')
            ->orderByDesc('assigned_at')
            ->first();
        $ledAssessmentRouteParams = $activeLedAssignment
            ? ['proyek' => $activeLedAssignment->cycle->project_id, 'siklus' => $activeLedAssignment->cycle_id]
            : $projectRouteParams;
        $verificationRouteParams = ! $viewerOnly
            && $currentProject
            && $authorization->reviewableProjects($user)->whereKey($currentProject->id)->exists()
                ? $projectRouteParams
                : [];
        $topbarSectionKey = match (true) {
            request()->routeIs('administration.users.*') => 'navigation.sections.users',
            request()->routeIs('administration.roles.*') => 'navigation.sections.roles',
            request()->routeIs('profile.*') => 'navigation.sections.profile',
            request()->routeIs('verifications.*') => 'navigation.verification',
            request()->routeIs('repository.*') || $previewFromRepository => 'navigation.repository',
            request()->routeIs('documents.*') => 'navigation.documents',
            request()->routeIs('follow-up-led.*') => 'navigation.follow_up_led',
            request()->routeIs('team-activity.*') => 'navigation.team_activity',
            request()->routeIs('led-assessment.index') => 'navigation.led_assessment',
            request()->routeIs('led-assessment.dashboard') => 'navigation.assessment_dashboard',
            request()->routeIs('administration.led-setup.*') => 'navigation.sections.led_setup',
            default => 'navigation.dashboard',
        };
        $roleLabels = auth()->user()->getRoleNames()->map(fn (string $role) => \App\Support\LocalizedLabel::role($role));
        $roleLabelsEn = auth()->user()->getRoleNames()->map(fn (string $role) => \App\Support\LocalizedLabel::role($role, 'en'));
        $roleLabelsId = auth()->user()->getRoleNames()->map(fn (string $role) => \App\Support\LocalizedLabel::role($role, 'id'));
    @endphp
    <input class="nav-toggle" id="nav-toggle" type="checkbox" aria-hidden="true">
    <aside class="sidebar">
        <div class="brand">
            <img
                class="sidebar-official-logo"
                src="{{ asset('images/apu/Logo Univ White.png') }}"
                alt="{{ __('common.brand.logo_alt') }}"
                data-locale-attribute="alt"
                data-locale-en="{{ __('common.brand.logo_alt', [], 'en') }}"
                data-locale-id="{{ __('common.brand.logo_alt', [], 'id') }}"
            >
        </div>

        <nav class="main-nav" aria-label="{{ __('navigation.main_label') }}" data-locale-attribute="aria-label" data-locale-en="{{ __('navigation.main_label', [], 'en') }}" data-locale-id="{{ __('navigation.main_label', [], 'id') }}">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard', $projectRouteParams) }}" wire:navigate>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>
                <x-localized-text key="navigation.dashboard" />
            </a>
            @can('dashboard.view')
                <a class="nav-link {{ request()->routeIs('led-assessment.dashboard') ? 'active' : '' }}" href="{{ route('led-assessment.dashboard', $projectRouteParams) }}" wire:navigate>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16v2H4v-2Zm1-2V9h3v8H5Zm5 0V3h4v14h-4Zm6 0v-6h3v6h-3Z"/></svg>
                    <x-localized-text key="navigation.assessment_dashboard" />
                </a>
            @endcan
            @if ($viewerOnly)
                @if ($activeLedAssignment)
                    <a class="nav-link {{ request()->routeIs('led-assessment.index') ? 'active' : '' }}" href="{{ route('led-assessment.index', $ledAssessmentRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2Zm3 4v2h8V7H8Zm0 4v2h5v-2H8Zm0 4v2h3v-2H8Zm8-4 1.5 1.5L20 10l1.4 1.4-3.9 3.9-2.9-2.9L16 11Z"/></svg>
                         <x-localized-text key="navigation.led_assessment" />
                    </a>
                @endif
                <x-viewer-disabled-nav-item label-key="navigation.documents">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg>
                </x-viewer-disabled-nav-item>
                <x-viewer-disabled-nav-item label-key="navigation.repository">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v11H3V6Zm2 2v9h14V8H5Zm3 2h8v2H8v-2Zm0 4h6v2H8v-2Z"/></svg>
                </x-viewer-disabled-nav-item>
                <x-viewer-disabled-nav-item label-key="navigation.verification">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-4 7 1.4 1.4L15 8.8l1.4 1.4-5 5L8 11.8 9.4 10.4l.6.6Z"/></svg>
                </x-viewer-disabled-nav-item>
                <x-viewer-disabled-nav-item label-key="navigation.follow_up_led">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2Zm2 4v2h10V7H7Zm0 4v2h10v-2H7Zm0 4v2h6v-2H7Z"/></svg>
                </x-viewer-disabled-nav-item>
                <x-viewer-disabled-nav-item label-key="navigation.team_activity">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3a3 3 0 1 0 0 6ZM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5a3 3 0 1 0 0 6Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z"/></svg>
                </x-viewer-disabled-nav-item>
                <x-viewer-disabled-nav-item label-key="navigation.settings">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.6 7.6 0 0 0 .05-1 7.6 7.6 0 0 0-.05-1l2.1-1.64-2-3.46-2.48 1a7.5 7.5 0 0 0-1.72-1L15 3.25h-4L10.7 5.9a7.5 7.5 0 0 0-1.72 1l-2.48-1-2 3.46L6.6 11a7.6 7.6 0 0 0-.05 1c0 .34.02.67.05 1l-2.1 1.64 2 3.46 2.48-1a7.5 7.5 0 0 0 1.72 1l.3 2.65h4l.3-2.65a7.5 7.5 0 0 0 1.72-1l2.48 1 2-3.46L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/></svg>
                </x-viewer-disabled-nav-item>
            @else
                @can('project.view')
                    <a class="nav-link {{ request()->routeIs('documents.*') && ! $previewFromRepository ? 'active' : '' }}" href="{{ route('documents.index', $projectRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg>
                         <x-localized-text key="navigation.documents" />
                    </a>
                    @can('document.view')
                        <a class="nav-link {{ request()->routeIs('repository.*') || $previewFromRepository ? 'active' : '' }}" href="{{ route('repository.index', $projectRouteParams) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v11H3V6Zm2 2v9h14V8H5Zm3 2h8v2H8v-2Zm0 4h6v2H8v-2Z"/></svg>
                             <x-localized-text key="navigation.repository" />
                        </a>
                    @endcan
                    @if (($user->hasRole('Reviewer') && $user->can('document.review')) || ($user->hasRole('Super Admin') && $user->can('document.view')))
                        <a class="nav-link {{ request()->routeIs('verifications.*') ? 'active' : '' }}" href="{{ route('verifications.index', $verificationRouteParams) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-4 7 1.4 1.4L15 8.8l1.4 1.4-5 5L8 11.8 9.4 10.4l.6.6Z"/></svg>
                             <x-localized-text key="navigation.verification" />
                        </a>
                    @endif
                    <a class="nav-link {{ request()->routeIs('follow-up-led.*') ? 'active' : '' }}" href="{{ route('follow-up-led.index', $projectRouteParams) }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2Zm2 4v2h10V7H7Zm0 4v2h10v-2H7Zm0 4v2h6v-2H7Z"/></svg>
                         <x-localized-text key="navigation.follow_up_led" />
                    </a>
                    @can('project.view')
                        <a class="nav-link {{ request()->routeIs('led-assessment.index') ? 'active' : '' }}" href="{{ route('led-assessment.index', $projectRouteParams) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2Zm3 4v2h8V7H8Zm0 4v2h5v-2H8Zm0 4v2h3v-2H8Zm8-4 1.5 1.5L20 10l1.4 1.4-3.9 3.9-2.9-2.9L16 11Z"/></svg>
                             <x-localized-text key="navigation.led_assessment" />
                        </a>
                    @endcan
                    @can('project.manage')
                        <a class="nav-link {{ request()->routeIs('administration.led-setup.*') ? 'active' : '' }}" href="{{ route('administration.led-setup.index', $projectRouteParams) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h10v2H4V4Zm0 7h7v2H4v-2Zm0 7h10v2H4v-2Zm14.5-8a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7Zm0 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM17.5 3h2v4h-2V3Zm0 14h2v4h-2v-4Zm-5-4.5h4v2h-4v-2Zm8 0h3.5v2h-3.5v-2Z"/></svg>
                             <x-localized-text key="navigation.led_configuration" />
                        </a>
                    @endcan
                    @if (app(\App\Services\AccreditationWorkflowActivityService::class)->canView($user))
                        <a class="nav-link {{ request()->routeIs('team-activity.*') ? 'active' : '' }}" href="{{ route('team-activity.index', $projectRouteParams) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3a3 3 0 1 0 0 6ZM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5a3 3 0 1 0 0 6Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z"/></svg>
                             <x-localized-text key="navigation.team_activity" />
                        </a>
                    @endif
                @endcan
                @can('viewAny', \App\Models\User::class)
                     <span class="nav-section" data-locale-en="{{ __('navigation.administration', [], 'en') }}" data-locale-id="{{ __('navigation.administration', [], 'id') }}">{{ __('navigation.administration') }}</span>
                    <a class="nav-link {{ request()->routeIs('administration.users.*') ? 'active' : '' }}" href="{{ route('administration.users.index') }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.6 7.6 0 0 0 .05-1 7.6 7.6 0 0 0-.05-1l2.1-1.64-2-3.46-2.48 1a7.5 7.5 0 0 0-1.72-1L15 3.25h-4L10.7 5.9a7.5 7.5 0 0 0-1.72 1l-2.48-1-2 3.46L6.6 11a7.6 7.6 0 0 0-.05 1c0 .34.02.67.05 1l-2.1 1.64 2 3.46 2.48-1a7.5 7.5 0 0 0 1.72 1l.3 2.65h4l.3-2.65a7.5 7.5 0 0 0 1.72-1l2.48 1 2-3.46L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/></svg>
                         <x-localized-text key="navigation.users" />
                    </a>
                    <a class="nav-link {{ request()->routeIs('administration.roles.*') ? 'active' : '' }}" href="{{ route('administration.roles.index') }}" wire:navigate>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5.1 3.4 9.7 8 11 4.6-1.3 8-5.9 8-11V5l-8-3Zm0 2.2L18 6.4V11c0 4-2.5 7.6-6 8.8C8.5 18.6 6 15 6 11V6.4l6-2.2Zm0 2.8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm0 8c-2.3 0-4 1.2-4 2.7v.4c1.1.8 2.5 1.5 4 1.9 1.5-.4 2.9-1.1 4-1.9v-.4c0-1.5-1.7-2.7-4-2.7Z"/></svg>
                         <x-localized-text key="navigation.roles_permissions" />
                    </a>
                @endcan
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
             <label class="menu-button" for="nav-toggle" aria-label="{{ __('navigation.open') }}" data-locale-attribute="aria-label" data-locale-en="{{ __('navigation.open', [], 'en') }}" data-locale-id="{{ __('navigation.open', [], 'id') }}">
                <span></span><span></span><span></span>
            </label>
            <div class="topbar-title">
                 <span data-locale-en="{{ __('common.brand.system_name', [], 'en') }}" data-locale-id="{{ __('common.brand.system_name', [], 'id') }}">{{ __('common.brand.system_name') }}</span>
                <strong data-locale-en="{{ __($topbarSectionKey, [], 'en') }}" data-locale-id="{{ __($topbarSectionKey, [], 'id') }}">{{ __($topbarSectionKey) }}</strong>
            </div>
             <div class="user-area">
                 <x-locale-switcher />
                  <a class="user-profile-link {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.password') }}" wire:navigate aria-label="{{ __('navigation.open_profile') }}" data-locale-attribute="aria-label" data-locale-en="{{ __('navigation.open_profile', [], 'en') }}" data-locale-id="{{ __('navigation.open_profile', [], 'id') }}">
                    <div class="user-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                    <div class="user-copy">
                        <strong>{{ auth()->user()->name }}</strong>
                          <span data-locale-en="{{ $roleLabelsEn->join(', ') ?: __('common.roles.none', [], 'en') }}" data-locale-id="{{ $roleLabelsId->join(', ') ?: __('common.roles.none', [], 'id') }}">{{ $roleLabels->join(', ') ?: __('common.roles.none') }}</span>
                    </div>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                      <button class="logout-button" type="submit" data-locale-en="{{ __('common.actions.logout', [], 'en') }}" data-locale-id="{{ __('common.actions.logout', [], 'id') }}">{{ __('common.actions.logout') }}</button>
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
