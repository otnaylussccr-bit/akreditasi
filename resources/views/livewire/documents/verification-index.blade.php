<div class="verification-index-page">
    <header class="verification-index-heading">
        <div>
            <span class="eyebrow dark">{{ __('verification.index.eyebrow') }}</span>
            <h1>{{ __('verification.index.title') }}</h1>
            <p>{{ __('verification.index.intro') }}</p>
        </div>
        <div class="verification-queue-stat">
            <small>{{ __('verification.index.submissions_shown') }}</small>
            <strong>{{ $submissions->count() }}</strong>
            <span>{{ $statusLabels[$status] ?? __('verification.statuses.all') }}</span>
        </div>
    </header>

    <section class="verification-project-card">
        <div>
            <small>{{ __('verification.index.accreditation_project') }}</small>
            <h2>{{ $project->name }}</h2>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->code }}</p>
        </div>
        @if ($projects->count() > 1)
            <label>
                <span>{{ __('verification.index.select_project') }}</span>
                <select wire:model.live="projectId">
                    @foreach ($projects as $accessibleProject)
                        <option value="{{ $accessibleProject->id }}">{{ $accessibleProject->code }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </section>

    <section class="verification-filter-card">
        <label>
            <span>{{ __('verification.index.criterion') }}</span>
            <select wire:model.live="criterionId">
                <option value="">{{ __('verification.index.all_criteria') }}</option>
                @foreach ($criteria as $criterion)
                    <option value="{{ $criterion->id }}">{{ $criterion->code }} &middot; {{ $criterion->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ __('verification.index.status') }}</span>
            <select wire:model.live="status">
                <option value="pending_review">{{ __('verification.statuses.pending_review') }}</option>
                <option value="verified">{{ __('verification.statuses.verified') }}</option>
                <option value="need_revision">{{ __('verification.statuses.need_revision') }}</option>
                <option value="all">{{ __('verification.statuses.all') }}</option>
            </select>
        </label>
    </section>

    <section class="verification-queue-card">
        <div class="verification-queue-heading">
            <div><strong>{{ __('verification.index.queue_title') }}</strong><span>{{ __('verification.index.queue_scope') }}</span></div>
            <span>{{ trans_choice('verification.index.submission_count', $submissions->count(), ['count' => $submissions->count()]) }}</span>
        </div>
        <div class="verification-table-wrap">
            <table class="verification-table">
                <thead>
                    <tr><th>{{ __('verification.index.columns.item') }}</th><th>{{ __('verification.index.columns.submitter') }}</th><th>{{ __('verification.index.columns.file') }}</th><th>{{ __('verification.index.columns.submitted_at') }}</th><th>{{ __('verification.index.columns.status') }}</th><th>{{ __('verification.index.columns.action') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($submissions as $submission)
                        @php
                            $requirement = $submission->requirement;
                            $item = $requirement->item;
                            $criterion = $item->criterion;
                        @endphp
                        <tr wire:key="verification-submission-{{ $submission->id }}">
                            <td>
                                <span class="verification-item-code">{{ $criterion->code }}.{{ $item->number }} / R{{ $requirement->number }}</span>
                                <strong>{{ $requirement->title }}</strong>
                                <small>{{ $criterion->name }}</small>
                            </td>
                            <td><strong>{{ $submission->submitter->name }}</strong><small>{{ __('verification.labels.revision', ['number' => $submission->revision_number]) }}</small></td>
                            <td><strong>{{ $submission->documents_count }}</strong><small>{{ trans_choice('verification.index.snapshot_files', $submission->documents_count) }}</small></td>
                            <td><strong>{{ \App\Support\BusinessDateTime::inBusinessTimezone($submission->submitted_at)->locale(app()->getLocale())->translatedFormat('d M Y') }}</strong><small>{{ \App\Support\BusinessDateTime::label($submission->submitted_at, 'H:i') }}</small></td>
                            <td><span class="workflow-badge status-{{ $submission->status }}">{{ $statusLabels[$submission->status] ?? __('verification.statuses.unknown') }}</span></td>
                            <td><a href="{{ route('verifications.show', $submission) }}" wire:navigate>{{ __('verification.index.open_verification') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="verification-empty"><strong>{{ __('verification.index.empty_title') }}</strong><span>{{ __('verification.index.empty_body') }}</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
