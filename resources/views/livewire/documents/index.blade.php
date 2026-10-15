<div class="documents-index-page">
    <header class="documents-index-heading">
        <div>
            <span class="eyebrow dark">Pemilihan Pekerjaan</span>
            <h1>Input Dokumen</h1>
            <p>Pilih project, kriteria, butir, dan rincian dokumen yang akan dikelola.</p>
        </div>
        <div class="documents-project-deadline">
            <span>Deadline Final</span>
            <strong>{{ $project->final_deadline->format('d M Y') }}</strong>
        </div>
    </header>

    <section class="documents-project-card">
        <div>
            <small>Project Akreditasi</small>
            <h2>{{ $project->name }}</h2>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->code }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">Read-only &middot; {{ str($project->status)->replace('_', ' ')->title() }}</span>
            @endif
        </div>
        @if ($projects->count() > 1)
            <div class="documents-project-switcher" aria-label="Pilih project akreditasi">
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
            <h2>Tidak ada kriteria yang dapat diakses</h2>
            <p>Assignment atau membership aktif tidak tersedia pada project ini.</p>
        </section>
    @else
        <nav class="documents-criteria-tabs" aria-label="Kriteria yang dapat diakses">
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
                        <small>{{ $criterion->items_count }} butir</small>
                    </div>
                </button>
            @endforeach
        </nav>

        <section class="documents-work-card">
            <div class="documents-work-heading" style="--criterion-header: {{ $activeCriterion->color_header }}; --criterion-body: {{ $activeCriterion->color_body }}">
                <span>{{ $activeCriterion->code }}</span>
                <div>
                    <small>Kriteria Aktif</small>
                    <h2>{{ $activeCriterion->name }}</h2>
                </div>
                <strong>{{ $items->count() }} butir</strong>
            </div>

            <div class="documents-item-list">
                @forelse ($items as $item)
                    <article class="documents-item-card {{ $item->is_led_header ? 'header-led' : '' }}" wire:key="document-item-{{ $item->id }}">
                        <header>
                            <span class="documents-item-number">{{ $activeCriterion->code }}.{{ $item->number }}</span>
                            <div>
                                <h3>{{ $item->title }}</h3>
                                <p>{{ $item->is_led_header ? 'Header Laporan Evaluasi Diri' : $item->requirements->count().' rincian dokumen' }}</p>
                            </div>
                            @if ($item->is_led_header)
                                <span class="documents-header-badge">Informasi</span>
                            @endif
                        </header>

                        @if ($item->is_led_header)
                            <div class="documents-header-notice">Header LED tetap ditampilkan sebagai struktur dokumen dan tidak menerima upload.</div>
                        @else
                            <div class="documents-requirement-table-wrap">
                                <table class="documents-requirement-table">
                                    <thead>
                                        <tr>
                                            <th>No.</th>
                                            <th>Rincian Dokumen</th>
                                            <th>Sifat</th>
                                            <th>Status</th>
                                            <th>File</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($item->requirements as $requirement)
                                            @php $latestDocument = $requirement->documents->first(); @endphp
                                            <tr wire:key="document-requirement-{{ $requirement->id }}">
                                                <td>{{ $requirement->number }}</td>
                                                <td><strong>{{ $requirement->title }}</strong></td>
                                                <td>{{ $requirement->is_required ? 'Wajib' : 'Opsional' }}</td>
                                                <td>
                                                    <span class="workflow-badge status-{{ $requirement->workflow_status }}">
                                                        {{ $statusLabels[$requirement->workflow_status] ?? $requirement->workflow_status }}
                                                    </span>
                                                </td>
                                                <td><strong>{{ $requirement->documents->count() }}</strong> file</td>
                                                <td>
                                                    <div class="documents-requirement-actions">
                                                        @can('upload', $requirement)
                                                            <a class="upload" href="{{ route('documents.upload', ['requirement' => $requirement, 'proyek' => $project->id]) }}" wire:navigate>Upload</a>
                                                        @elsecan('accessUpload', $requirement)
                                                            <span class="locked">Terkunci</span>
                                                        @endcan

                                                        @if ($latestDocument)
                                                            @can('view', $latestDocument)
                                                                <a href="{{ route('documents.preview', $latestDocument) }}" target="_blank" rel="noopener">Lihat</a>
                                                            @endcan
                                                        @endif

                                                        @cannot('accessUpload', $requirement)
                                                            @if (! $latestDocument)
                                                                <span class="read-only">Read-only</span>
                                                            @endif
                                                        @endcannot
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6"><div class="documents-requirement-empty">Belum ada rincian dokumen pada butir ini.</div></td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="documents-items-empty">Belum ada butir pada kriteria ini.</div>
                @endforelse
            </div>
        </section>
    @endif
</div>
