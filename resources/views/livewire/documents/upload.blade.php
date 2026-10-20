<div class="document-upload-page">
    @php
        $statusLabels = __('documents.status');
        $selectedFormats = collect($files)
            ->map(fn ($file) => strtoupper($file->getClientOriginalExtension()))
            ->filter()
            ->unique()
            ->implode(', ');
        $internalDeadline = $requirement->item->target_deadline ?? $requirement->item->source_deadline;
    @endphp

    <header class="document-upload-heading">
        <div>
            <span class="eyebrow dark">{{ __('documents.upload.eyebrow') }}</span>
            <h1>{{ __('documents.upload.heading') }}</h1>
            <p>{{ __('documents.upload.introduction') }}</p>
        </div>
        <div class="upload-final-deadline">
            <span class="upload-deadline-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            </span>
            <div>
                <small>{{ __('documents.upload.final_submission_deadline') }}</small>
                <strong>{{ $requirement->item->criterion->project->final_deadline->translatedFormat('d M Y') }}</strong>
            </div>
        </div>
    </header>

    <nav class="document-breadcrumb" aria-label="{{ __('documents.upload.breadcrumb_aria') }}">
        <a href="{{ route('follow-up-led.index', ['proyek' => $requirement->item->criterion->project_id, 'kriteria' => $requirement->item->criterion_id]) }}" wire:navigate>{{ __('documents.upload.follow_up_led') }}</a>
        <span aria-hidden="true">&rsaquo;</span>
        <span>{{ __('documents.upload.criterion_with_code', ['code' => $requirement->item->criterion->code]) }}</span>
        <span aria-hidden="true">&rsaquo;</span>
        <span>{{ $requirement->item->criterion->code }}.{{ $requirement->item->number }} {{ $requirement->item->title }}</span>
        <span aria-hidden="true">&rsaquo;</span>
        <strong>{{ __('documents.upload.requirement_with_number', ['number' => $requirement->number]) }}</strong>
    </nav>

    <div class="upload-overview-grid">
        <section class="upload-item-card" style="--criterion-header: {{ $requirement->item->criterion->color_header }}; --criterion-body: {{ $requirement->item->criterion->color_body }}">
            <div class="upload-item-code">
                <span class="upload-item-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg>
                </span>
                <div>
                    <small>{{ __('documents.upload.item_code') }}</small>
                    <strong>{{ $requirement->item->criterion->code }}.{{ $requirement->item->number }}</strong>
                    <span>{{ __('documents.upload.criterion_with_code', ['code' => $requirement->item->criterion->code]) }}</span>
                    <p>{{ $requirement->item->criterion->name }}</p>
                </div>
            </div>
            <div class="upload-item-title">
                <small>{{ __('documents.upload.item_title') }}</small>
                <h2>{{ $requirement->item->title }}</h2>
                <span>{{ __('documents.upload.requirement_with_number', ['number' => $requirement->number]) }}</span>
                <p>{{ $requirement->title }}</p>
            </div>
            <div class="upload-item-owner">
                <div>
                    <span class="upload-owner-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 6v2h18v-2c0-3.5-4-6-9-6Z"/></svg>
                    </span>
                    <div><small>{{ __('documents.upload.active_pic') }}</small><strong>{{ $pic->name }}</strong></div>
                </div>
                <div>
                    <span class="upload-owner-icon deadline" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
                    </span>
                    <div><small>{{ __('documents.upload.internal_deadline') }}</small><strong>{{ $internalDeadline?->translatedFormat('d M Y') ?? __('documents.upload.not_specified') }}</strong></div>
                </div>
            </div>
        </section>

        <section class="upload-stat-grid" aria-label="{{ __('documents.upload.statistics_aria') }}">
            <article class="upload-stat-card total">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg></span>
                <div><small>{{ __('documents.upload.stats.total_files') }}</small><strong>{{ $documentStats['total'] }}</strong><span>{{ trans_choice('documents.upload.stats.documents', $documentStats['total']) }}</span></div>
            </article>
            <article class="upload-stat-card active">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9.2 16.6-4.3-4.3 1.4-1.4 2.9 2.9 8.5-8.5 1.4 1.4-9.9 9.9Z"/></svg></span>
                <div><small>{{ __('documents.upload.stats.active_files') }}</small><strong>{{ $documentStats['active'] }}</strong><span>{{ trans_choice('documents.upload.stats.documents', $documentStats['active']) }}</span></div>
            </article>
            <article class="upload-stat-card historical">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4a8 8 0 1 1-7.75 10H2l3-3 3 3H6.28A6 6 0 1 0 7 7.1L5.6 5.65A7.95 7.95 0 0 1 12 4Zm-1 3h2v5.2l3.1 1.8-1 1.73L11 13.35V7Z"/></svg></span>
                <div><small>{{ __('documents.upload.stats.historical_files') }}</small><strong>{{ $documentStats['historical'] }}</strong><span>{{ trans_choice('documents.upload.stats.documents', $documentStats['historical']) }}</span></div>
            </article>
            <article class="upload-stat-card workflow status-{{ $requirement->workflow_status }}">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm-1 5v6h6v-2h-4V7h-2Z"/></svg></span>
                <div><small>{{ __('documents.upload.stats.workflow_status') }}</small><strong>{{ $statusLabels[$requirement->workflow_status] ?? __('documents.status.unknown') }}</strong><span>{{ __('documents.upload.stats.active_requirement') }}</span></div>
            </article>
        </section>
    </div>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    <div class="document-upload-grid">
        <section class="upload-card">
            <div class="upload-card-heading">
                <div>
                    <span>{{ __('documents.upload.form.heading') }}</span>
                    <small>{{ __('documents.upload.form.limits') }}</small>
                </div>
                <span class="private-storage-label">{{ __('documents.upload.form.private_storage') }}</span>
            </div>

            @if (! $workflowMutable)
                <div class="workflow-lock-notice">
                    <strong>{{ __('documents.upload.locks.project_read_only') }}</strong>
                    <span>{{ __('documents.upload.locks.project_status', ['status' => $statusLabels[$requirement->item->criterion->project->status] ?? __('documents.status.unknown')]) }}</span>
                </div>
            @elseif (in_array($requirement->workflow_status, ['pending_review', 'verified'], true))
                <div class="workflow-lock-notice">
                    <strong>{{ __('documents.upload.locks.upload_locked') }}</strong>
                    <span>{{ __('documents.upload.locks.requirement_status', ['status' => $statusLabels[$requirement->workflow_status] ?? __('documents.status.unknown')]) }}</span>
                </div>
            @else
                <form
                    wire:submit="saveDocuments"
                    x-data="{ temporaryUploading: false }"
                    x-on:livewire-upload-start="temporaryUploading = true"
                    x-on:livewire-upload-finish="temporaryUploading = false"
                    x-on:livewire-upload-error="temporaryUploading = false"
                    x-on:livewire-upload-cancel="temporaryUploading = false"
                >
                    <div class="selected-file-summary">
                        <div>
                            <small>{{ __('documents.upload.form.document_name') }}</small>
                            <strong>{{ count($files) === 1 ? $files[0]->getClientOriginalName() : (count($files) > 1 ? trans_choice('documents.upload.form.files_selected', count($files), ['count' => count($files)]) : __('documents.upload.form.selected_filename')) }}</strong>
                        </div>
                        <div>
                            <small>{{ __('documents.upload.form.file_format') }}</small>
                            <strong>{{ $selectedFormats !== '' ? $selectedFormats : __('documents.upload.form.detected_automatically') }}</strong>
                        </div>
                    </div>

                    <label class="document-drop-zone" for="accreditation-files">
                        <input
                            id="accreditation-files"
                            type="file"
                            multiple
                            wire:model="files"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpeg,.jpg,.png,.bmp,.tiff,.tif"
                            x-bind:disabled="temporaryUploading"
                            wire:loading.attr="disabled"
                            wire:target="saveDocuments"
                        >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 16V8.83l-2.59 2.58L7 10l5-5 5 5-1.41 1.41L13 8.83V16h-2Zm-5 4a4 4 0 0 1-.69-7.94A7 7 0 0 1 18.9 10.1 5 5 0 0 1 19 20H6Zm0-2h13a3 3 0 1 0-1.22-5.74l-1.25.56-.16-1.36A5 5 0 0 0 6.42 14H6a2 2 0 1 0 0 4Z"/></svg>
                        <strong>{{ __('documents.upload.form.drop_files') }}</strong>
                        <span>{{ __('documents.upload.form.click_to_select') }}</span>
                        <small>{{ __('documents.upload.form.accepted_formats') }}</small>
                    </label>

                    <div x-show="temporaryUploading" style="display: none" class="upload-progress-note">{{ __('documents.upload.form.processing') }}</div>

                    @error('files') <p class="upload-error">{{ $message }}</p> @enderror
                    @foreach ($errors->get('files.*') as $messages)
                        @foreach ($messages as $message)
                            <p class="upload-error">{{ $message }}</p>
                        @endforeach
                    @endforeach

                    @if ($files !== [])
                        <div class="pending-files">
                            <div class="pending-files-heading">
                                <strong>{{ __('documents.upload.form.files_ready') }}</strong>
                                <span>{{ trans_choice('documents.upload.form.file_limit_count', count($files), ['count' => count($files), 'max' => 10]) }}</span>
                            </div>
                            @foreach ($files as $index => $file)
                                <div class="pending-file" wire:key="pending-file-{{ $index }}-{{ $file->getFilename() }}">
                                    <span class="file-extension">{{ strtoupper($file->getClientOriginalExtension()) }}</span>
                                    <div>
                                        <strong>{{ $file->getClientOriginalName() }}</strong>
                                        <small>{{ __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($file->getSize() / 1024, 1, true)]) }} &middot; {{ strtoupper($file->getClientOriginalExtension()) }}</small>
                                    </div>
                                    <button type="button" wire:click="removeFile({{ $index }})" aria-label="{{ __('documents.upload.form.remove_file_aria', ['name' => $file->getClientOriginalName()]) }}">&times;</button>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <button
                        class="upload-submit-button"
                        type="submit"
                        @disabled($files === [] || $errors->has('files') || $errors->has('files.*'))
                        x-bind:disabled="temporaryUploading || @js($files === [] || $errors->has('files') || $errors->has('files.*'))"
                        wire:loading.attr="disabled"
                        wire:target="saveDocuments"
                    >
                        <span wire:loading.remove wire:target="saveDocuments">{{ count($files) ? trans_choice('documents.upload.actions.upload_documents_count', count($files), ['count' => count($files)]) : __('documents.upload.actions.upload_documents') }}</span>
                        <span wire:loading wire:target="saveDocuments">{{ __('documents.upload.actions.saving') }}</span>
                    </button>

                    @if ($requiresRevisionUpload && Gate::allows('submit', $requirement->item->criterion))
                        <div class="submission-revision-notice">
                            <strong>{{ __('documents.upload.revision.required') }}</strong>
                            <span>{{ __('documents.upload.revision.instructions') }}</span>
                        </div>
                    @endif

                    @if ($canSubmitForReview)
                        <div class="submission-action-panel">
                            <div>
                                <strong>{{ __('documents.upload.submission.heading') }}</strong>
                                <span>{{ __('documents.upload.submission.description') }}</span>
                            </div>
                            @if (! $hasActiveReviewer)
                                <p>{{ __('documents.upload.submission.no_reviewer') }}</p>
                            @endif
                            @error('submission') <p>{{ $message }}</p> @enderror
                            <button
                                type="button"
                                wire:click="submitForReview"
                                wire:loading.attr="disabled"
                                wire:target="submitForReview"
                                x-bind:disabled="temporaryUploading || @js($files !== [] || ! $hasActiveReviewer)"
                                @disabled($files !== [] || ! $hasActiveReviewer)
                            >
                                <span wire:loading.remove wire:target="submitForReview">{{ __('documents.upload.actions.submit_to_verifier') }}</span>
                                <span wire:loading wire:target="submitForReview">{{ __('documents.upload.actions.locking_snapshot') }}</span>
                            </button>
                        </div>
                    @endif
                </form>
            @endif
        </section>

        <div class="upload-side-stack">
            <aside class="upload-guidance-card">
                <div class="side-card-heading">
                    <span aria-hidden="true">i</span>
                    <h2>{{ __('documents.upload.guidance.heading') }}</h2>
                </div>
                <ol>
                    <li><span>1</span><p>{{ __('documents.upload.guidance.relevant') }}</p></li>
                    <li><span>2</span><p>{{ __('documents.upload.guidance.clear_name') }}</p></li>
                    <li><span>3</span><p>{{ __('documents.upload.guidance.final_document') }}</p></li>
                    <li><span>4</span><p>{{ __('documents.upload.guidance.server_check') }}</p></li>
                    <li><span>5</span><p>{{ __('documents.upload.guidance.history_preserved') }}</p></li>
                </ol>
                <div class="upload-guidance-note">{{ __('documents.upload.guidance.note') }}</div>
            </aside>

            <aside class="workflow-legend-card">
                <div class="side-card-heading">
                    <span aria-hidden="true">&#10003;</span>
                    <div><h2>{{ __('documents.upload.legend.heading') }}</h2><small>{{ __('documents.upload.legend.description') }}</small></div>
                </div>
                <div class="workflow-legend-grid">
                    <div><span class="workflow-dot status-draft">&bull;&bull;&bull;</span><strong>{{ __('documents.status.draft') }}</strong><small>{{ __('documents.upload.legend.draft') }}</small></div>
                    <div><span class="workflow-dot status-pending_review">&#9687;</span><strong>{{ __('documents.status.pending_review') }}</strong><small>{{ __('documents.upload.legend.pending_review') }}</small></div>
                    <div><span class="workflow-dot status-verified">&#10003;</span><strong>{{ __('documents.status.verified') }}</strong><small>{{ __('documents.upload.legend.verified') }}</small></div>
                    <div><span class="workflow-dot status-need_revision">!</span><strong>{{ __('documents.status.need_revision') }}</strong><small>{{ __('documents.upload.legend.need_revision') }}</small></div>
                </div>
            </aside>
        </div>
    </div>

    <div class="upload-lower-grid">
        <section class="document-history-card">
            <div class="upload-card-heading">
                <div>
                    <span>{{ __('documents.upload.history.heading') }}</span>
                    <small>{{ __('documents.upload.history.description') }}</small>
                </div>
                <span>{{ trans_choice('documents.upload.history.file_count', $documents->count(), ['count' => $documents->count()]) }}</span>
            </div>
            <div class="document-history-scroll">
                <table class="document-history-table">
                    <thead>
                        <tr>
                            <th>{{ __('documents.upload.history.file_name') }}</th>
                            <th>{{ __('documents.upload.history.format') }}</th>
                            <th>{{ __('documents.upload.history.size') }}</th>
                            <th>{{ __('documents.upload.history.uploader') }}</th>
                            <th>{{ __('documents.upload.history.upload_date') }}</th>
                            <th>{{ __('documents.upload.history.revision') }}</th>
                            <th>{{ __('documents.upload.history.status') }}</th>
                            <th>{{ __('documents.upload.history.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $document)
                            <tr wire:key="stored-document-{{ $document->id }}">
                                <td><strong>{{ $document->original_name }}</strong></td>
                                <td><span class="file-extension">{{ strtoupper($document->extension) }}</span></td>
                                <td>{{ $document->size_bytes >= 1048576 ? __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1048576, 2, true)]) : __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1024, 1, true)]) }}</td>
                                <td>{{ $document->uploader->name }}</td>
                                <td>{{ \App\Support\BusinessDateTime::label($document->uploaded_at) }}</td>
                                <td>{{ __('documents.upload.history.revision_value', ['revision' => $document->revision_number]) }}</td>
                                <td><span class="document-active-state {{ $document->is_active ? 'active' : 'historical' }}">{{ $document->is_active ? __('documents.status.active') : __('documents.status.historical') }}</span></td>
                                <td>
                                    <div class="document-actions">
                                        <a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener">{{ __('documents.actions.view') }}</a>
                                        <a href="{{ route('documents.download', $document) }}">{{ __('documents.actions.download') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="document-history-empty">{{ __('documents.upload.history.empty') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="review-notes-card">
            <div class="upload-card-heading">
                <div>
                    <span>{{ __('documents.upload.notes.heading') }}</span>
                    <small>{{ __('documents.upload.notes.description') }}</small>
                </div>
            </div>
            <div class="review-notes-body">
                @forelse ($reviewNotes as $reviewNote)
                    <article class="review-note" wire:key="review-note-{{ $reviewNote['key'] }}">
                        <div class="review-note-avatar">{{ strtoupper(mb_substr($reviewNote['actor_name'], 0, 1)) }}</div>
                        <div>
                            <div class="review-note-meta">
                                <strong>{{ $reviewNote['actor_name'] }}</strong>
                                <time>{{ \App\Support\BusinessDateTime::label($reviewNote['created_at']) }}</time>
                            </div>
                            <span class="workflow-badge status-{{ $reviewNote['status'] }}">{{ $statusLabels[$reviewNote['status']] ?? __('documents.status.unknown') }}</span>
                            <p>{{ $reviewNote['note'] }}</p>
                            @if ($reviewNote['attachments']->isNotEmpty())
                                <div class="review-note-attachments">
                                    @foreach ($reviewNote['attachments'] as $attachment)
                                        @php
                                            $canOpenAttachmentInBrowser = in_array(strtolower($attachment->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                                        @endphp
                                        <div wire:key="pic-correction-attachment-{{ $attachment->id }}">
                                            <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ $attachment->file_size >= 1048576 ? __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($attachment->file_size / 1048576, 2, true)]) : __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($attachment->file_size / 1024, 1, true)]) }}</small></span>
                                            <span>
                                                @if ($canOpenAttachmentInBrowser)
                                                    <a href="{{ route('verifications.attachments.open', [$reviewNote['submission_id'], $attachment]) }}" target="_blank" rel="noopener noreferrer">{{ __('documents.actions.open_new_tab') }}</a>
                                                @endif
                                                <a href="{{ route('verifications.attachments.download', [$reviewNote['submission_id'], $attachment]) }}">{{ __('documents.actions.download') }}</a>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="review-notes-empty">
                        <span aria-hidden="true">&bull;&bull;&bull;</span>
                        <strong>{{ __('documents.upload.notes.empty_title') }}</strong>
                        <p>{{ __('documents.upload.notes.empty_body') }}</p>
                    </div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
