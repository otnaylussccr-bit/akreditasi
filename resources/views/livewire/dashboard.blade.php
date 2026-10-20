<div class="progress-dashboard-page" @if ($viewerTvMode) wire:poll.30s.visible="refreshDashboard" @endif>
    @if ($viewerTvMode)
        <div class="dashboard-refresh-status" role="status" aria-live="polite">
            <span><i aria-hidden="true"></i>{{ __('dashboard.refresh.automatic') }}</span>
            @if ($lastUpdatedAt)
                <time datetime="{{ $lastUpdatedAt }}">{{ __('dashboard.refresh.last_updated', ['datetime' => __('workflow.datetime.with_timezone', ['datetime' => \App\Support\BusinessDateTime::localizedFormat($lastUpdatedAt, 'd M Y, H:i:s'), 'timezone' => __('workflow.datetime.business_timezone')])]) }}</time>
            @endif
        </div>
    @endif

    @if ($projects->isEmpty())
        <header class="progress-dashboard-heading">
            <div>
                <span class="eyebrow dark">{{ __('dashboard.eyebrow') }}</span>
                <h1>{{ __('dashboard.title') }}</h1>
                <p>{{ __('dashboard.intro.empty') }}</p>
            </div>
        </header>

        <section class="empty-state">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm2 3h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg>
            </div>
            <h2>{{ __('dashboard.empty.title') }}</h2>
            <p>{{ __('dashboard.empty.body') }}</p>
        </section>
    @else
        @php $bucketMap = $statusBuckets->keyBy('status'); @endphp

        <header class="progress-dashboard-heading">
            <div>
                <span class="eyebrow dark">{{ __('dashboard.eyebrow') }}</span>
                <h1>{{ __('dashboard.title') }}</h1>
                <p>{{ __('dashboard.intro.project', ['study_program' => $project->studyProgram->name]) }}</p>
            </div>
            @if ($projects->count() > 1)
                <label class="dashboard-project-select">
                    <span>{{ __('dashboard.project.label') }}</span>
                    <select wire:change="selectProject($event.target.value)">
                        @foreach ($projects as $accessibleProject)
                            <option value="{{ $accessibleProject->id }}" @selected($project->id === $accessibleProject->id)>
                                {{ $accessibleProject->code }} - {{ $accessibleProject->studyProgram->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
        </header>

        <section class="dashboard-deadline-banner deadline-{{ $deadline['state'] }}">
            <div class="dashboard-deadline-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            </div>
            <div class="dashboard-deadline-date">
                <span>{{ __('dashboard.deadline.submission') }}</span>
                <strong>{{ \App\Support\BusinessDateTime::localizedFormat($deadline['date'], 'd M Y') }}</strong>
                <small>{{ $project->code }} &middot; {{ $project->name }}</small>
            </div>
            <div class="dashboard-deadline-remaining">
                <span>{{ __('dashboard.deadline.remaining') }}</span>
                <strong>{{ $deadline['label'] }}</strong>
                <small>{{ __('dashboard.deadline.no_start_date') }}</small>
            </div>
        </section>

        <section class="dashboard-kpi-grid" aria-label="{{ __('dashboard.kpi.aria_label') }}">
            <article class="dashboard-kpi-card total">
                <span class="dashboard-kpi-icon" aria-hidden="true">LED</span>
                <div><span>{{ __('dashboard.kpi.total_items') }}</span><strong>{{ $summary['work_count'] }}</strong><small>{{ trans_choice('dashboard.kpi.headers_excluded', $summary['header_count'], ['count' => $summary['header_count']]) }}</small></div>
            </article>

            @foreach ($statusBuckets as $bucket)
                <article class="dashboard-kpi-card status-{{ $bucket['status'] }}">
                    <span class="dashboard-kpi-icon" aria-hidden="true">
                        @switch($bucket['status'])
                            @case('verified') &#10003; @break
                            @case('pending_review') &#9201; @break
                            @case('need_revision') ! @break
                            @case('draft') D @break
                            @case('in_progress') ~ @break
                            @default &bull;
                        @endswitch
                    </span>
                    <div><span>{{ $bucket['label'] }}</span><strong>{{ $bucket['count'] }}</strong><small>{{ __('dashboard.kpi.percentage_of_work', ['percentage' => number_format($bucket['percentage'], 1, app()->getLocale() === 'id' ? ',' : '.', app()->getLocale() === 'id' ? '.' : ',')]) }}</small></div>
                </article>
            @endforeach
        </section>

        <div class="dashboard-analytics-grid">
            <section class="dashboard-panel criteria-progress-panel">
                <header class="dashboard-panel-heading">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">|||</span><div><h2>{{ __('dashboard.criteria.title') }}</h2><p>{{ __('dashboard.criteria.description') }}</p></div></div>
                    <div class="dashboard-status-legend compact" aria-label="{{ __('dashboard.status_distribution.legend_aria_label') }}">
                        @foreach ($statusBuckets as $bucket)
                            <span><i style="--legend-color: {{ $bucket['color'] }}"></i>{{ $bucket['label'] }}</span>
                        @endforeach
                    </div>
                </header>

                <div class="criteria-progress-table-wrap">
                    <table class="criteria-progress-table">
                        <thead><tr><th>{{ __('dashboard.criteria.columns.criterion') }}</th><th>{{ __('dashboard.criteria.columns.name') }}</th><th>{{ __('dashboard.criteria.columns.status_distribution') }}</th><th>{{ __('dashboard.criteria.columns.completed') }}</th><th>{{ __('dashboard.criteria.columns.completed_total') }}</th></tr></thead>
                        <tbody>
                            @forelse ($criteriaProgress as $entry)
                                <tr class="criterion-identity-row" style="--criterion-header: {{ $entry['criterion']->color_header }}; --criterion-body: {{ $entry['criterion']->color_body }}">
                                    <td class="criterion-identity-code-cell"><span class="dashboard-criterion-code">{{ $entry['criterion']->code }}</span></td>
                                    <td class="criterion-identity-name-cell"><strong>{{ $entry['criterion']->name }}</strong></td>
                                    <td>
                                        @if ($entry['summary']['work_count'] > 0)
                                            <div class="criteria-stacked-bar" aria-label="{{ __('dashboard.criteria.distribution_aria_label', ['criterion' => $entry['criterion']->code]) }}">
                                                @foreach ($entry['segments'] as $segment)
                                                    @if ($segment['count'] > 0)
                                                        <span title="{{ $segment['label'] }}: {{ $segment['count'] }}" style="width: {{ $segment['percentage'] }}%; --segment-color: {{ $segment['color'] }}"></span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="criteria-no-work">{{ __('dashboard.criteria.no_work') }}</span>
                                        @endif
                                    </td>
                                    <td><strong>{{ $entry['summary']['completion_percentage'] }}%</strong></td>
                                    <td>{{ $entry['summary']['verified_count'] }} / {{ $entry['summary']['work_count'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="dashboard-table-empty">{{ __('dashboard.criteria.empty') }}</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-panel status-distribution-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">|||</span><div><h2>{{ __('dashboard.status_distribution.title') }}</h2><p>{{ __('dashboard.status_distribution.description') }}</p></div></div>
                </header>

                @if ($summary['work_count'] > 0)
                    <div class="status-donut-layout">
                        <div class="status-donut" style="--donut-gradient: {{ $donutGradient }}" role="img" aria-label="{{ trans_choice('dashboard.status_distribution.chart_aria_label', $summary['work_count'], ['count' => $summary['work_count']]) }}">
                            <div><strong>{{ $summary['work_count'] }}</strong><span>{{ trans_choice('dashboard.status_distribution.item_unit', $summary['work_count']) }}</span></div>
                        </div>
                        <div class="dashboard-status-legend donut-legend">
                            @foreach ($statusBuckets as $bucket)
                                <div><span><i style="--legend-color: {{ $bucket['color'] }}"></i>{{ $bucket['label'] }}</span><strong>{{ $bucket['count'] }}</strong><small>{{ number_format($bucket['percentage'], 1, app()->getLocale() === 'id' ? ',' : '.', app()->getLocale() === 'id' ? '.' : ',') }}%</small></div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="dashboard-chart-empty"><strong>{{ __('dashboard.status_distribution.empty_title') }}</strong><span>{{ __('dashboard.status_distribution.empty_body') }}</span></div>
                @endif
            </section>
        </div>

        <div class="dashboard-lower-grid">
            <section class="dashboard-panel attention-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon warning" aria-hidden="true">!</span><div><h2>{{ __('dashboard.attention.title') }}</h2><p>{{ trans_choice('dashboard.attention.description', $attentionThresholdDays, ['count' => $attentionThresholdDays]) }}</p></div></div>
                    @if ($followUpUrl)<a href="{{ $followUpUrl }}">{{ __('dashboard.actions.view_all') }}</a>@endif
                </header>

                <div class="dashboard-attention-table-wrap">
                    <table class="dashboard-attention-table">
                        <thead><tr><th>{{ __('dashboard.attention.columns.number') }}</th><th>{{ __('dashboard.attention.columns.item_code') }}</th><th>{{ __('dashboard.attention.columns.item_title') }}</th><th>{{ __('dashboard.attention.columns.criterion') }}</th><th>{{ __('dashboard.attention.columns.aggregate_status') }}</th><th>{{ __('dashboard.attention.columns.internal_deadline') }}</th><th>{{ __('dashboard.attention.columns.remaining') }}</th><th>{{ __('dashboard.attention.columns.action') }}</th></tr></thead>
                        <tbody>
                            @forelse ($attentionItems as $entry)
                                <tr class="attention-criterion-row" style="--criterion-header: {{ $entry['item']->criterion->color_header }}; --criterion-body: {{ $entry['item']->criterion->color_body }}">
                                    <td>{{ $loop->iteration }}</td>
                                    <td class="attention-criterion-code-cell"><strong>{{ $entry['item']->criterion->code }}.{{ $entry['item']->number }}</strong></td>
                                    <td class="attention-criterion-title-cell">{{ $entry['item']->title }}</td>
                                    <td>{{ $entry['item']->criterion->code }} &middot; {{ $entry['item']->criterion->name }}</td>
                                    <td>
                                        <span class="workflow-badge status-{{ $entry['item']->aggregate_status }}">{{ $bucketMap[$entry['item']->aggregate_status]['label'] }}</span>
                                        @if ($entry['item']->has_required_revision)<small class="attention-revision-note">{{ __('dashboard.attention.revision_note') }}</small>@endif
                                    </td>
                                    <td>{{ $entry['deadline'] ? \App\Support\BusinessDateTime::localizedFormat($entry['deadline'], 'd M Y') : __('dashboard.attention.not_defined') }}</td>
                                    <td><span class="attention-time {{ ($entry['days'] ?? 0) < 0 ? 'overdue' : '' }}">{{ $entry['time_label'] }}</span></td>
                                    <td>
                                        @if ($entry['url'])
                                            <a class="attention-open-link" href="{{ $entry['url'] }}">{{ __('dashboard.attention.open_item') }}</a>
                                        @else
                                            <span class="attention-read-only">{{ __('dashboard.attention.read_only') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8"><div class="dashboard-table-empty"><strong>{{ __('dashboard.attention.empty_title') }}</strong><span>{{ __('dashboard.attention.empty_body') }}</span></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-panel activity-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">i</span><div><h2>{{ __('dashboard.activity.title') }}</h2><p>{{ __('dashboard.activity.description') }}</p></div></div>
                    @if ($activityUrl)<a href="{{ $activityUrl }}" wire:navigate>{{ __('dashboard.actions.view_all') }}</a>@endif
                </header>

                @if (! $canViewActivity)
                    <div class="dashboard-activity-empty"><strong>{{ __('dashboard.activity.unavailable_title') }}</strong><span>{{ __('dashboard.activity.unavailable_body') }}</span></div>
                @elseif ($activities->isEmpty())
                    <div class="dashboard-activity-empty"><strong>{{ __('dashboard.activity.empty_title') }}</strong><span>{{ __('dashboard.activity.empty_body') }}</span></div>
                @else
                    <div class="dashboard-activity-list">
                        @foreach ($activities as $activity)
                            <article>
                                <span class="activity-mark" aria-hidden="true">&bull;</span>
                                <div>
                                    <div><strong>{{ $activity['label'] }}</strong><time>{{ $activity['created_at'] ? __('workflow.datetime.with_timezone', ['datetime' => \App\Support\BusinessDateTime::localizedFormat($activity['created_at']), 'timezone' => __('workflow.datetime.business_timezone')]) : __('dashboard.activity.time_unavailable') }}</time></div>
                                    <p><a href="{{ $activity['url'] }}">{{ $activity['item_code'] }} &middot; {{ $activity['item_title'] }}</a></p>
                                    <small>{{ __('dashboard.activity.by_actor', ['actor' => $activity['actor']]) }}</small>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
