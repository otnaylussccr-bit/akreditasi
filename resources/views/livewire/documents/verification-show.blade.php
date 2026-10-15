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
            <span class="eyebrow dark">Proses Verifikasi Dokumen</span>
            <h1>{{ $requirement->title }}</h1>
        </div>
        <a href="{{ route('verifications.index', ['proyek' => $project->id]) }}" wire:navigate>Kembali ke Antrian</a>
    </header>

    <nav class="document-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('verifications.index', ['proyek' => $project->id]) }}" wire:navigate>Verifikasi Dokumen</a>
        <span aria-hidden="true">&rsaquo;</span><span>{{ $project->code }}</span>
        <span aria-hidden="true">&rsaquo;</span><span>{{ $criterion->code }}.{{ $item->number }}</span>
        <span aria-hidden="true">&rsaquo;</span><strong>Rincian {{ $requirement->number }}</strong>
    </nav>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    @if ($project->status !== 'active')
        <div class="workflow-lock-notice">
            <strong>Project dalam mode baca-saja</strong>
            <span>Project berstatus {{ $project->status }}. Snapshot, keputusan, dan lampiran historis tetap dapat dilihat atau diunduh.</span>
        </div>
    @endif

    <section class="verification-context-card">
        <div><small>Project</small><strong>{{ $project->code }}</strong><span>{{ $project->name }}</span></div>
        <div><small>Butir Terkait</small><strong>{{ $criterion->code }}.{{ $item->number }}</strong><span>{{ $item->title }}</span></div>
        <div><small>Kriteria</small><strong>{{ $criterion->code }}</strong><span>{{ $criterion->name }}</span></div>
        <div><small>PIC / Submitter</small><strong>{{ $submission->submitter->name }}</strong><span>Revisi {{ $submission->revision_number }}</span></div>
        <div><small>Tanggal Submit</small><strong>{{ $submission->submitted_at->format('d M Y') }}</strong><span>{{ $submission->submitted_at->format('H:i') }}</span></div>
        <div><small>Snapshot</small><strong>{{ $submission->documents->count() }} file</strong><span>Tidak berubah setelah submit</span></div>
    </section>

    <section class="verification-workspace-card">
        <div class="verification-process-grid">
            <div class="verification-left-stack">
                <section class="verification-documents-card">
                    <div class="verification-section-heading">
                        <div><strong>Dokumen Original PIC</strong><span>File snapshot hanya dibuka melalui endpoint privat terotorisasi.</span></div>
                        <span class="workflow-badge status-{{ $submission->status }}">{{ str($submission->status)->replace('_', ' ')->title() }}</span>
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
                                        &bull; {{ $document->size_bytes >= 1048576 ? number_format($document->size_bytes / 1048576, 2).' MB' : number_format($document->size_bytes / 1024, 1).' KB' }}
                                        &bull; Revisi {{ $document->revision_number }}
                                    </span>
                                    <small>Submission v{{ $submission->revision_number }} &bull; {{ str($submission->status)->replace('_', ' ')->title() }}</small>
                                </div>
                                <div class="verification-document-actions">
                                    @if ($canOpenOriginalInBrowser)
                                        <a href="{{ route('verifications.documents.open', [$submission, $document]) }}" target="_blank" rel="noopener noreferrer">Buka Tab Baru</a>
                                    @endif
                                    <a href="{{ route('verifications.documents.download', [$submission, $document]) }}">Download Original</a>
                                </div>
                            </article>
                        @empty
                            <div class="verification-empty"><strong>Snapshot tidak memiliki file.</strong></div>
                        @endforelse
                    </div>
                </section>

                <section class="verification-history-card">
                    <div class="verification-section-heading"><div><strong>Riwayat Versi dan Feedback</strong><span>Setiap versi dan lampiran keputusan tetap terpisah secara historis.</span></div></div>
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
                                        <small>{{ $historySubmission->submitted_at->format('d M Y, H:i') }} &bull; {{ $historySubmission->documents_count }} file</small>
                                    </div>
                                    <span class="workflow-badge status-{{ $historySubmission->status }}">{{ str($historySubmission->status)->replace('_', ' ')->title() }}</span>
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
                                                    <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ $attachment->file_size >= 1048576 ? number_format($attachment->file_size / 1048576, 2).' MB' : number_format($attachment->file_size / 1024, 1).' KB' }}</small></span>
                                                    <span class="verification-attachment-actions">
                                                        @if ($canOpenAttachmentInBrowser)
                                                            <a href="{{ route('verifications.attachments.open', [$historySubmission, $attachment]) }}" target="_blank" rel="noopener noreferrer">Buka Tab Baru</a>
                                                        @endif
                                                        <a href="{{ route('verifications.attachments.download', [$historySubmission, $attachment]) }}">Unduh</a>
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
                <div class="verification-section-heading"><div><strong>Keputusan Verifikator</strong><span>Satu keputusan untuk seluruh snapshot submission.</span></div></div>

                @if ($canDecide)
                    <form wire:submit="confirmDecision">
                        <fieldset>
                            <legend>Status Dokumen</legend>
                            @if ($canVerify)
                                <label class="verification-decision verified {{ $decision === 'verified' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="decision" value="verified">
                                    <span>&#10003;</span><strong>Terverifikasi</strong><small>Seluruh paket bukti dinilai sesuai.</small>
                                </label>
                            @endif
                            @if ($canRequestRevision)
                                <label class="verification-decision revision {{ $decision === 'need_revision' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="decision" value="need_revision">
                                    <span>!</span><strong>Perlu Revisi</strong><small>Berikan instruksi perbaikan yang dapat ditindaklanjuti.</small>
                                </label>
                            @endif
                        </fieldset>

                        <label class="verification-note-field">
                            <span>Catatan Revisi {{ $decision === 'need_revision' ? '*' : '(opsional)' }}</span>
                            <textarea wire:model="reviewNote" maxlength="10000" rows="7" placeholder="Tuliskan temuan dan instruksi perbaikan secara spesifik..."></textarea>
                        </label>
                        @error('decision') <p class="verification-error">{{ $message }}</p> @enderror
                        @error('reviewNote') <p class="verification-error">{{ $message }}</p> @enderror

                        @if ($decision === 'need_revision')
                            <section class="verification-attachment-upload">
                                <div>
                                    <strong>Lampiran Koreksi</strong>
                                    <small>Opsional, maksimal 10 file dan 50 MB per file. File disimpan privat.</small>
                                </div>
                                <label>
                                    <input type="file" wire:model="correctionAttachments" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.bmp,.tif,.tiff">
                                    <span>Pilih Lampiran</span>
                                </label>
                                <p wire:loading wire:target="correctionAttachments" class="verification-uploading">Memeriksa lampiran...</p>
                                @error('correctionAttachments') <p class="verification-error">{{ $message }}</p> @enderror
                                @error('correctionAttachments.*') <p class="verification-error">{{ $message }}</p> @enderror

                                @if ($correctionAttachments !== [])
                                    <div class="verification-selected-attachments">
                                        @foreach ($correctionAttachments as $index => $attachment)
                                            <div wire:key="pending-correction-{{ $index }}-{{ $attachment->getFilename() }}">
                                                <span><strong>{{ $attachment->getClientOriginalName() }}</strong><small>{{ number_format($attachment->getSize() / 1024, 1) }} KB</small></span>
                                                <button type="button" wire:click="removeCorrectionAttachment({{ $index }})">Hapus</button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @endif

                        @if ($confirmingDecision)
                            <div class="verification-confirmation" role="alert">
                                <strong>Konfirmasi keputusan</strong>
                                <p>Keputusan <b>{{ $decision === 'verified' ? 'Terverifikasi' : 'Perlu Revisi' }}</b> akan disimpan permanen untuk submission v{{ $submission->revision_number }}.</p>
                                <div>
                                    <button type="button" wire:click="cancelDecisionConfirmation">Kembali</button>
                                    <button type="button" wire:click="submitDecision" wire:loading.attr="disabled" wire:target="submitDecision">
                                        <span wire:loading.remove wire:target="submitDecision">Ya, Simpan Keputusan</span>
                                        <span wire:loading wire:target="submitDecision">Menyimpan...</span>
                                    </button>
                                </div>
                            </div>
                        @else
                            <button class="verification-submit-decision" type="submit" wire:loading.attr="disabled" wire:target="confirmDecision,correctionAttachments">
                                Tinjau dan Konfirmasi
                            </button>
                        @endif
                    </form>
                @else
                    <div class="verification-final-result status-{{ $submission->status }}">
                        <span>{{ $submission->status === 'verified' ? '&#10003;' : '!' }}</span>
                        <strong>{{ $submission->status === 'verified' ? 'Terverifikasi' : ($submission->status === 'need_revision' ? 'Perlu Revisi' : 'Mode Pemantauan') }}</strong>
                        <p>{{ $submission->review_note ?: 'Tidak ada catatan keputusan.' }}</p>
                        @if ($submission->decider)
                            <small>Diputuskan oleh {{ $submission->decider->name }} pada {{ $submission->decided_at->format('d M Y, H:i') }}</small>
                        @endif
                        @if ($decisionEvent?->attachments->isNotEmpty())
                            <div class="verification-stored-attachments compact">
                                @foreach ($decisionEvent->attachments as $attachment)
                                    @can('view', $attachment)
                                        @php
                                            $canOpenAttachmentInBrowser = in_array(strtolower($attachment->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                                        @endphp
                                        <div wire:key="decision-attachment-{{ $attachment->id }}">
                                            <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ number_format($attachment->file_size / 1024, 1) }} KB</small></span>
                                            <span class="verification-attachment-actions">
                                                @if ($canOpenAttachmentInBrowser)
                                                    <a href="{{ route('verifications.attachments.open', [$submission, $attachment]) }}" target="_blank" rel="noopener noreferrer">Buka Tab Baru</a>
                                                @endif
                                                <a href="{{ route('verifications.attachments.download', [$submission, $attachment]) }}">Unduh</a>
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
