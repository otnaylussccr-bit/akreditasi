<div class="document-upload-page">
    @php
        $statusLabels = [
            'not_started' => 'Belum Mulai',
            'draft' => 'Draft',
            'pending_review' => 'Menunggu Verifikasi',
            'need_revision' => 'Perlu Revisi',
            'verified' => 'Terverifikasi',
            'submit' => 'Dikirim',
            'submitted' => 'Dikirim',
        ];
        $selectedFormats = collect($files)
            ->map(fn ($file) => strtoupper($file->getClientOriginalExtension()))
            ->filter()
            ->unique()
            ->implode(', ');
        $internalDeadline = $requirement->item->target_deadline ?? $requirement->item->source_deadline;
    @endphp

    <header class="document-upload-heading">
        <div>
            <span class="eyebrow dark">Input Dokumen Akreditasi</span>
            <h1>Detail Butir & Upload Dokumen</h1>
            <p>Unggah satu atau lebih dokumen pendukung untuk rincian aktif.</p>
        </div>
        <div class="upload-final-deadline">
            <span class="upload-deadline-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            </span>
            <div>
                <small>Batas Akhir Pengajuan (Final)</small>
                <strong>{{ $requirement->item->criterion->project->final_deadline->format('d M Y') }}</strong>
            </div>
        </div>
    </header>

    <nav class="document-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('follow-up-led.index', ['proyek' => $requirement->item->criterion->project_id, 'kriteria' => $requirement->item->criterion_id]) }}" wire:navigate>Follow Up LED</a>
        <span aria-hidden="true">&rsaquo;</span>
        <span>Kriteria {{ $requirement->item->criterion->code }}</span>
        <span aria-hidden="true">&rsaquo;</span>
        <span>{{ $requirement->item->criterion->code }}.{{ $requirement->item->number }} {{ $requirement->item->title }}</span>
        <span aria-hidden="true">&rsaquo;</span>
        <strong>Rincian {{ $requirement->number }}</strong>
    </nav>

    <div class="upload-overview-grid">
        <section class="upload-item-card" style="--criterion-header: {{ $requirement->item->criterion->color_header }}; --criterion-body: {{ $requirement->item->criterion->color_body }}">
            <div class="upload-item-code">
                <span class="upload-item-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg>
                </span>
                <div>
                    <small>Kode Butir</small>
                    <strong>{{ $requirement->item->criterion->code }}.{{ $requirement->item->number }}</strong>
                    <span>Kriteria {{ $requirement->item->criterion->code }}</span>
                    <p>{{ $requirement->item->criterion->name }}</p>
                </div>
            </div>
            <div class="upload-item-title">
                <small>Judul Butir</small>
                <h2>{{ $requirement->item->title }}</h2>
                <span>Rincian {{ $requirement->number }}</span>
                <p>{{ $requirement->title }}</p>
            </div>
            <div class="upload-item-owner">
                <div>
                    <span class="upload-owner-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 6v2h18v-2c0-3.5-4-6-9-6Z"/></svg>
                    </span>
                    <div><small>PIC Aktif</small><strong>{{ $pic->name }}</strong></div>
                </div>
                <div>
                    <span class="upload-owner-icon deadline" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
                    </span>
                    <div><small>Deadline Internal</small><strong>{{ $internalDeadline?->format('d M Y') ?? 'Belum ditentukan' }}</strong></div>
                </div>
            </div>
        </section>

        <section class="upload-stat-grid" aria-label="Statistik dokumen rincian aktif">
            <article class="upload-stat-card total">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2H6v16h12V8h-4V4Zm-5 7h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg></span>
                <div><small>Total File</small><strong>{{ $documentStats['total'] }}</strong><span>dokumen</span></div>
            </article>
            <article class="upload-stat-card active">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9.2 16.6-4.3-4.3 1.4-1.4 2.9 2.9 8.5-8.5 1.4 1.4-9.9 9.9Z"/></svg></span>
                <div><small>File Aktif</small><strong>{{ $documentStats['active'] }}</strong><span>dokumen</span></div>
            </article>
            <article class="upload-stat-card historical">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4a8 8 0 1 1-7.75 10H2l3-3 3 3H6.28A6 6 0 1 0 7 7.1L5.6 5.65A7.95 7.95 0 0 1 12 4Zm-1 3h2v5.2l3.1 1.8-1 1.73L11 13.35V7Z"/></svg></span>
                <div><small>File Historis</small><strong>{{ $documentStats['historical'] }}</strong><span>dokumen</span></div>
            </article>
            <article class="upload-stat-card workflow status-{{ $requirement->workflow_status }}">
                <span class="upload-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm-1 5v6h6v-2h-4V7h-2Z"/></svg></span>
                <div><small>Status Workflow</small><strong>{{ $statusLabels[$requirement->workflow_status] ?? $requirement->workflow_status }}</strong><span>rincian aktif</span></div>
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
                    <span>Upload Dokumen Pendukung</span>
                    <small>Maksimal 10 file dalam satu batch dan 50 MB per file.</small>
                </div>
                <span class="private-storage-label">Penyimpanan Privat</span>
            </div>

            @if (! $workflowMutable)
                <div class="workflow-lock-notice">
                    <strong>Project dalam mode baca-saja</strong>
                    <span>Project berstatus {{ $requirement->item->criterion->project->status }}. Dokumen dan riwayat tetap dapat dilihat dan diunduh.</span>
                </div>
            @elseif (in_array($requirement->workflow_status, ['pending_review', 'verified'], true))
                <div class="workflow-lock-notice">
                    <strong>Upload dikunci</strong>
                    <span>Rincian berstatus {{ $statusLabels[$requirement->workflow_status] ?? $requirement->workflow_status }}. File tersimpan tetap dapat dilihat dan diunduh.</span>
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
                            <small>Nama Dokumen</small>
                            <strong>{{ count($files) === 1 ? $files[0]->getClientOriginalName() : (count($files) > 1 ? count($files).' file dipilih' : 'Mengikuti nama file yang dipilih') }}</strong>
                        </div>
                        <div>
                            <small>Format File</small>
                            <strong>{{ $selectedFormats !== '' ? $selectedFormats : 'Terdeteksi otomatis' }}</strong>
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
                        <strong>Seret dan lepas file di sini</strong>
                        <span>atau klik untuk memilih beberapa file</span>
                        <small>PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, BMP, TIFF, TIF</small>
                    </label>

                    <div x-show="temporaryUploading" style="display: none" class="upload-progress-note">Mentransfer dan memeriksa file yang dipilih...</div>

                    @error('files') <p class="upload-error">{{ $message }}</p> @enderror
                    @foreach ($errors->get('files.*') as $messages)
                        @foreach ($messages as $message)
                            <p class="upload-error">{{ $message }}</p>
                        @endforeach
                    @endforeach

                    @if ($files !== [])
                        <div class="pending-files">
                            <div class="pending-files-heading">
                                <strong>File Siap Diunggah</strong>
                                <span>{{ count($files) }} / 10 file</span>
                            </div>
                            @foreach ($files as $index => $file)
                                <div class="pending-file" wire:key="pending-file-{{ $index }}-{{ $file->getFilename() }}">
                                    <span class="file-extension">{{ strtoupper($file->getClientOriginalExtension()) }}</span>
                                    <div>
                                        <strong>{{ $file->getClientOriginalName() }}</strong>
                                        <small>{{ number_format($file->getSize() / 1024, 1) }} KB &middot; {{ strtoupper($file->getClientOriginalExtension()) }}</small>
                                    </div>
                                    <button type="button" wire:click="removeFile({{ $index }})" aria-label="Hapus {{ $file->getClientOriginalName() }} dari daftar">&times;</button>
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
                        <span wire:loading.remove wire:target="saveDocuments">Upload {{ count($files) ?: '' }} Dokumen</span>
                        <span wire:loading wire:target="saveDocuments">Menyimpan file dan metadata...</span>
                    </button>

                    @if ($requiresRevisionUpload && Gate::allows('submit', $requirement->item->criterion))
                        <div class="submission-revision-notice">
                            <strong>Upload revisi diperlukan</strong>
                            <span>Unggah minimal satu file revisi baru sebelum paket bukti dapat dikirim ulang.</span>
                        </div>
                    @endif

                    @if ($canSubmitForReview)
                        <div class="submission-action-panel">
                            <div>
                                <strong>Kirim paket bukti aktif</strong>
                                <span>Seluruh file aktif akan dibekukan sebagai snapshot revisi berikutnya.</span>
                            </div>
                            @if (! $hasActiveReviewer)
                                <p>Belum ada Reviewer aktif dan independen pada kriteria ini. Hubungi pengelola assignment sebelum submit.</p>
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
                                <span wire:loading.remove wire:target="submitForReview">Kirim ke Verifikator</span>
                                <span wire:loading wire:target="submitForReview">Mengunci snapshot...</span>
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
                    <h2>Panduan Upload Dokumen</h2>
                </div>
                <ol>
                    <li><span>1</span><p>Pilih file yang relevan dengan rincian aktif.</p></li>
                    <li><span>2</span><p>Gunakan nama file yang jelas dan mudah dipahami.</p></li>
                    <li><span>3</span><p>Pastikan dokumen final dan dapat dibuka dengan baik.</p></li>
                    <li><span>4</span><p>Format dan konten file akan diperiksa pada server.</p></li>
                    <li><span>5</span><p>File lama tidak ditimpa dan tetap tersedia dalam riwayat.</p></li>
                </ol>
                <div class="upload-guidance-note">Upload menyimpan dokumen sebagai draft. Setelah paket bukti siap, kirim seluruh file aktif ke verifikator sebagai satu snapshot.</div>
            </aside>

            <aside class="workflow-legend-card">
                <div class="side-card-heading">
                    <span aria-hidden="true">&#10003;</span>
                    <div><h2>Legenda Status Rincian</h2><small>Status workflow pada requirement aktif.</small></div>
                </div>
                <div class="workflow-legend-grid">
                    <div><span class="workflow-dot status-draft">&bull;&bull;&bull;</span><strong>Draft</strong><small>Dokumen tersimpan dan masih dapat ditambah.</small></div>
                    <div><span class="workflow-dot status-pending_review">&#9687;</span><strong>Menunggu Verifikasi</strong><small>Upload dikunci selama proses review.</small></div>
                    <div><span class="workflow-dot status-verified">&#10003;</span><strong>Terverifikasi</strong><small>Rincian telah selesai diverifikasi.</small></div>
                    <div><span class="workflow-dot status-need_revision">!</span><strong>Perlu Revisi</strong><small>PIC dapat menambah file revisi baru.</small></div>
                </div>
            </aside>
        </div>
    </div>

    <div class="upload-lower-grid">
        <section class="document-history-card">
            <div class="upload-card-heading">
                <div>
                    <span>Riwayat Dokumen</span>
                    <small>Seluruh file pada rincian ini tanpa mengubah file historis.</small>
                </div>
                <span>{{ $documents->count() }} file</span>
            </div>
            <div class="document-history-scroll">
                <table class="document-history-table">
                    <thead>
                        <tr>
                            <th>Nama File</th>
                            <th>Format</th>
                            <th>Ukuran</th>
                            <th>Uploader</th>
                            <th>Tanggal Upload</th>
                            <th>Revisi</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $document)
                            <tr wire:key="stored-document-{{ $document->id }}">
                                <td><strong>{{ $document->original_name }}</strong></td>
                                <td><span class="file-extension">{{ strtoupper($document->extension) }}</span></td>
                                <td>{{ $document->size_bytes >= 1048576 ? number_format($document->size_bytes / 1048576, 2).' MB' : number_format($document->size_bytes / 1024, 1).' KB' }}</td>
                                <td>{{ $document->uploader->name }}</td>
                                <td>{{ \App\Support\BusinessDateTime::label($document->uploaded_at) }}</td>
                                <td>v{{ $document->revision_number }}</td>
                                <td><span class="document-active-state {{ $document->is_active ? 'active' : 'historical' }}">{{ $document->is_active ? 'Aktif' : 'Historis' }}</span></td>
                                <td>
                                    <div class="document-actions">
                                        <a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener">Lihat</a>
                                        <a href="{{ route('documents.download', $document) }}">Unduh</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="document-history-empty">Belum ada file yang tersimpan untuk rincian ini.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="review-notes-card">
            <div class="upload-card-heading">
                <div>
                    <span>Catatan Verifikator</span>
                    <small>Catatan review pada rincian aktif.</small>
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
                            <span class="workflow-badge status-{{ $reviewNote['status'] }}">{{ $statusLabels[$reviewNote['status']] ?? $reviewNote['status'] }}</span>
                            <p>{{ $reviewNote['note'] }}</p>
                            @if ($reviewNote['attachments']->isNotEmpty())
                                <div class="review-note-attachments">
                                    @foreach ($reviewNote['attachments'] as $attachment)
                                        @php
                                            $canOpenAttachmentInBrowser = in_array(strtolower($attachment->extension), ['pdf', 'jpg', 'jpeg', 'png'], true);
                                        @endphp
                                        <div wire:key="pic-correction-attachment-{{ $attachment->id }}">
                                            <span><strong>{{ $attachment->original_filename }}</strong><small>{{ strtoupper($attachment->extension) }} &bull; {{ $attachment->file_size >= 1048576 ? number_format($attachment->file_size / 1048576, 2).' MB' : number_format($attachment->file_size / 1024, 1).' KB' }}</small></span>
                                            <span>
                                                @if ($canOpenAttachmentInBrowser)
                                                    <a href="{{ route('verifications.attachments.open', [$reviewNote['submission_id'], $attachment]) }}" target="_blank" rel="noopener noreferrer">Buka Tab Baru</a>
                                                @endif
                                                <a href="{{ route('verifications.attachments.download', [$reviewNote['submission_id'], $attachment]) }}">Unduh</a>
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
                        <strong>Belum ada catatan verifikator</strong>
                        <p>Catatan akan tampil jika review note tersedia untuk rincian ini.</p>
                    </div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
