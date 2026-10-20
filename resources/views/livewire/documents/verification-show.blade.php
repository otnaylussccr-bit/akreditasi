@php
    $requirement = $submission->requirement;
    $item = $requirement->item;
    $criterion = $item->criterion;
    $project = $criterion->project;
    $decisionEvent = $submission->events->firstWhere('action', $submission->status);
@endphp

<div class="verification-show-page">
    <header class="verification-process-heading">
        <div>
            <span class="eyebrow dark">{{ __('verification.show.eyebrow') }}</span>
            <h1>{{ $requirement->title }}</h1>
        </div>
        <a href="{{ route('verifications.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('verification.show.back_to_queue') }}</a>
    </header>

    <nav class="document-breadcrumb" aria-label="{{ __('verification.show.breadcrumb_label') }}">
        <a href="{{ route('verifications.index', ['proyek' => $project->id]) }}" wire:navigate>{{ __('verification.show.document_verification') }}</a>
        <span aria-hidden="true">&rsaquo;</span><span>{{ $project->code }}</span>
        <span aria-hidden="true">&rsaquo;</span><span>{{ $criterion->code }}.{{ $item->number }}</span>
        <span aria-hidden="true">&rsaquo;</span><strong>{{ __('verification.show.requirement', ['number' => $requirement->number]) }}</strong>
    </nav>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    @if ($project->status !== 'active')
        <div class="workflow-lock-notice">
            <strong>{{ __('verification.show.read_only_title') }}</strong>
            <span>{{ __('verification.show.read_only_body', ['status' => $projectStatusLabel]) }}</span>
        </div>
    @endif

    <section class="verification-context-card">
        <div><small>{{ __('verification.show.context.project') }}</small><strong>{{ $project->code }}</strong><span>{{ $project->name }}</span></div>
        <div><small>{{ __('verification.show.context.related_item') }}</small><strong>{{ $criterion->code }}.{{ $item->number }}</strong><span>{{ $item->title }}</span></div>
        <div><small>{{ __('verification.show.context.criterion') }}</small><strong>{{ $criterion->code }}</strong><span>{{ $criterion->name }}</span></div>
        <div><small>{{ __('verification.show.context.submitter') }}</small><strong>{{ $submission->submitter->name }}</strong><span>{{ __('verification.labels.revision', ['number' => $submission->revision_number]) }}</span></div>
        <div><small>{{ __('verification.show.context.submitted_at') }}</small><strong>{{ \App\Support\BusinessDateTime::inBusinessTimezone($submission->submitted_at)->locale(app()->getLocale())->translatedFormat('d M Y') }}</strong><span>{{ \App\Support\BusinessDateTime::label($submission->submitted_at, 'H:i') }}</span></div>
        <div><small>{{ __('verification.show.context.snapshot') }}</small><strong>{{ trans_choice('verification.labels.file_count', $submission->documents->count(), ['count' => $submission->documents->count()]) }}</strong><span>{{ __('verification.show.context.immutable') }}</span></div>
    </section>

    <section class="verification-workspace-card">
        <div class="verification-process-grid">
            <div class="verification-left-stack">
                <section class="verification-documents-card">
                    <div class="verification-section-heading">
                        <div><strong>{{ __('verification.show.documents.title') }}</strong><span>{{ __('verification.show.documents.help') }}</span></div>
                        <span class="workflow-badge status-{{ $submission->status }}">{{ $statusLabels[$submission->status] }}</span>
                    </div>

                    <div class="verification-document-list">
                        @forelse ($submission->documents as $document)
                            @php
                                $canOpenOriginalInBrowser = in_array(strtolower($document->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                            @endphp
                            <article class="verification-document-item" wire:key="snapshot-document-{{ $document->id }}">
                                <span class="verification-file-icon" aria-hidden="true">{{ strtoupper($document->extension) }}</span>
                                <div class="verification-document-copy">
                                    <strong>{{ $document->original_name }}</strong>
                                    <span>
                                        {{ strtoupper($document->extension) }}
                                        &bull; {{ $document->size_bytes >= 1048576 ? __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1048576, 2, true)]) : __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1024, 1, true)]) }}
                                        &bull; {{ __('verification.labels.revision', ['number' => $document->revision_number]) }}
                                    </span>
                                    <small>{{ __('verification.show.documents.submission_version', ['version' => $submission->revision_number]) }} &bull; {{ $statusLabels[$submission->status] }}</small>
                                </div>
                                <div class="verification-document-actions">
                                    @if ($canOpenOriginalInBrowser)
                                        <a href="{{ route('verifications.documents.open', [$submission, $document]) }}" target="_blank" rel="noopener noreferrer">{{ __('verification.actions.open_new_tab') }}</a>
                                    @endif
                                    <a href="{{ route('verifications.documents.download', [$submission, $document]) }}">{{ __('verification.actions.download_original') }}</a>
                                </div>
                            </article>
                        @empty
                            <div class="verification-empty"><strong>{{ __('verification.show.documents.empty') }}</strong></div>
                        @endforelse
                    </div>
                </section>

                <section class="verification-history-card">
                    <div class="verification-section-heading"><div><strong>{{ __('verification.show.history.title') }}</strong><span>{{ __('verification.show.history.help') }}</span></div></div>
                    <div class="verification-history-list">
                        @foreach ($history as $historySubmission)
                            @php
                                $historyDecisionEvent = $historySubmission->events->firstWhere('action', $historySubmission->status);
                            @endphp
                            <article class="verification-history-item" wire:key="history-submission-{{ $historySubmission->id }}">
                                <div class="verification-history-summary">
                                    <span class="verification-history-revision">v{{ $historySubmission->revision_number }}</span>
                                    <div>
                                        <strong>{{ $historySubmission->submitter->name }}</strong>
                                        <small>{{ \App\Support\BusinessDateTime::label($historySubmission->submitted_at) }} &bull; {{ trans_choice('verification.labels.file_count', $historySubmission->documents_count, ['count' => $historySubmission->documents_count]) }}</small>
                                    </div>
                                    <span class="workflow-badge status-{{ $historySubmission->status }}">{{ $statusLabels[$historySubmission->status] }}</span>
                                </div>
                                @if ($historySubmission->review_note)
                                    <p>{{ $historySubmission->review_note }}</p>
                                @endif
                                @if ($historyDecisionEvent?->attachments->isNotEmpty())
                                    <div class="verification-stored-attachments">
                                        @foreach ($historyDecisionEvent->attachments as $attachment)
                                            @can('view', $attachment)
                                                @php
                                                    $canOpenAttachmentInBrowser = in_array(strtolower($attachment->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                                                @endphp
                                                <div wire:key="history-attachment-{{ $attachment->id }}">
                                                    <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ $attachment->file_size >= 1048576 ? __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($attachment->file_size / 1048576, 2, true)]) : __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($attachment->file_size / 1024, 1, true)]) }}</small></span>
                                                    <span class="verification-attachment-actions">
                                                        @if ($canOpenAttachmentInBrowser)
                                                            <a href="{{ route('verifications.attachments.open', [$historySubmission, $attachment]) }}" target="_blank" rel="noopener noreferrer">{{ __('verification.actions.open_new_tab') }}</a>
                                                        @endif
                                                        <a href="{{ route('verifications.attachments.download', [$historySubmission, $attachment]) }}">{{ __('verification.actions.download') }}</a>
                                                    </span>
                                                </div>
                                            @endcan
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="verification-result-card">
                <div class="verification-section-heading"><div><strong>{{ __('verification.show.decision.title') }}</strong><span>{{ __('verification.show.decision.help') }}</span></div></div>

                @if ($canDecide)
                    <form wire:submit="confirmDecision">
                        <fieldset>
                            <legend>{{ __('verification.show.decision.document_status') }}</legend>
                            @if ($canVerify)
                                <label class="verification-decision verified {{ $decision === 'verified' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="decision" value="verified">
                                    <span aria-hidden="true">&#10003;</span><strong>{{ __('verification.statuses.verified') }}</strong><small>{{ __('verification.show.decision.verified_help') }}</small>
                                </label>
                            @endif
                            @if ($canRequestRevision)
                                <label class="verification-decision revision {{ $decision === 'need_revision' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="decision" value="need_revision">
                                    <span aria-hidden="true">!</span><strong>{{ __('verification.statuses.need_revision') }}</strong><small>{{ __('verification.show.decision.revision_help') }}</small>
                                </label>
                            @endif
                        </fieldset>

                        <label class="verification-note-field">
                            <span>{{ __('verification.show.decision.review_note') }} {{ $decision === 'need_revision' ? '*' : __('verification.show.decision.optional') }}</span>
                            <textarea wire:model="reviewNote" maxlength="10000" rows="7" placeholder="{{ __('verification.show.decision.note_placeholder') }}"></textarea>
                        </label>
                        @error('decision') <p class="verification-error">{{ $message }}</p> @enderror
                        @error('reviewNote') <p class="verification-error">{{ $message }}</p> @enderror

                        @if ($decision === 'need_revision')
                            <section class="verification-attachment-upload">
                                <div>
                                    <strong>{{ __('verification.show.attachments.title') }}</strong>
                                    <small>{{ __('verification.show.attachments.help') }}</small>
                                </div>
                                <label>
                                    <input type="file" wire:model="correctionAttachments" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.bmp,.tif,.tiff">
                                    <span>{{ __('verification.show.attachments.choose') }}</span>
                                </label>
                                <p wire:loading wire:target="correctionAttachments" class="verification-uploading">{{ __('verification.show.attachments.checking') }}</p>
                                @error('correctionAttachments') <p class="verification-error">{{ $message }}</p> @enderror
                                @error('correctionAttachments.*') <p class="verification-error">{{ $message }}</p> @enderror

                                @if ($correctionAttachments !== [])
                                    <div class="verification-selected-attachments">
                                        @foreach ($correctionAttachments as $index => $attachment)
                                            <div wire:key="pending-correction-{{ $index }}-{{ $attachment->getFilename() }}">
                                                 <span><strong>{{ $attachment->getClientOriginalName() }}</strong><small>{{ __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($attachment->getSize() / 1024, 1, true)]) }}</small></span>
                                                <button type="button" wire:click="removeCorrectionAttachment({{ $index }})" aria-label="{{ __('verification.show.attachments.remove_label', ['name' => $attachment->getClientOriginalName()]) }}">{{ __('verification.actions.remove') }}</button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @endif

                        @if ($confirmingDecision)
                            <div class="verification-confirmation" role="alert">
                                <strong>{{ __('verification.show.confirmation.title') }}</strong>
                                <p>{{ __('verification.show.confirmation.body', ['decision' => $statusLabels[$decision], 'version' => $submission->revision_number]) }}</p>
                                <div>
                                    <button type="button" wire:click="cancelDecisionConfirmation">{{ __('verification.actions.back') }}</button>
                                    <button type="button" wire:click="submitDecision" wire:loading.attr="disabled" wire:target="submitDecision">
                                        <span wire:loading.remove wire:target="submitDecision">{{ __('verification.show.confirmation.save') }}</span>
                                        <span wire:loading wire:target="submitDecision">{{ __('verification.show.confirmation.saving') }}</span>
                                    </button>
                                </div>
                            </div>
                        @else
                            <button class="verification-submit-decision" type="submit" wire:loading.attr="disabled" wire:target="confirmDecision,correctionAttachments">
                                {{ __('verification.show.decision.review_and_confirm') }}
                            </button>
                        @endif
                    </form>
                @else
                    <div class="verification-final-result status-{{ $submission->status }}">
                        <span aria-hidden="true">{!! $submission->status === 'verified' ? '&#10003;' : '!' !!}</span>
                        <strong>{{ $submission->status === 'pending_review' ? __('verification.statuses.monitoring') : ($statusLabels[$submission->status] ?? __('verification.statuses.unknown')) }}</strong>
                        <p>{{ $submission->review_note ?: __('verification.show.final_result.no_note') }}</p>
                        @if ($submission->decider)
                            <small>{{ __('verification.show.final_result.decided_by', ['name' => $submission->decider->name, 'date' => \App\Support\BusinessDateTime::label($submission->decided_at)]) }}</small>
                        @endif
                        @if ($decisionEvent?->attachments->isNotEmpty())
                            <div class="verification-stored-attachments compact">
                                @foreach ($decisionEvent->attachments as $attachment)
                                    @can('view', $attachment)
                                        @php
                                            $canOpenAttachmentInBrowser = in_array(strtolower($attachment->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                                        @endphp
                                        <div wire:key="decision-attachment-{{ $attachment->id }}">
                                            <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($attachment->file_size / 1024, 1, true)]) }}</small></span>
                                            <span class="verification-attachment-actions">
                                                @if ($canOpenAttachmentInBrowser)
                                                    <a href="{{ route('verifications.attachments.open', [$submission, $attachment]) }}" target="_blank" rel="noopener noreferrer">{{ __('verification.actions.open_new_tab') }}</a>
                                                @endif
                                                <a href="{{ route('verifications.attachments.download', [$submission, $attachment]) }}">{{ __('verification.actions.download') }}</a>
                                            </span>
                                        </div>
                                    @endcan
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </aside>
        </div>
    </section>
</div>
