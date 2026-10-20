<div class="evidence-repository-page">
    @php
        $formatBytes = static function (int $bytes): string {
            if ($bytes >= 1048576) {
                return __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($bytes / 1048576, 2, true)]);
            }

            return __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format(max($bytes, 1) / 1024, 1, true)]);
        };
        $formatDate = static fn ($date): string => $date->copy()->locale(app()->getLocale())->translatedFormat('d M Y');
        $formatDateTime = static fn ($date): string => \App\Support\BusinessDateTime::label($date);
    @endphp

    <header class="repository-heading">
        <div>
            <span class="eyebrow dark">{{ __('repository.heading.eyebrow') }}</span>
            <h1>{{ __('repository.heading.title') }}</h1>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->name }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">{{ __('repository.heading.read_only') }} &middot;
                    {{ \App\Support\LocalizedLabel::status($project->status) }}</span>
            @endif
        </div>
        <div class="repository-heading-actions">
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
            <div class="repository-deadline">
                <span aria-hidden="true">&#9635;</span>
                <div><small>{{ __('repository.heading.submission_deadline') }}</small><strong>{{ $formatDate($project->final_deadline) }}</strong></div>
            </div>
        </div>
    </header>

    <section class="repository-filter-bar" aria-label="{{ __('repository.filters.aria_label') }}">
        <label class="repository-search-field">
            <span>{{ __('common.labels.search') }}</span>
            <input type="search" wire:model.live.debounce.350ms="search"
                placeholder="{{ __('repository.filters.search_placeholder') }}">
        </label>
        <label>
            <span>{{ __('common.labels.criterion') }}</span>
            <select wire:model.live="criterionId">
                <option value="">{{ __('repository.filters.all_criteria') }}</option>
                @foreach ($criteria as $criterion)
                    <option value="{{ $criterion->id }}">{{ $criterion->code }} - {{ $criterion->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('repository.filters.verification_status') }}</span>
            <select wire:model.live="workflowStatus">
                <option value="">{{ __('repository.filters.all_statuses') }}</option>
                @foreach (['not_started', 'draft', 'pending_review', 'need_revision', 'verified'] as $status)
                    <option value="{{ $status }}">{{ $statusLabels[$status] }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('repository.filters.file_type') }}</span>
            <select wire:model.live="extension">
                <option value="">{{ __('repository.filters.all_file_types') }}</option>
                @foreach ($fileTypes as $fileType)
                    <option value="{{ $fileType }}">{{ strtoupper($fileType) }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('repository.filters.uploader') }}</span>
            <select wire:model.live="uploaderId">
                <option value="">{{ __('repository.filters.all_uploaders') }}</option>
                @foreach ($uploaderOptions as $uploader)
                    <option value="{{ $uploader->id }}">{{ $uploader->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="repository-reset-button" type="button" wire:click="resetFilters">{{ __('repository.filters.reset') }}</button>
    </section>

    <div class="repository-workspace">
        <aside class="repository-folder-panel">
            <header><span class="repository-panel-icon" aria-hidden="true">&#9638;</span>
                <div>
                    <h2>{{ __('repository.folders.title') }}</h2>
                    <p>{{ __('repository.folders.help') }}</p>
                </div>
            </header>
            <nav class="repository-folder-tree" aria-label="{{ __('repository.folders.aria_label') }}">
                <button class="repository-root-folder {{ $folderType === 'all' ? 'selected' : '' }}" type="button"
                    wire:click="selectFolder('all', null)">
                    <span aria-hidden="true">&#128193;</span><strong>{{ __('repository.folders.all_documents') }}</strong><small>{{ $folderCount }}</small>
                </button>

                <ul>
                    @foreach ($criteria as $criterion)
                        @php $criterionExpanded = in_array($criterion->id, $expandedCriteria, true); @endphp
                        <li wire:key="repository-criterion-{{ $criterion->id }}">
                            <div
                                class="repository-tree-row {{ $folderType === 'criterion' && $folderNodeId === $criterion->id ? 'selected' : '' }}">
                                <button class="repository-tree-toggle" type="button"
                                    wire:click="toggleCriterion({{ $criterion->id }})"
                                    aria-expanded="{{ $criterionExpanded ? 'true' : 'false' }}">{{ $criterionExpanded ? '−' : '+' }}</button>
                                <button class="repository-tree-label" type="button"
                                    wire:click="selectFolder('criterion', {{ $criterion->id }})">
                                    <span aria-hidden="true">&#128193;</span><strong>{{ __('repository.folders.criterion', ['code' => $criterion->code]) }}</strong><small>{{ $criterion->repository_documents_count }}</small>
                                </button>
                            </div>
                            @if ($criterionExpanded)
                                <ul>
                                    @foreach ($criterion->items as $item)
                                        @php $itemExpanded = in_array($item->id, $expandedItems, true); @endphp
                                        <li wire:key="repository-item-{{ $item->id }}">
                                            <div
                                                class="repository-tree-row child {{ $folderType === 'item' && $folderNodeId === $item->id ? 'selected' : '' }}">
                                                <button class="repository-tree-toggle" type="button"
                                                    wire:click="toggleItem({{ $item->id }})"
                                                    aria-expanded="{{ $itemExpanded ? 'true' : 'false' }}">{{ $itemExpanded ? '−' : '+' }}</button>
                                                <button class="repository-tree-label" type="button"
                                                    wire:click="selectFolder('item', {{ $item->id }})"
                                                    title="{{ $item->title }}">
                                                    <span
                                                        aria-hidden="true">&#128193;</span><strong>{{ $criterion->code }}.{{ $item->number }}</strong><small>{{ $item->repository_documents_count }}</small>
                                                </button>
                                            </div>
                                            @if ($itemExpanded)
                                                <ul>
                                                    @foreach ($item->requirements as $requirement)
                                                        <li>
                                                            <button
                                                                class="repository-requirement-node {{ $folderType === 'requirement' && $folderNodeId === $requirement->id ? 'selected' : '' }}"
                                                                type="button"
                                                                wire:click="selectFolder('requirement', {{ $requirement->id }})"
                                                                title="{{ $requirement->title }}">
                                                                <span
                                                                    aria-hidden="true">&#128196;</span><strong>R{{ $requirement->number }}</strong><small>{{ $requirement->repository_documents_count }}</small>
                                                            </button>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        <main class="repository-main-column">
            <section class="repository-list-panel">
                <header class="repository-list-heading">
                    <div><span class="repository-panel-icon" aria-hidden="true">&#9638;</span>
                        <div>
                            <h2>{{ __('repository.list.title') }}</h2>
                            <p>{{ trans_choice('repository.list.filtered_count', $documents->total(), ['count' => $documents->total()]) }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="repository-sort-select"><span>{{ __('repository.list.sort') }}</span><select wire:model.live="sort">
                                @foreach ($sortOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select></label>
                        @if ($canUpload)
                            <a class="repository-upload-button"
                                href="{{ route('documents.index', ['proyek' => $project->id]) }}" wire:navigate>+
                                {{ __('repository.list.upload_document') }}</a>
                        @endif
                    </div>
                </header>

                <div class="repository-table-wrap">
                    <table class="repository-document-table">
                        <thead>
                            <tr>
                                <th>{{ __('repository.table.select') }}</th>
                                <th>{{ __('repository.table.file_name') }}</th>
                                <th>{{ __('common.labels.criterion') }}</th>
                                <th>{{ __('repository.table.item_requirement') }}</th>
                                <th>{{ __('repository.table.type') }}</th>
                                <th>{{ __('repository.table.size') }}</th>
                                <th>{{ __('repository.table.uploader') }}</th>
                                <th>{{ __('repository.table.uploaded_at') }}</th>
                                <th>{{ __('repository.table.current_status') }}</th>
                                <th>{{ __('repository.table.version') }}</th>
                                <th>{{ __('common.labels.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($documents as $document)
                                @php
                                    $requirement = $document->requirement;
                                    $item = $requirement->item;
                                    $criterion = $item->criterion;
                                    $isSelected = $selectedDocument?->id === $document->id;
                                    $isPreviewable = in_array(
                                        strtolower($document->extension),
                                        $previewableExtensions,
                                        true,
                                    );
                                @endphp
                                <tr class="{{ $isSelected ? 'selected' : '' }}"
                                    wire:key="repository-document-{{ $document->id }}">
                                    <td><button class="repository-row-select" type="button"
                                            wire:click="selectDocument({{ $document->id }})"
                                            aria-label="{{ __('repository.actions.select_file', ['name' => $document->original_name]) }}">{{ $isSelected ? '&#10003;' : '' }}</button>
                                    </td>
                                    <td>
                                        <button class="repository-file-name" type="button"
                                            wire:click="selectDocument({{ $document->id }})">
                                            <span
                                                class="repository-file-icon type-{{ strtolower($document->extension) }}">{{ strtoupper($document->extension) }}</span>
                                            <strong
                                                title="{{ $document->original_name }}">{{ $document->original_name }}</strong>
                                        </button>
                                    </td>
                                    <td><strong>{{ $criterion->code }}</strong><small>{{ $criterion->name }}</small>
                                    </td>
                                    <td><strong>{{ $criterion->code }}.{{ $item->number }} /
                                            R{{ $requirement->number }}</strong><small>{{ $requirement->title }}</small>
                                    </td>
                                    <td>{{ strtoupper($document->extension) }}</td>
                                    <td>{{ $formatBytes($document->size_bytes) }}</td>
                                    <td>{{ $document->uploader->name }}</td>
                                    <td>{{ $formatDateTime($document->uploaded_at) }}</td>
                                    <td><span
                                            class="workflow-badge status-{{ $requirement->workflow_status }}">{{ $statusLabels[$requirement->workflow_status] ?? \App\Support\LocalizedLabel::status($requirement->workflow_status) }}</span>
                                    </td>
                                    <td><strong>v{{ $document->revision_number }}</strong><small>{{ $document->is_active ? __('common.status.active') : __('repository.states.historical') }}</small>
                                    </td>
                                    <td>
                                        @if ($isPreviewable)
                                            <a class="repository-table-action"
                                                href="{{ route('documents.preview', ['document' => $document, 'asal' => 'repository']) }}"
                                                target="_blank" rel="noopener noreferrer">{{ __('common.actions.view') }}</a>
                                        @else
                                            <a class="repository-table-action"
                                                href="{{ route('documents.download', $document) }}">{{ __('common.actions.download') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11">
                                        <div class="repository-empty"><strong>{{ __('repository.empty.title') }}</strong><span>{{ __('repository.empty.help') }}</span></div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($documents->hasPages())
                    <div class="repository-pagination">
                        <span>{{ trans_choice('repository.pagination.summary', $documents->total(), ['first' => $documents->firstItem(), 'last' => $documents->lastItem(), 'total' => $documents->total()]) }}</span>
                        {{ $documents->links() }}
                    </div>
                @endif
            </section>

            @if ($selectedDocument)
                @php
                    $selectedRequirement = $selectedDocument->requirement;
                    $selectedItem = $selectedRequirement->item;
                    $selectedCriterion = $selectedItem->criterion;
                    $selectedPreviewable = in_array(
                        strtolower($selectedDocument->extension),
                        $previewableExtensions,
                        true,
                    );
                @endphp
                <section class="repository-detail-panel" wire:key="repository-detail-{{ $selectedDocument->id }}">
                    <nav class="repository-detail-breadcrumb" aria-label="{{ __('repository.detail.breadcrumb_aria') }}">
                        <span>{{ $project->code }}</span><b>&rsaquo;</b><span>{{ __('repository.detail.criterion', ['code' => $selectedCriterion->code]) }}</span><b>&rsaquo;</b><span>{{ $selectedCriterion->code }}.{{ $selectedItem->number }}</span><b>&rsaquo;</b><strong>{{ __('repository.detail.requirement', ['number' => $selectedRequirement->number]) }}</strong>
                    </nav>
                    <div class="repository-detail-heading">
                        <div class="repository-file-icon large type-{{ strtolower($selectedDocument->extension) }}">
                            {{ strtoupper($selectedDocument->extension) }}</div>
                        <div>
                            <h2>{{ $selectedDocument->original_name }}</h2>
                            <p>{{ __('repository.detail.current_status') }} <span
                                    class="workflow-badge status-{{ $selectedRequirement->workflow_status }}">{{ $statusLabels[$selectedRequirement->workflow_status] ?? \App\Support\LocalizedLabel::status($selectedRequirement->workflow_status) }}</span>
                            </p>
                        </div>
                        <div class="repository-detail-actions">
                            @if ($selectedPreviewable)
                                <a class="primary"
                                    href="{{ route('documents.preview', ['document' => $selectedDocument, 'asal' => 'repository']) }}"
                                    target="_blank" rel="noopener noreferrer">{{ __('repository.actions.view_document') }}</a>
                            @endif
                            <a href="{{ route('documents.download', $selectedDocument) }}">{{ __('repository.actions.download_original') }}</a>
                            <a href="#repository-version-history">{{ __('repository.actions.version_history') }}</a>
                        </div>
                    </div>

                    <div class="repository-detail-grid">
                        <dl class="repository-file-metadata">
                            <div>
                                <dt>{{ __('repository.detail.format') }}</dt>
                                <dd>{{ strtoupper($selectedDocument->extension) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('repository.detail.size') }}</dt>
                                <dd>{{ $formatBytes($selectedDocument->size_bytes) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('repository.detail.uploader') }}</dt>
                                <dd>{{ $selectedDocument->uploader->name }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('repository.detail.uploaded_at') }}</dt>
                                <dd>{{ $formatDateTime($selectedDocument->uploaded_at) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('repository.detail.file_version') }}</dt>
                                <dd>v{{ $selectedDocument->revision_number }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('repository.detail.file_status') }}</dt>
                                <dd>{{ $selectedDocument->is_active ? __('common.status.active') : __('repository.states.historical') }}</dd>
                            </div>
                        </dl>

                        <div class="repository-related-item">
                            <header><span aria-hidden="true">&#128279;</span>
                                <div><strong>{{ __('repository.related.title') }}</strong><small>{{ __('repository.related.help') }}</small></div>
                            </header>
                            <div>
                                <span>{{ $selectedCriterion->code }}.{{ $selectedItem->number }} /
                                    R{{ $selectedRequirement->number }}</span>
                                <strong>{{ $selectedRequirement->title }}</strong>
                                <small>{{ $selectedItem->title }}</small>
                                <a
                                    href="{{ route('follow-up-led.index', ['proyek' => $project->id, 'kriteria' => $selectedCriterion->id, 'cari' => $selectedItem->title]) }}">{{ __('repository.actions.open_requirement') }}</a>
                            </div>
                            <p>{{ __('repository.related.note') }}</p>
                        </div>
                    </div>

                    <div class="repository-version-history" id="repository-version-history">
                        <header>
                            <div>
                                <h3>{{ __('repository.history.title') }}</h3>
                                <p>{{ __('repository.history.help') }}</p>
                            </div><span>{{ trans_choice('repository.history.file_count', $versions->count(), ['count' => $versions->count()]) }}</span>
                        </header>
                        <div class="repository-version-list">
                            @foreach ($versions as $version)
                                <article class="{{ $version->id === $selectedDocument->id ? 'selected' : '' }}">
                                    <button type="button"
                                        wire:click="selectDocument({{ $version->id }})"><strong>v{{ $version->revision_number }}
                                            &middot;
                                            {{ $version->original_name }}</strong><span>{{ __('repository.history.uploaded_by', ['date' => $formatDateTime($version->uploaded_at), 'name' => $version->uploader->name]) }}</span></button>
                                    <span
                                        class="repository-file-state {{ $version->is_active ? 'active' : 'historical' }}">{{ $version->is_active ? __('common.status.active') : __('repository.states.historical') }}</span>
                                    <div class="repository-snapshot-list">
                                        @forelse ($version->reviewSubmissions as $submission)
                                            <span>{{ __('repository.history.snapshot', ['revision' => $submission->revision_number, 'status' => $statusLabels[$submission->status] ?? \App\Support\LocalizedLabel::status($submission->status)]) }}</span>
                                        @empty
                                            <span>{{ __('repository.history.no_snapshot') }}</span>
                                        @endforelse
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>

                    <div class="repository-scope-note">
                        <strong>{{ __('repository.scope.title') }}</strong>
                        <span>{{ __('repository.scope.help') }}</span>
                    </div>
                </section>
            @endif
        </main>
    </div>
</div>
