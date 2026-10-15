<div class="evidence-repository-page">
    @php
        $formatBytes = static function (int $bytes): string {
            if ($bytes >= 1048576) {
                return number_format($bytes / 1048576, 2, ',', '.').' MB';
            }

            return number_format(max($bytes, 1) / 1024, 1, ',', '.').' KB';
        };
    @endphp

    <header class="repository-heading">
        <div>
            <span class="eyebrow dark">Pusat Dokumen Akreditasi</span>
            <h1>Repository Bukti</h1>
            <p>{{ $project->studyProgram->name }} &middot; {{ $project->name }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">Read-only &middot; {{ str($project->status)->replace('_', ' ')->title() }}</span>
            @endif
        </div>
        <div class="repository-heading-actions">
            @if ($projects->count() > 1)
                <label class="repository-project-select">
                    <span>Project</span>
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
                <div><small>Batas Akhir Pengajuan</small><strong>{{ $project->final_deadline->format('d M Y') }}</strong></div>
            </div>
        </div>
    </header>

    <section class="repository-filter-bar" aria-label="Filter Repository Bukti">
        <label class="repository-search-field">
            <span>Pencarian</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="Cari nama file, butir, atau rincian...">
        </label>
        <label>
            <span>Kriteria</span>
            <select wire:model.live="criterionId">
                <option value="">Semua Kriteria</option>
                @foreach ($criteria as $criterion)
                    <option value="{{ $criterion->id }}">{{ $criterion->code }} - {{ $criterion->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Status Verifikasi</span>
            <select wire:model.live="workflowStatus">
                <option value="">Semua Status</option>
                @foreach (['not_started', 'draft', 'pending_review', 'need_revision', 'verified'] as $status)
                    <option value="{{ $status }}">{{ $statusLabels[$status] }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Jenis File</span>
            <select wire:model.live="extension">
                <option value="">Semua Jenis</option>
                @foreach ($fileTypes as $fileType)
                    <option value="{{ $fileType }}">{{ strtoupper($fileType) }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Uploader</span>
            <select wire:model.live="uploaderId">
                <option value="">Semua Uploader</option>
                @foreach ($uploaderOptions as $uploader)
                    <option value="{{ $uploader->id }}">{{ $uploader->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="repository-reset-button" type="button" wire:click="resetFilters">Reset Filter</button>
    </section>

    <div class="repository-workspace">
        <aside class="repository-folder-panel">
            <header><span class="repository-panel-icon" aria-hidden="true">&#9638;</span><div><h2>Struktur Folder</h2><p>Folder virtual dari metadata.</p></div></header>
            <nav class="repository-folder-tree" aria-label="Struktur folder virtual">
                <button
                    class="repository-root-folder {{ $folderType === 'all' ? 'selected' : '' }}"
                    type="button"
                    wire:click="selectFolder('all', null)"
                >
                    <span aria-hidden="true">&#128193;</span><strong>Semua Dokumen</strong><small>{{ $folderCount }}</small>
                </button>

                <ul>
                    @foreach ($criteria as $criterion)
                        @php $criterionExpanded = in_array($criterion->id, $expandedCriteria, true); @endphp
                        <li wire:key="repository-criterion-{{ $criterion->id }}">
                            <div class="repository-tree-row {{ $folderType === 'criterion' && $folderNodeId === $criterion->id ? 'selected' : '' }}">
                                <button class="repository-tree-toggle" type="button" wire:click="toggleCriterion({{ $criterion->id }})" aria-expanded="{{ $criterionExpanded ? 'true' : 'false' }}">{{ $criterionExpanded ? '−' : '+' }}</button>
                                <button class="repository-tree-label" type="button" wire:click="selectFolder('criterion', {{ $criterion->id }})">
                                    <span aria-hidden="true">&#128193;</span><strong>Kriteria {{ $criterion->code }}</strong><small>{{ $criterion->repository_documents_count }}</small>
                                </button>
                            </div>
                            @if ($criterionExpanded)
                                <ul>
                                    @foreach ($criterion->items as $item)
                                        @php $itemExpanded = in_array($item->id, $expandedItems, true); @endphp
                                        <li wire:key="repository-item-{{ $item->id }}">
                                            <div class="repository-tree-row child {{ $folderType === 'item' && $folderNodeId === $item->id ? 'selected' : '' }}">
                                                <button class="repository-tree-toggle" type="button" wire:click="toggleItem({{ $item->id }})" aria-expanded="{{ $itemExpanded ? 'true' : 'false' }}">{{ $itemExpanded ? '−' : '+' }}</button>
                                                <button class="repository-tree-label" type="button" wire:click="selectFolder('item', {{ $item->id }})" title="{{ $item->title }}">
                                                    <span aria-hidden="true">&#128193;</span><strong>{{ $criterion->code }}.{{ $item->number }}</strong><small>{{ $item->repository_documents_count }}</small>
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
                                                                title="{{ $requirement->title }}"
                                                            >
                                                                <span aria-hidden="true">&#128196;</span><strong>R{{ $requirement->number }}</strong><small>{{ $requirement->repository_documents_count }}</small>
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
                    <div><span class="repository-panel-icon" aria-hidden="true">&#9638;</span><div><h2>Daftar Dokumen</h2><p>{{ $documents->total() }} file sesuai filter dan scope akses.</p></div></div>
                    <div>
                        <label class="repository-sort-select"><span>Urutkan</span><select wire:model.live="sort">@foreach ($sortOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        @if ($canUpload)
                            <a class="repository-upload-button" href="{{ route('documents.index', ['proyek' => $project->id]) }}" wire:navigate>+ Upload Dokumen</a>
                        @endif
                    </div>
                </header>

                <div class="repository-table-wrap">
                    <table class="repository-document-table">
                        <thead>
                            <tr>
                                <th>Pilih</th><th>Nama File</th><th>Kriteria</th><th>Butir / Rincian</th><th>Jenis</th><th>Ukuran</th><th>Uploader</th><th>Tanggal Upload</th><th>Status Saat Ini</th><th>Versi</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($documents as $document)
                                @php
                                    $requirement = $document->requirement;
                                    $item = $requirement->item;
                                    $criterion = $item->criterion;
                                    $isSelected = $selectedDocument?->id === $document->id;
                                    $isPreviewable = in_array(strtolower($document->extension), $previewableExtensions, true);
                                @endphp
                                <tr class="{{ $isSelected ? 'selected' : '' }}" wire:key="repository-document-{{ $document->id }}">
                                    <td><button class="repository-row-select" type="button" wire:click="selectDocument({{ $document->id }})" aria-label="Pilih {{ $document->original_name }}">{{ $isSelected ? '&#10003;' : '' }}</button></td>
                                    <td>
                                        <button class="repository-file-name" type="button" wire:click="selectDocument({{ $document->id }})">
                                            <span class="repository-file-icon type-{{ strtolower($document->extension) }}">{{ strtoupper($document->extension) }}</span>
                                            <strong title="{{ $document->original_name }}">{{ $document->original_name }}</strong>
                                        </button>
                                    </td>
                                    <td><strong>{{ $criterion->code }}</strong><small>{{ $criterion->name }}</small></td>
                                    <td><strong>{{ $criterion->code }}.{{ $item->number }} / R{{ $requirement->number }}</strong><small>{{ $requirement->title }}</small></td>
                                    <td>{{ strtoupper($document->extension) }}</td>
                                    <td>{{ $formatBytes($document->size_bytes) }}</td>
                                    <td>{{ $document->uploader->name }}</td>
                                    <td>{{ $document->uploaded_at->format('d M Y, H:i') }}</td>
                                    <td><span class="workflow-badge status-{{ $requirement->workflow_status }}">{{ $statusLabels[$requirement->workflow_status] ?? $requirement->workflow_status }}</span></td>
                                    <td><strong>v{{ $document->revision_number }}</strong><small>{{ $document->is_active ? 'Aktif' : 'Historis' }}</small></td>
                                    <td>
                                        @if ($isPreviewable)
                                            <a class="repository-table-action" href="{{ route('documents.preview', ['document' => $document, 'asal' => 'repository']) }}" target="_blank" rel="noopener noreferrer">Lihat</a>
                                        @else
                                            <a class="repository-table-action" href="{{ route('documents.download', $document) }}">Unduh</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="11"><div class="repository-empty"><strong>Tidak ada dokumen ditemukan</strong><span>Ubah filter atau pilih folder lain dalam scope Anda.</span></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($documents->hasPages())
                    <div class="repository-pagination">
                        <span>Menampilkan {{ $documents->firstItem() }}-{{ $documents->lastItem() }} dari {{ $documents->total() }} dokumen</span>
                        {{ $documents->links() }}
                    </div>
                @endif
            </section>

            @if ($selectedDocument)
                @php
                    $selectedRequirement = $selectedDocument->requirement;
                    $selectedItem = $selectedRequirement->item;
                    $selectedCriterion = $selectedItem->criterion;
                    $selectedPreviewable = in_array(strtolower($selectedDocument->extension), $previewableExtensions, true);
                @endphp
                <section class="repository-detail-panel" wire:key="repository-detail-{{ $selectedDocument->id }}">
                    <nav class="repository-detail-breadcrumb" aria-label="Posisi dokumen">
                        <span>{{ $project->code }}</span><b>&rsaquo;</b><span>Kriteria {{ $selectedCriterion->code }}</span><b>&rsaquo;</b><span>{{ $selectedCriterion->code }}.{{ $selectedItem->number }}</span><b>&rsaquo;</b><strong>Rincian {{ $selectedRequirement->number }}</strong>
                    </nav>
                    <div class="repository-detail-heading">
                        <div class="repository-file-icon large type-{{ strtolower($selectedDocument->extension) }}">{{ strtoupper($selectedDocument->extension) }}</div>
                        <div><h2>{{ $selectedDocument->original_name }}</h2><p>Status rincian saat ini: <span class="workflow-badge status-{{ $selectedRequirement->workflow_status }}">{{ $statusLabels[$selectedRequirement->workflow_status] ?? $selectedRequirement->workflow_status }}</span></p></div>
                        <div class="repository-detail-actions">
                            @if ($selectedPreviewable)
                                <a class="primary" href="{{ route('documents.preview', ['document' => $selectedDocument, 'asal' => 'repository']) }}" target="_blank" rel="noopener noreferrer">Lihat Dokumen</a>
                            @endif
                            <a href="{{ route('documents.download', $selectedDocument) }}">Unduh Original</a>
                            <a href="#repository-version-history">Riwayat Versi</a>
                        </div>
                    </div>

                    <div class="repository-detail-grid">
                        <dl class="repository-file-metadata">
                            <div><dt>Format</dt><dd>{{ strtoupper($selectedDocument->extension) }}</dd></div>
                            <div><dt>Ukuran</dt><dd>{{ $formatBytes($selectedDocument->size_bytes) }}</dd></div>
                            <div><dt>Uploader</dt><dd>{{ $selectedDocument->uploader->name }}</dd></div>
                            <div><dt>Tanggal Upload</dt><dd>{{ $selectedDocument->uploaded_at->format('d M Y, H:i') }}</dd></div>
                            <div><dt>Versi File</dt><dd>v{{ $selectedDocument->revision_number }}</dd></div>
                            <div><dt>Status File (Metadata)</dt><dd>{{ $selectedDocument->is_active ? 'Aktif' : 'Historis' }}</dd></div>
                        </dl>

                        <div class="repository-related-item">
                            <header><span aria-hidden="true">&#128279;</span><div><strong>Relasi Butir</strong><small>Relasi langsung yang tersimpan pada schema.</small></div></header>
                            <div>
                                <span>{{ $selectedCriterion->code }}.{{ $selectedItem->number }} / R{{ $selectedRequirement->number }}</span>
                                <strong>{{ $selectedRequirement->title }}</strong>
                                <small>{{ $selectedItem->title }}</small>
                                <a href="{{ route('follow-up-led.index', ['proyek' => $project->id, 'kriteria' => $selectedCriterion->id, 'cari' => $selectedItem->title]) }}">Buka Rincian</a>
                            </div>
                            <p>Satu file original terikat pada satu rincian. Repository tidak membuat mapping multi-butir baru.</p>
                        </div>
                    </div>

                    <div class="repository-version-history" id="repository-version-history">
                        <header><div><h3>Riwayat File & Snapshot Rincian</h3><p>File dikelompokkan oleh rincian; nomor revisi tidak dianggap sebagai lineage antar-file.</p></div><span>{{ $versions->count() }} file</span></header>
                        <div class="repository-version-list">
                            @foreach ($versions as $version)
                                <article class="{{ $version->id === $selectedDocument->id ? 'selected' : '' }}">
                                    <button type="button" wire:click="selectDocument({{ $version->id }})"><strong>v{{ $version->revision_number }} &middot; {{ $version->original_name }}</strong><span>{{ $version->uploaded_at->format('d M Y, H:i') }} oleh {{ $version->uploader->name }}</span></button>
                                    <span class="repository-file-state {{ $version->is_active ? 'active' : 'historical' }}">{{ $version->is_active ? 'Aktif' : 'Historis' }}</span>
                                    <div class="repository-snapshot-list">
                                        @forelse ($version->reviewSubmissions as $submission)
                                            <span>Snapshot r{{ $submission->revision_number }}: {{ $statusLabels[$submission->status] ?? str($submission->status)->replace('_', ' ')->title() }}</span>
                                        @empty
                                            <span>Belum masuk snapshot review</span>
                                        @endforelse
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>

                    <div class="repository-scope-note">
                        <strong>Dokumen original</strong>
                        <span>Lampiran koreksi Reviewer tidak dicampur ke Repository Bukti dan tetap mengikuti endpoint serta authorization feedback existing.</span>
                    </div>
                </section>
            @endif
        </main>
    </div>
</div>
