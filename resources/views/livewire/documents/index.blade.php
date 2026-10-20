<div class="documents-index-page">
    <header class="documents-index-heading">
        <div>
            <span class="eyebrow dark">{{ __('documents.index.eyebrow') }}</span>
            <h1>{{ __('documents.index.heading') }}</h1>
            <p>{{ __('documents.index.introduction') }}</p>
        </div>
        <div class="documents-project-deadline">
            <span>{{ __('documents.index.final_deadline') }}</span>
            <strong>{{ $project->final_deadline->translatedFormat('d M Y') }}</strong>
        </div>
    </header>

    <section class="documents-project-card">
        <div>
            <small>{{ __('documents.index.accreditation_project') }}</small>
            <h2>{{ $project->name }}</h2>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->code }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">{{ __('documents.index.read_only') }} &middot; {{ $statusLabels[$project->status] ?? __('documents.status.unknown') }}</span>
            @endif
        </div>
        @if ($projects->count() > 1)
            <div class="documents-project-switcher" aria-label="{{ __('documents.index.project_switcher_aria') }}">
                @foreach ($projects as $accessibleProject)
                    <button
                        type="button"
                        wire:key="document-project-{{ $accessibleProject->id }}"
                        wire:click="selectProject({{ $accessibleProject->id }})"
                        class="{{ $project->id === $accessibleProject->id ? 'active' : '' }}"
                    >
                        {{ $accessibleProject->code }}
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    @if ($criteria->isEmpty())
        <section class="empty-state">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm2 3h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg>
            </div>
            <h2>{{ __('documents.index.empty_criteria_title') }}</h2>
            <p>{{ __('documents.index.empty_criteria_body') }}</p>
        </section>
    @else
        <nav class="documents-criteria-tabs" aria-label="{{ __('documents.index.criteria_aria') }}">
            @foreach ($criteria as $criterion)
                <button
                    type="button"
                    wire:key="document-criterion-{{ $criterion->id }}"
                    wire:click="selectCriterion({{ $criterion->id }})"
                    class="{{ $activeCriterion?->id === $criterion->id ? 'active' : '' }}"
                    style="--criterion-header: {{ $criterion->color_header }}; --criterion-body: {{ $criterion->color_body }}"
                    aria-pressed="{{ $activeCriterion?->id === $criterion->id ? 'true' : 'false' }}"
                >
                    <span>{{ $criterion->code }}</span>
                    <div>
                        <strong>{{ $criterion->name }}</strong>
                        <small>{{ trans_choice('documents.index.item_count', $criterion->items_count, ['count' => $criterion->items_count]) }}</small>
                    </div>
                </button>
            @endforeach
        </nav>

        <section class="documents-work-card">
            <div class="documents-work-heading" style="--criterion-header: {{ $activeCriterion->color_header }}; --criterion-body: {{ $activeCriterion->color_body }}">
                <span>{{ $activeCriterion->code }}</span>
                <div>
                    <small>{{ __('documents.index.active_criterion') }}</small>
                    <h2>{{ $activeCriterion->name }}</h2>
                </div>
                <strong>{{ trans_choice('documents.index.item_count', $items->count(), ['count' => $items->count()]) }}</strong>
            </div>

            <div class="documents-item-list">
                @forelse ($items as $item)
                    <article class="documents-item-card {{ $item->is_led_header ? 'header-led' : '' }}" wire:key="document-item-{{ $item->id }}">
                        <header>
                            <span class="documents-item-number">{{ $activeCriterion->code }}.{{ $item->number }}</span>
                            <div>
                                <h3>{{ $item->title }}</h3>
                                <p>{{ $item->is_led_header ? __('documents.index.led_header') : trans_choice('documents.index.requirement_count', $item->requirements->count(), ['count' => $item->requirements->count()]) }}</p>
                            </div>
                            @if ($item->is_led_header)
                                <span class="documents-header-badge">{{ __('documents.index.information') }}</span>
                            @endif
                        </header>

                        @if ($item->is_led_header)
                            <div class="documents-header-notice">{{ __('documents.index.led_header_notice') }}</div>
                        @else
                            <div class="documents-requirement-table-wrap">
                                <table class="documents-requirement-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('documents.index.table.number') }}</th>
                                            <th>{{ __('documents.index.table.requirement') }}</th>
                                            <th>{{ __('documents.index.table.type') }}</th>
                                            <th>{{ __('documents.index.table.status') }}</th>
                                            <th>{{ __('documents.index.table.file') }}</th>
                                            <th>{{ __('documents.index.table.action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($item->requirements as $requirement)
                                            @php $latestDocument = $requirement->documents->first(); @endphp
                                            <tr wire:key="document-requirement-{{ $requirement->id }}">
                                                <td>{{ $requirement->number }}</td>
                                                <td><strong>{{ $requirement->title }}</strong></td>
                                                <td>{{ $requirement->is_required ? __('documents.index.required') : __('documents.index.optional') }}</td>
                                                <td>
                                                    <span class="workflow-badge status-{{ $requirement->workflow_status }}">
                                                        {{ $statusLabels[$requirement->workflow_status] ?? __('documents.status.unknown') }}
                                                    </span>
                                                </td>
                                                <td><strong>{{ $requirement->documents->count() }}</strong> {{ trans_choice('documents.index.file_unit', $requirement->documents->count()) }}</td>
                                                <td>
                                                    <div class="documents-requirement-actions">
                                                        @can('upload', $requirement)
                                                            <a class="upload" href="{{ route('documents.upload', ['requirement' => $requirement, 'proyek' => $project->id]) }}" wire:navigate>{{ __('documents.actions.upload') }}</a>
                                                        @elsecan('accessUpload', $requirement)
                                                            <span class="locked">{{ __('documents.actions.locked') }}</span>
                                                        @endcan

                                                        @if ($latestDocument)
                                                            @can('view', $latestDocument)
                                                                <a href="{{ route('documents.preview', $latestDocument) }}" target="_blank" rel="noopener">{{ __('documents.actions.view') }}</a>
                                                            @endcan
                                                        @endif

                                                        @cannot('accessUpload', $requirement)
                                                            @if (! $latestDocument)
                                                                <span class="read-only">{{ __('documents.index.read_only') }}</span>
                                                            @endif
                                                        @endcannot
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6"><div class="documents-requirement-empty">{{ __('documents.index.empty_requirements') }}</div></td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="documents-items-empty">{{ __('documents.index.empty_items') }}</div>
                @endforelse
            </div>
        </section>
    @endif
</div>
