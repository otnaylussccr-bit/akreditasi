<div class="verification-index-page">
    <header class="verification-index-heading">
        <div>
            <span class="eyebrow dark">Antrian Review Rincian</span>
            <h1>Verifikasi Dokumen</h1>
            <p>Periksa satu paket snapshot dokumen dan berikan satu keputusan untuk seluruh rincian.</p>
        </div>
        <div class="verification-queue-stat">
            <small>Submission Ditampilkan</small>
            <strong>{{ $submissions->count() }}</strong>
            <span>{{ $statusLabels[$status] ?? 'Semua Status' }}</span>
        </div>
    </header>

    <section class="verification-project-card">
        <div>
            <small>Project Akreditasi</small>
            <h2>{{ $project->name }}</h2>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->code }}</p>
        </div>
        @if ($projects->count() > 1)
            <label>
                <span>Pilih Project</span>
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
            <span>Kriteria</span>
            <select wire:model.live="criterionId">
                <option value="">Semua kriteria</option>
                @foreach ($criteria as $criterion)
                    <option value="{{ $criterion->id }}">{{ $criterion->code }} &middot; {{ $criterion->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Status</span>
            <select wire:model.live="status">
                <option value="pending_review">Menunggu Verifikasi</option>
                <option value="verified">Terverifikasi</option>
                <option value="need_revision">Perlu Revisi</option>
                <option value="all">Semua status</option>
            </select>
        </label>
    </section>

    <section class="verification-queue-card">
        <div class="verification-queue-heading">
            <div><strong>Daftar Rincian</strong><span>Scope dibatasi oleh assignment reviewer aktif.</span></div>
            <span>{{ $submissions->count() }} submission</span>
        </div>
        <div class="verification-table-wrap">
            <table class="verification-table">
                <thead>
                    <tr><th>Butir & Rincian</th><th>PIC / Submitter</th><th>File</th><th>Tanggal Submit</th><th>Status</th><th>Aksi</th></tr>
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
                            <td><strong>{{ $submission->submitter->name }}</strong><small>Revisi {{ $submission->revision_number }}</small></td>
                            <td><strong>{{ $submission->documents_count }}</strong><small>snapshot file</small></td>
                            <td><strong>{{ $submission->submitted_at->format('d M Y') }}</strong><small>{{ $submission->submitted_at->format('H:i') }}</small></td>
                            <td><span class="workflow-badge status-{{ $submission->status }}">{{ $statusLabels[$submission->status] ?? $submission->status }}</span></td>
                            <td><a href="{{ route('verifications.show', $submission) }}" wire:navigate>Buka Verifikasi</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="verification-empty"><strong>Tidak ada submission</strong><span>Tidak ada rincian pada filter dan scope aktif.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
