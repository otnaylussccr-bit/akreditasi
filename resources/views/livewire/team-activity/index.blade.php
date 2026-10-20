<div class="team-activity-page">
    <header class="team-activity-heading">
        <div>
            <span class="eyebrow dark">{{ __('team_activity.eyebrow') }}</span>
            <h1>{{ __('team_activity.title') }}</h1>
            <p>{{ __('team_activity.intro', ['study_program' => $project->studyProgram->name]) }}</p>
        </div>
        <div class="team-activity-heading-actions">
            @if ($projects->count() > 1)
                <label class="team-activity-project-select">
                    <span>{{ __('team_activity.project.label') }}</span>
                    <select wire:change="selectProject($event.target.value)">
                        @foreach ($projects as $accessibleProject)
                            <option value="{{ $accessibleProject->id }}" @selected($project->id === $accessibleProject->id)>
                                {{ $accessibleProject->code }} - {{ $accessibleProject->studyProgram->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="team-activity-deadline">
                <span class="team-activity-calendar" aria-hidden="true">&#9635;</span>
                <div><small>{{ __('team_activity.deadline.submission') }}</small><strong>{{ \App\Support\BusinessDateTime::localizedFormat($project->final_deadline, 'd M Y') }}</strong></div>
                @php $remainingDays = max(0, (int) now()->startOfDay()->diffInDays($project->final_deadline->copy()->startOfDay(), false)); @endphp
                <div><small>{{ __('team_activity.deadline.remaining') }}</small><strong>{{ trans_choice('team_activity.deadline.days', $remainingDays, ['count' => $remainingDays]) }}</strong></div>
            </div>
        </div>
    </header>

    <section class="team-activity-filters" aria-label="{{ __('team_activity.filters.aria_label') }}">
        <label>
            <span>{{ __('team_activity.filters.activity') }}</span>
            <select wire:model.live="event">
                <option value="">{{ __('team_activity.filters.all_activities') }}</option>
                @foreach ($eventOptions as $eventKey => $definition)
                    <option value="{{ $eventKey }}">{{ $definition['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('team_activity.filters.user') }}</span>
            <select wire:model.live="actorId">
                <option value="">{{ __('team_activity.filters.all_users') }}</option>
                @foreach ($actorOptions as $actor)
                    <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('team_activity.filters.criterion') }}</span>
            <select wire:model.live="criterionId">
                <option value="">{{ __('team_activity.filters.all_criteria') }}</option>
                @foreach ($criteria as $criterion)
                    <option value="{{ $criterion->id }}">{{ $criterion->code }} - {{ $criterion->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="team-activity-date-filter">
            <label><span>{{ __('team_activity.filters.from') }}</span><input type="date" wire:model.live="dateFrom"></label>
            <label><span>{{ __('team_activity.filters.to') }}</span><input type="date" wire:model.live="dateTo"></label>
        </div>
        <label class="team-activity-search">
            <span>{{ __('team_activity.filters.search') }}</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('team_activity.filters.search_placeholder') }}">
        </label>
        <button type="button" wire:click="resetFilters">{{ __('team_activity.filters.reset') }}</button>
    </section>

    <div class="team-activity-workspace">
        <section class="team-activity-history">
            <header>
                <div><span class="team-activity-section-icon" aria-hidden="true">&#8801;</span><div><h2>{{ __('team_activity.history.title') }}</h2><p>{{ $periodLabel }}</p></div></div>
                <span>{{ trans_choice('team_activity.history.showing', $activities->total(), ['from' => $activities->firstItem() ?? 0, 'to' => $activities->lastItem() ?? 0, 'count' => $activities->total()]) }}</span>
            </header>

            <div class="team-activity-list">
                @forelse ($activities as $activity)
                    <article style="--activity-color: {{ $activity['color'] }}">
                        <time><strong>{{ \App\Support\BusinessDateTime::localizedFormat($activity['created_at'], 'd M Y') }}</strong><span>{{ __('workflow.datetime.with_timezone', ['datetime' => \App\Support\BusinessDateTime::localizedFormat($activity['created_at'], 'H:i'), 'timezone' => __('workflow.datetime.business_timezone')]) }}</span></time>
                        <span class="team-activity-event-icon" aria-hidden="true">
                            @switch($activity['category'])
                                @case('upload') &#8593; @break
                                @case('submission') &#10148; @break
                                @case('revision') ! @break
                                @default &#10003;
                            @endswitch
                        </span>
                        <div class="team-activity-actor">
                            <span>{{ $activity['actor_initials'] }}</span>
                            <div><strong>{{ $activity['actor'] }}</strong><small>{{ $activity['actor_role'] }}</small></div>
                        </div>
                        <div class="team-activity-description">
                            <strong>{{ $activity['label'] }}</strong>
                            <a href="{{ $activity['url'] }}" wire:navigate>{{ $activity['item_code'] }} &middot; {{ $activity['item_title'] }}</a>
                        </div>
                        <span class="team-activity-badge">{{ $activity['short_label'] }}</span>
                        <a class="team-activity-open" href="{{ $activity['url'] }}" wire:navigate aria-label="{{ __('team_activity.history.open_item', ['item' => $activity['item_code']]) }}">&rsaquo;</a>
                    </article>
                @empty
                    <div class="team-activity-empty">
                        <strong>{{ __('team_activity.history.empty_title') }}</strong>
                        <span>{{ __('team_activity.history.empty_body') }}</span>
                    </div>
                @endforelse
            </div>

            @if ($activities->hasPages())
                <footer class="team-activity-pagination">
                    <span>{{ __('team_activity.pagination.label', ['current' => $activities->currentPage(), 'last' => $activities->lastPage()]) }}</span>
                    {{ $activities->onEachSide(1)->links() }}
                </footer>
            @endif
        </section>

        <aside class="team-activity-sidebar">
            <section class="team-activity-summary">
                <header><h2>{{ __('team_activity.summary.title') }}</h2><span>{{ __('workflow.datetime.with_timezone', ['datetime' => \App\Support\BusinessDateTime::today()->locale(app()->getLocale())->translatedFormat('d M Y'), 'timezone' => __('workflow.datetime.business_timezone')]) }}</span></header>
                <div class="team-activity-kpis">
                    <article class="kpi-today"><span aria-hidden="true">&#9638;</span><div><small>{{ __('team_activity.summary.today.label') }}</small><strong>{{ $todayCount }}</strong><p>{{ __('team_activity.summary.today.description') }}</p></div></article>
                    <article class="kpi-verified"><span aria-hidden="true">&#10003;</span><div><small>{{ __('team_activity.summary.verified.label') }}</small><strong>{{ $verifiedCount }}</strong><p>{{ __('team_activity.summary.verified.description') }}</p></div></article>
                    <article class="kpi-revision"><span aria-hidden="true">!</span><div><small>{{ __('team_activity.summary.revisions.label') }}</small><strong>{{ $openRevisionCount }}</strong><p>{{ __('team_activity.summary.revisions.description') }}</p></div></article>
                    <article class="kpi-pic"><span aria-hidden="true">&#9787;</span><div><small>{{ __('team_activity.summary.most_active.label') }}</small>@if ($mostActivePic)<strong class="pic-name">{{ $mostActivePic['name'] }}</strong><p>{{ trans_choice('team_activity.summary.most_active.count', $mostActivePic['count'], ['count' => $mostActivePic['count']]) }}</p>@else<strong class="pic-name">{{ __('team_activity.summary.most_active.none') }}</strong><p>{{ __('team_activity.summary.most_active.empty') }}</p>@endif</div></article>
                </div>
            </section>

            <section class="team-activity-categories">
                <header><h2>{{ __('team_activity.categories.title') }}</h2><span>{{ $periodLabel }}</span></header>
                <div>
                    @foreach ($categories as $category)
                        <article class="category-{{ $category['category'] }}">
                            <span class="category-icon" aria-hidden="true">
                                @switch($category['category'])
                                    @case('upload') &#8593; @break
                                    @case('submission') &#10148; @break
                                    @case('revision') ! @break
                                    @default &#10003;
                                @endswitch
                            </span>
                            <div><strong>{{ $category['label'] }}</strong>@if ($category['latest'])<p>{{ __('team_activity.categories.latest', ['actor' => $category['latest']['actor'], 'item' => $category['latest']['item_code']]) }}</p>@else<p>{{ __('team_activity.categories.empty') }}</p>@endif</div>
                            <strong>{{ $category['count'] }}</strong>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="team-activity-accountability">
                <span aria-hidden="true">&#10003;</span>
                <div><strong>{{ __('team_activity.accountability.title') }}</strong><p>{{ __('team_activity.accountability.body') }}</p></div>
            </section>
        </aside>
    </div>
</div>
