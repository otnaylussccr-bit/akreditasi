<div class="follow-up-page">
    @php
        $formatDate = static fn ($date): string => $date->copy()->locale(app()->getLocale())->translatedFormat('d M Y');
    @endphp

    <header class="follow-up-heading">
        <div>
            <span class="eyebrow dark">{{ __('follow_up_led.heading.eyebrow') }}</span>
            <h1>{{ __('follow_up_led.heading.title') }}</h1>
            <p>{{ trans_choice('follow_up_led.heading.work_summary', $workItemCount, ['count' => $workItemCount]) }} &middot; {{ $project->studyProgram->name }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">{{ __('follow_up_led.heading.read_only') }} &middot; {{ \App\Support\LocalizedLabel::status($project->status) }}</span>
            @endif
            @if ($projects->count() > 1)
                <label class="repository-project-select">
                    <span>{{ __('common.labels.project') }}</span>
                    <select wire:change="selectProject($event.target.value)">
                        @foreach ($projects as $accessibleProject)
                            <option value="{{ $accessibleProject->id }}" @selected($project->id === $accessibleProject->id)>
                                {{ $accessibleProject->code }} - {{ $accessibleProject->studyProgram->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
        <div class="deadline-card">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            <div>
                <span>{{ __('follow_up_led.heading.final_deadline') }}</span>
                <strong>{{ $formatDate($project->final_deadline) }}</strong>
            </div>
        </div>
        <div class="follow-up-stat">
            <span>{{ __('follow_up_led.heading.total_work') }}</span>
            <strong>{{ $workItemCount }}</strong>
            <small>{{ trans_choice('follow_up_led.heading.structure_count', $structureCount, ['count' => $structureCount]) }} &middot; {{ trans_choice('follow_up_led.heading.header_count', $headerCount, ['count' => $headerCount]) }}</small>
        </div>
        <div class="follow-up-stat work-stat">
            <span>{{ __('follow_up_led.heading.completed') }}</span>
            <strong>{{ $verifiedWorkItemCount }}</strong>
            <small>{{ trans_choice('follow_up_led.heading.completion_summary', $workItemCount, ['percent' => $workProgress, 'count' => $workItemCount]) }}</small>
        </div>
    </header>

    @if ($criteria->isEmpty())
        <section class="empty-state">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm2 3h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg>
            </div>
            <h2>{{ __('follow_up_led.empty_criteria.title') }}</h2>
            <p>{{ __('follow_up_led.empty_criteria.help') }}</p>
        </section>
    @else
        <section class="filter-card criteria-filter-card" aria-label="{{ __('follow_up_led.filters.aria_label') }}">
            <div class="filter-field">
                <label for="criterion-filter">{{ __('common.labels.criterion') }}</label>
                <select id="criterion-filter" wire:change="selectCriterion($event.target.value)">
                    @foreach ($criteria as $criterion)
                        <option value="{{ $criterion->id }}" @selected($activeCriterion?->id === $criterion->id)>
                            {{ $criterion->code }} &middot; {{ $criterion->name }} ({{ $criterion->items_count }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="status-filter">{{ __('common.labels.status') }}</label>
                <select id="status-filter" wire:model.live="workflowStatus">
                    <option value="">{{ __('follow_up_led.filters.all_statuses') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="pic-filter">{{ __('follow_up_led.filters.source_pic') }}</label>
                <select id="pic-filter" wire:model.live="sourcePic">
                    <option value="">{{ __('follow_up_led.filters.all_source_pics') }}</option>
                    @foreach ($sourcePics as $pic)
                        <option value="{{ $pic }}">{{ $pic }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="item-search">{{ __('common.labels.search') }}</label>
                <input id="item-search" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('follow_up_led.filters.search_placeholder') }}">
            </div>
            <button class="filter-reset" type="button" wire:click="resetFilters">{{ __('follow_up_led.filters.reset') }}</button>
        </section>

        @php
            $statusLabels = ['header' => __('follow_up_led.statuses.header')] + $statusOptions;
        @endphp

        <div class="follow-up-workspace {{ $selectedItem ? 'has-detail' : '' }}">
            <section class="follow-up-list-card">
                <div class="table-card-heading">
                    <div>
                        <span>{{ __('common.labels.criterion') }} {{ $activeCriterion->code }} &middot; {{ $activeCriterion->name }}</span>
                        <small>{{ __('follow_up_led.list.help') }}</small>
                    </div>
                    <span>{{ trans_choice('follow_up_led.list.displayed', $items->total(), ['visible' => $items->count(), 'total' => $items->total()]) }}</span>
                </div>

                <div class="follow-up-item-list">
                    @forelse ($items as $item)
                        @php
                            $deadline = $item->target_deadline ?? $item->source_deadline;
                            $isActive = $selectedItem?->id === $item->id;
                        @endphp
                        <article
                            class="follow-up-item-card aggregate-status-{{ $item->aggregate_status }} {{ $isActive ? 'active' : '' }} {{ $item->is_led_header ? 'led-header-card' : '' }}"
                            wire:key="follow-up-item-{{ $item->id }}"
                            style="--criterion-header: {{ $item->criterion->color_header }}; --criterion-body: {{ $item->criterion->color_body }}"
                        >
                            <div class="follow-up-item-summary">
                                <span class="follow-up-item-code">{{ $item->criterion->code }}.{{ $item->number }}</span>
                                <div class="follow-up-item-title">
                                    <h2>{{ $item->title }}</h2>
                                    <p>{{ $item->is_led_header
                                        ? __('follow_up_led.item.header_description')
                                        : trans_choice('follow_up_led.item.requirement_count', $item->requirements->count(), ['count' => $item->requirements->count()]) }}</p>
                                </div>
                                <div class="follow-up-item-status">
                                    <span class="workflow-badge status-{{ $item->aggregate_status }}">{{ $statusLabels[$item->aggregate_status] }}</span>
                                    @if ($item->has_required_revision)
                                        <small>{{ __('follow_up_led.item.needs_revision') }}</small>
                                    @endif
                                </div>
                                <button
                                    class="follow-up-item-toggle"
                                    type="button"
                                    wire:click="openDetail({{ $item->id }})"
                                    aria-expanded="{{ $isActive ? 'true' : 'false' }}"
                                >{{ $isActive ? __('follow_up_led.item.detail_open') : __('follow_up_led.item.view_detail') }}</button>
                            </div>

                            @unless ($item->is_led_header)
                                <div class="follow-up-item-meta">
                                    <div><span>{{ __('follow_up_led.item.source_pic') }}</span><strong>{{ $item->source_pic ?: __('follow_up_led.item.not_listed') }}</strong></div>
                                    <div><span>{{ __('follow_up_led.item.internal_deadline') }}</span><strong>{{ $deadline ? $formatDate($deadline) : __('common.empty.not_defined') }}</strong></div>
                                    <div class="follow-up-item-progress">
                                        <div><span>{{ __('follow_up_led.item.required_progress') }}</span><strong>{{ $item->verified_required_count }} / {{ $item->required_count }}</strong><b>{{ $item->verified_progress }}%</b></div>
                                        <div class="progress-track progress-{{ $item->has_required_revision ? 'need_revision' : $item->aggregate_status }}" aria-label="{{ __('follow_up_led.item.progress_aria', ['percent' => $item->verified_progress]) }}">
                                            <span style="width: {{ $item->verified_progress }}%"></span>
                                        </div>
                                    </div>
                                </div>
                            @endunless

                            @if ($isActive)
                                @if ($item->is_led_header)
                                    <div class="follow-up-header-notice">{{ __('follow_up_led.header_notice') }}</div>
                                @else
                                    <div class="follow-up-expanded" wire:key="expanded-item-{{ $selectedItem->id }}">
                                        <div class="follow-up-expanded-heading">
                                            <div>
                                                <strong>{{ __('follow_up_led.expanded.title') }}</strong>
                                                <span>{{ __('follow_up_led.expanded.help') }}</span>
                                            </div>
                                            <span>{{ trans_choice('follow_up_led.expanded.requirement_count', $selectedItem->requirements->count(), ['count' => $selectedItem->requirements->count()]) }}</span>
                                        </div>
                                        <div class="follow-up-requirement-table-wrap">
                                            <table class="follow-up-requirement-table">
                                                <thead>
                                                    <tr>
                                                        <th>{{ __('follow_up_led.table.number') }}</th>
                                                        <th>{{ __('follow_up_led.table.requirement') }}</th>
                                                        <th>{{ __('follow_up_led.table.nature') }}</th>
                                                        <th>{{ __('common.labels.status') }}</th>
                                                        <th>{{ __('common.labels.file') }}</th>
                                                        <th>{{ __('common.labels.action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @forelse ($selectedItem->requirements as $requirement)
                                                        @php $latestDocument = $requirement->documents->first(); @endphp
                                                        <tr wire:key="follow-up-requirement-{{ $requirement->id }}">
                                                            <td><span class="requirement-number">{{ $requirement->number }}</span></td>
                                                            <td><strong>{{ $requirement->title }}</strong></td>
                                                            <td><span class="requirement-nature {{ $requirement->is_required ? 'required' : '' }}">{{ $requirement->is_required ? __('follow_up_led.nature.required') : __('follow_up_led.nature.optional') }}</span></td>
                                                            <td>
                                                                <span class="workflow-badge status-{{ $requirement->workflow_status }}">
                                                                    {{ $statusOptions[$requirement->workflow_status] ?? \App\Support\LocalizedLabel::status($requirement->workflow_status) }}
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <strong>{{ trans_choice('follow_up_led.file_count', $requirement->documents->count(), ['count' => $requirement->documents->count()]) }}</strong>
                                                                @if ($latestDocument)
                                                                    <small title="{{ $latestDocument->original_name }}">{{ __('follow_up_led.table.latest', ['name' => $latestDocument->original_name]) }}</small>
                                                                @endif
                                                            </td>
                                                            <td>
                                                                <div class="follow-up-requirement-actions">
                                                                    @can('upload', $requirement)
                                                                        <a class="upload" href="{{ route('documents.upload', ['requirement' => $requirement, 'proyek' => $project->id]) }}" wire:navigate>{{ __('common.actions.upload') }}</a>
                                                                    @elsecan('accessUpload', $requirement)
                                                                        <span class="locked">{{ __('follow_up_led.states.locked') }}</span>
                                                                    @endcan

                                                                    @if ($latestDocument)
                                                                        @can('view', $latestDocument)
                                                                            <a href="{{ route('documents.preview', $latestDocument) }}" target="_blank" rel="noopener noreferrer">{{ __('common.actions.view') }}</a>
                                                                        @endcan
                                                                    @endif

                                                                    @cannot('accessUpload', $requirement)
                                                                        @if (! $latestDocument)
                                                                            <span class="read-only">{{ __('follow_up_led.states.read_only') }}</span>
                                                                        @endif
                                                                    @endcannot
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6"><div class="table-empty compact">{{ __('follow_up_led.empty_requirements') }}</div></td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </article>
                    @empty
                        <div class="table-empty">
                            <strong>{{ __('follow_up_led.empty_items.title') }}</strong>
                            <span>{{ __('follow_up_led.empty_items.help') }}</span>
                        </div>
                    @endforelse
                </div>

                @if ($items->hasPages())
                    <div class="table-pagination">
                        <span>{{ trans_choice('follow_up_led.pagination.summary', $items->total(), ['first' => $items->firstItem(), 'last' => $items->lastItem(), 'total' => $items->total()]) }}</span>
                        {{ $items->links() }}
                    </div>
                @endif
            </section>

            @if ($selectedItem)
                @php $selectedDeadline = $selectedItem->target_deadline ?? $selectedItem->source_deadline; @endphp
                <aside class="item-detail-panel" wire:key="item-detail-{{ $selectedItem->id }}">
                    <div class="detail-panel-head">
                        <div>
                            <span>{{ __('follow_up_led.detail.quick_summary') }}</span>
                            <strong>{{ $selectedItem->criterion->code }}.{{ $selectedItem->number }}</strong>
                        </div>
                        <span class="detail-active-mark" aria-label="{{ __('follow_up_led.states.active_aria') }}">{{ \App\Support\LocalizedLabel::status('active') }}</span>
                    </div>

                    <div class="detail-criterion" style="--criterion-header: {{ $selectedItem->criterion->color_header }}; --criterion-body: {{ $selectedItem->criterion->color_body }}">
                        <span>{{ $selectedItem->criterion->code }}</span>
                        <div><small>{{ __('common.labels.criterion') }}</small><strong>{{ $selectedItem->criterion->name }}</strong></div>
                    </div>

                    <h2>{{ $selectedItem->title }}</h2>
                    <span class="workflow-badge status-{{ $selectedItem->aggregate_status }}">{{ $statusLabels[$selectedItem->aggregate_status] }}</span>

                    @if ($selectedItem->has_required_revision)
                        <div class="detail-revision-alert" role="status">
                            <strong>{{ __('follow_up_led.detail.action_required') }}</strong>
                            <span>{{ __('follow_up_led.detail.revision_help') }}</span>
                        </div>
                    @endif

                    @if ($selectedItem->is_led_header)
                        <div class="header-led-notice">
                            <strong>{{ __('follow_up_led.detail.header_title') }}</strong>
                            <span>{{ __('follow_up_led.detail.header_help') }}</span>
                        </div>
                    @else
                        <dl class="detail-meta compact-meta">
                            <div><dt>{{ __('follow_up_led.item.source_pic') }}</dt><dd>{{ $selectedItem->source_pic ?: __('follow_up_led.item.not_listed') }}</dd></div>
                            <div><dt>{{ __('follow_up_led.detail.deadline') }}</dt><dd>{{ $selectedDeadline ? $formatDate($selectedDeadline) : __('common.empty.not_defined') }}</dd></div>
                        </dl>

                        <div class="detail-progress-card">
                            <div><span>{{ __('follow_up_led.detail.verified_progress') }}</span><strong>{{ $selectedItem->verified_required_count }} / {{ $selectedItem->required_count }}</strong><b>{{ $selectedItem->verified_progress }}%</b></div>
                            <div class="progress-track progress-{{ $selectedItem->has_required_revision ? 'need_revision' : $selectedItem->aggregate_status }}">
                                <span style="width: {{ $selectedItem->verified_progress }}%"></span>
                            </div>
                        </div>

                        @if ($selectedItem->source_detail_text || $selectedItem->source_note || $selectedItem->source_aux_note)
                            <div class="source-description">
                                <strong>{{ __('follow_up_led.detail.description') }}</strong>
                                @if ($selectedItem->source_detail_text)<p>{{ $selectedItem->source_detail_text }}</p>@endif
                                @if ($selectedItem->source_note)<p>{{ $selectedItem->source_note }}</p>@endif
                                @if ($selectedItem->source_aux_note)<p>{{ $selectedItem->source_aux_note }}</p>@endif
                            </div>
                        @endif

                        <div class="requirements-heading">
                            <div><strong>{{ __('follow_up_led.detail.requirement_summary') }}</strong><span>{{ trans_choice('follow_up_led.item.requirement_count', $selectedItem->requirements->count(), ['count' => $selectedItem->requirements->count()]) }}</span></div>
                            <span>{{ __('follow_up_led.detail.actual_status') }}</span>
                        </div>
                        <ol class="requirement-list quick-list">
                            @forelse ($selectedItem->requirements as $requirement)
                                <li>
                                    <span class="requirement-number">{{ $requirement->number }}</span>
                                    <div><strong>{{ $requirement->title }}</strong><span>{{ $requirement->is_required ? __('follow_up_led.nature.required') : __('follow_up_led.nature.optional') }} &middot; {{ trans_choice('follow_up_led.file_count', $requirement->documents->count(), ['count' => $requirement->documents->count()]) }}</span></div>
                                    <span class="workflow-badge status-{{ $requirement->workflow_status }}">{{ $statusOptions[$requirement->workflow_status] ?? \App\Support\LocalizedLabel::status($requirement->workflow_status) }}</span>
                                </li>
                            @empty
                                <li class="requirements-empty">{{ __('follow_up_led.empty_requirements') }}</li>
                            @endforelse
                        </ol>
                    @endif
                </aside>
            @endif
        </div>
    @endif
</div>
