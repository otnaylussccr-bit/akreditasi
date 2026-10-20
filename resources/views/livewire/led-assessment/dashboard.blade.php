<div class="led-page led-dashboard">
    <header class="led-page-head">
        <div>
            <span class="eyebrow dark">{{ __('led_assessment.dashboard.eyebrow') }}</span>
            <h1>{{ __('led_assessment.dashboard.title') }}</h1>
            <p>{{ __('led_assessment.dashboard.intro') }}</p>
        </div>
        <div class="led-head-actions">
            <select wire:change="selectProject($event.target.value)" aria-label="{{ __('led_assessment.dashboard.select_project') }}">
                @foreach ($projects as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $project->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            @if ($canOpenAssessment)
                <a href="{{ route('led-assessment.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_assessment.dashboard.open_assessment') }}</a>
            @endif
        </div>
    </header>

    @if (! $cycle || ! $summary)
        <section class="empty-state led-empty">
            <strong>{{ $hasAnyCycle ? __('led_assessment.dashboard.draft_empty_title') : __('led_assessment.dashboard.no_cycle_title') }}</strong><p>{{ $hasAnyCycle ? __('led_assessment.dashboard.draft_empty_body') : __('led_assessment.dashboard.no_cycle_body') }}</p>
            @can('project.manage')<a href="{{ route('administration.led-setup.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('led_assessment.dashboard.open_setup') }}</a>@endcan
        </section>
    @else
        <section class="led-dashboard-banner">
            <div><span>{{ __('led_assessment.dashboard.project') }}</span><strong>{{ $project->name }}</strong></div>
            <div><span>{{ __('led_assessment.dashboard.cycle') }}</span><select wire:change="selectCycle($event.target.value)" aria-label="{{ __('led_assessment.dashboard.select_cycle') }}">@foreach ($cycles as $option)<option value="{{ $option->id }}" @selected($option->id === $cycle->id)>#{{ $option->cycle_number }} {{ $option->cycle_name }}</option>@endforeach</select></div>
            <div><span>{{ __('led_assessment.dashboard.instrument_version') }}</span><strong>{{ $cycle->instrumentVersion->version_code }}</strong></div>
            <div><span>{{ __('led_assessment.dashboard.aggregation_principle') }}</span><strong>{{ __('led_assessment.dashboard.approved_pointer_only') }}</strong></div>
        </section>

        @if ($summary['approved'] === 0)
            <div class="setup-readiness-warning led-dashboard-empty-note" role="status"><strong>{{ __('led_assessment.dashboard.no_approved_revision') }}</strong><p>{{ __('led_assessment.dashboard.no_approved_help') }}</p></div>
        @endif

        <section class="led-kpi-grid">
            <article><span>{{ __('led_assessment.dashboard.total_indicators') }}</span><strong>{{ $summary['total'] }}</strong><small>{{ __('led_assessment.dashboard.instrument_configuration') }}</small></article>
            <article class="success"><span>{{ __('led_assessment.dashboard.approved') }}</span><strong>{{ $summary['approved'] }}</strong><small>{{ __('led_assessment.dashboard.coverage', ['percentage' => $summary['total'] ? round($summary['approved'] / $summary['total'] * 100) : 0]) }}</small></article>
            <article class="gold"><span>{{ __('led_assessment.dashboard.approved_average') }}</span><strong><x-led-score-value :score="$summary['average']" /></strong><small>{{ __('led_assessment.dashboard.draft_review_excluded') }}</small></article>
            <article class="danger"><span>{{ __('led_assessment.dashboard.not_approved') }}</span><strong>{{ $summary['pending'] }}</strong><small>{{ __('led_assessment.dashboard.no_dashboard_score') }}</small></article>
        </section>

        <div class="led-dashboard-grid">
            <section class="led-dashboard-card">
                <header><div><strong>{{ __('led_assessment.dashboard.score_distribution') }}</strong><small>{{ __('led_assessment.dashboard.draft_not_mixed') }}</small></div></header>
                <div class="led-distribution">
                    @foreach ($summary['distribution'] as $bucket)
                        @php
                            $scoreTone = match ((float) $bucket['score']) {
                                4.0 => '4',
                                3.0 => '3',
                                2.0 => '2',
                                1.0 => '1',
                                default => 'neutral',
                            };
                        @endphp
                        <div class="led-score-tone score-tone-{{ $scoreTone }}" data-score-tone="{{ $scoreTone }}"><span class="score"><x-led-score-value :score="$bucket['score']" /></span><strong>{{ $bucket['count'] }}</strong><small>{{ $bucket['label'] }}</small></div>
                    @endforeach
                </div>
            </section>
            <section class="led-dashboard-card">
                <header><div><strong>{{ __('led_assessment.dashboard.indicator_status') }}</strong><small>{{ __('led_assessment.dashboard.pointer_status_consistency') }}</small></div></header>
                <div class="led-dashboard-list">
                    @foreach ($summary['assessments'] as $assessment)
                        @php $approved = $assessment->currentApprovedRevision; @endphp
                        <div>
                            <span>{{ str_pad((string) $assessment->indicator->indicator_number, 2, '0', STR_PAD_LEFT) }}</span>
                            <p><strong>{{ $assessment->indicator->title }}</strong><small>{{ __('led_assessment.dashboard.criterion', ['code' => $assessment->indicator->criterion_code]) }}</small></p>
                            @if ($approved?->status === 'approved' && $approved->rubric)
                                @php
                                    $scoreTone = match ((float) $approved->rubric->internal_score) {
                                        4.0 => '4',
                                        3.0 => '3',
                                        2.0 => '2',
                                        1.0 => '1',
                                        default => 'neutral',
                                    };
                                @endphp
                                <b class="led-approved-score led-score-tone score-tone-{{ $scoreTone }}" data-approved-score="true" data-score-tone="{{ $scoreTone }}"><x-led-score-value :score="$approved->rubric->internal_score" /> · {{ $approved->rubric->category_label }}</b>
                            @else
                                <b class="muted">{{ __('led_assessment.dashboard.not_approved') }}</b>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
