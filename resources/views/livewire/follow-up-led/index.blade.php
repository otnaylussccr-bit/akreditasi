<div class="follow-up-page">
    <header class="follow-up-heading">
        <div>
            <span class="eyebrow dark">Monitoring Butir Akreditasi</span>
            <h1>Follow Up LED</h1>
            <p>Daftar {{ $workItemCount }} pekerjaan tindak lanjut LED &middot; {{ $project->studyProgram->name }}</p>
            @if ($project->status !== 'active')
                <span class="workflow-badge status-{{ $project->status }}">Read-only &middot; {{ str($project->status)->replace('_', ' ')->title() }}</span>
            @endif
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
        </div>
        <div class="deadline-card">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            <div>
                <span>Deadline Final</span>
                <strong>{{ $project->final_deadline->format('d M Y') }}</strong>
            </div>
        </div>
        <div class="follow-up-stat">
            <span>Total Pekerjaan</span>
            <strong>{{ $workItemCount }}</strong>
            <small>{{ $structureCount }} struktur &middot; {{ $headerCount }} header LED</small>
        </div>
        <div class="follow-up-stat work-stat">
            <span>Selesai</span>
            <strong>{{ $verifiedWorkItemCount }}</strong>
            <small>{{ $workProgress }}% dari {{ $workItemCount }} pekerjaan</small>
        </div>
    </header>

    @if ($criteria->isEmpty())
        <section class="empty-state">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm2 3h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg>
            </div>
            <h2>Tidak ada kriteria yang dapat diakses</h2>
            <p>Membership proyek atau assignment kriteria aktif tidak tersedia untuk akun ini.</p>
        </section>
    @else
        <section class="filter-card criteria-filter-card" aria-label="Filter Follow Up LED">
            <div class="filter-field">
                <label for="criterion-filter">Kriteria</label>
                <select id="criterion-filter" wire:change="selectCriterion($event.target.value)">
                    @foreach ($criteria as $criterion)
                        <option value="{{ $criterion->id }}" @selected($activeCriterion?->id === $criterion->id)>
                            {{ $criterion->code }} &middot; {{ $criterion->name }} ({{ $criterion->items_count }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="status-filter">Status</label>
                <select id="status-filter" wire:model.live="workflowStatus">
                    <option value="">Semua Status</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="pic-filter">PIC Sumber Excel</label>
                <select id="pic-filter" wire:model.live="sourcePic">
                    <option value="">Semua PIC</option>
                    @foreach ($sourcePics as $pic)
                        <option value="{{ $pic }}">{{ $pic }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label for="item-search">Pencarian</label>
                <input id="item-search" type="search" wire:model.live.debounce.350ms="search" placeholder="Cari butir atau rincian...">
            </div>
            <button class="filter-reset" type="button" wire:click="resetFilters">Reset Filter</button>
        </section>

        @php
            $statusLabels = [
                'header' => 'Header LED',
                'in_progress' => 'Dalam Proses',
                'not_started' => 'Belum Mulai',
                'draft' => 'Draft',
                'pending_review' => 'Menunggu Verifikasi',
                'need_revision' => 'Perlu Revisi',
                'verified' => 'Terverifikasi',
            ];
        @endphp

        <div class="follow-up-workspace {{ $selectedItem ? 'has-detail' : '' }}">
            <section class="follow-up-list-card">
                <div class="table-card-heading">
                    <div>
                        <span>Kriteria {{ $activeCriterion->code }} &middot; {{ $activeCriterion->name }}</span>
                        <small>Pilih butir untuk melihat rincian dokumen lengkap tanpa meninggalkan halaman.</small>
                    </div>
                    <span>{{ $items->count() }} dari {{ $items->total() }} butir ditampilkan</span>
                </div>

                <div class="follow-up-item-list">
                    @forelse ($items as $item)
                        @php
                            $deadline = $item->target_deadline ?? $item->source_deadline;
                            $isActive = $selectedItem?->id === $item->id;
                        @endphp
                        <article
                            class="follow-up-item-card aggregate-status-{{ $item->aggregate_status }} {{ $isActive ? 'active' : '' }} {{ $item->is_led_header ? 'led-header-card' : '' }}"
                            wire:key="follow-up-item-{{ $item->id }}"
                            style="--criterion-header: {{ $item->criterion->color_header }}; --criterion-body: {{ $item->criterion->color_body }}"
                        >
                            <div class="follow-up-item-summary">
                                <span class="follow-up-item-code">{{ $item->criterion->code }}.{{ $item->number }}</span>
                                <div class="follow-up-item-title">
                                    <h2>{{ $item->title }}</h2>
                                    <p>{{ $item->is_led_header ? 'Struktur pembuka Laporan Evaluasi Diri' : $item->requirements->count().' rincian dokumen' }}</p>
                                </div>
                                <div class="follow-up-item-status">
                                    <span class="workflow-badge status-{{ $item->aggregate_status }}">{{ $statusLabels[$item->aggregate_status] }}</span>
                                    @if ($item->has_required_revision)
                                        <small>Ada rincian perlu revisi</small>
                                    @endif
                                </div>
                                <button
                                    class="follow-up-item-toggle"
                                    type="button"
                                    wire:click="openDetail({{ $item->id }})"
                                    aria-expanded="{{ $isActive ? 'true' : 'false' }}"
                                >{{ $isActive ? 'Rincian Terbuka' : 'Lihat Rincian' }}</button>
                            </div>

                            @unless ($item->is_led_header)
                                <div class="follow-up-item-meta">
                                    <div><span>PIC Sumber</span><strong>{{ $item->source_pic ?: 'Belum tercantum' }}</strong></div>
                                    <div><span>Deadline Internal</span><strong>{{ $deadline?->format('d M Y') ?? 'Belum ditentukan' }}</strong></div>
                                    <div class="follow-up-item-progress">
                                        <div><span>Progress Wajib</span><strong>{{ $item->verified_required_count }} / {{ $item->required_count }}</strong><b>{{ $item->verified_progress }}%</b></div>
                                        <div class="progress-track progress-{{ $item->has_required_revision ? 'need_revision' : $item->aggregate_status }}" aria-label="Progress {{ $item->verified_progress }} persen">
                                            <span style="width: {{ $item->verified_progress }}%"></span>
                                        </div>
                                    </div>
                                </div>
                            @endunless

                            @if ($isActive)
                                @if ($item->is_led_header)
                                    <div class="follow-up-header-notice">Header LED tidak memerlukan upload, verifikasi, atau progress rincian.</div>
                                @else
                                    <div class="follow-up-expanded" wire:key="expanded-item-{{ $selectedItem->id }}">
                                        <div class="follow-up-expanded-heading">
                                            <div>
                                                <strong>Rincian Dokumen Operasional</strong>
                                                <span>Status dan file ditampilkan langsung dari setiap rincian.</span>
                                            </div>
                                            <span>{{ $selectedItem->requirements->count() }} rincian</span>
                                        </div>
                                        <div class="follow-up-requirement-table-wrap">
                                            <table class="follow-up-requirement-table">
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
                                                    @forelse ($selectedItem->requirements as $requirement)
                                                        @php $latestDocument = $requirement->documents->first(); @endphp
                                                        <tr wire:key="follow-up-requirement-{{ $requirement->id }}">
                                                            <td><span class="requirement-number">{{ $requirement->number }}</span></td>
                                                            <td><strong>{{ $requirement->title }}</strong></td>
                                                            <td><span class="requirement-nature {{ $requirement->is_required ? 'required' : '' }}">{{ $requirement->is_required ? 'Wajib' : 'Opsional' }}</span></td>
                                                            <td>
                                                                <span class="workflow-badge status-{{ $requirement->workflow_status }}">
                                                                    {{ $statusOptions[$requirement->workflow_status] ?? $requirement->workflow_status }}
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <strong>{{ $requirement->documents->count() }} file</strong>
                                                                @if ($latestDocument)
                                                                    <small title="{{ $latestDocument->original_name }}">Terbaru: {{ $latestDocument->original_name }}</small>
                                                                @endif
                                                            </td>
                                                            <td>
                                                                <div class="follow-up-requirement-actions">
                                                                    @can('upload', $requirement)
                                                                        <a class="upload" href="{{ route('documents.upload', ['requirement' => $requirement, 'proyek' => $project->id]) }}" wire:navigate>Upload</a>
                                                                    @elsecan('accessUpload', $requirement)
                                                                        <span class="locked">Terkunci</span>
                                                                    @endcan

                                                                    @if ($latestDocument)
                                                                        @can('view', $latestDocument)
                                                                            <a href="{{ route('documents.preview', $latestDocument) }}" target="_blank" rel="noopener noreferrer">Lihat</a>
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
                                                        <tr><td colspan="6"><div class="table-empty compact">Belum ada rincian dokumen pada butir ini.</div></td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </article>
                    @empty
                        <div class="table-empty">
                            <strong>Tidak ada pekerjaan yang sesuai</strong>
                            <span>Ubah atau reset kombinasi filter pada kriteria aktif.</span>
                        </div>
                    @endforelse
                </div>

                @if ($items->hasPages())
                    <div class="table-pagination">
                        <span>Menampilkan {{ $items->firstItem() }}-{{ $items->lastItem() }} dari {{ $items->total() }} butir</span>
                        {{ $items->links() }}
                    </div>
                @endif
            </section>

            @if ($selectedItem)
                @php $selectedDeadline = $selectedItem->target_deadline ?? $selectedItem->source_deadline; @endphp
                <aside class="item-detail-panel" wire:key="item-detail-{{ $selectedItem->id }}">
                    <div class="detail-panel-head">
                        <div>
                            <span>Quick Summary Butir</span>
                            <strong>{{ $selectedItem->criterion->code }}.{{ $selectedItem->number }}</strong>
                        </div>
                        <span class="detail-active-mark" aria-label="Butir aktif">Aktif</span>
                    </div>

                    <div class="detail-criterion" style="--criterion-header: {{ $selectedItem->criterion->color_header }}; --criterion-body: {{ $selectedItem->criterion->color_body }}">
                        <span>{{ $selectedItem->criterion->code }}</span>
                        <div><small>Kriteria</small><strong>{{ $selectedItem->criterion->name }}</strong></div>
                    </div>

                    <h2>{{ $selectedItem->title }}</h2>
                    <span class="workflow-badge status-{{ $selectedItem->aggregate_status }}">{{ $statusLabels[$selectedItem->aggregate_status] }}</span>

                    @if ($selectedItem->has_required_revision)
                        <div class="detail-revision-alert" role="status">
                            <strong>Tindakan diperlukan</strong>
                            <span>Satu atau lebih rincian wajib dikembalikan untuk revisi.</span>
                        </div>
                    @endif

                    @if ($selectedItem->is_led_header)
                        <div class="header-led-notice">
                            <strong>Header Laporan Evaluasi Diri</strong>
                            <span>Butir ini tidak memerlukan upload, verifikasi, atau progress rincian.</span>
                        </div>
                    @else
                        <dl class="detail-meta compact-meta">
                            <div><dt>PIC Sumber</dt><dd>{{ $selectedItem->source_pic ?: 'Belum tercantum' }}</dd></div>
                            <div><dt>Deadline</dt><dd>{{ $selectedDeadline?->format('d M Y') ?? 'Belum ditentukan' }}</dd></div>
                        </dl>

                        <div class="detail-progress-card">
                            <div><span>Progress Terverifikasi</span><strong>{{ $selectedItem->verified_required_count }} / {{ $selectedItem->required_count }}</strong><b>{{ $selectedItem->verified_progress }}%</b></div>
                            <div class="progress-track progress-{{ $selectedItem->has_required_revision ? 'need_revision' : $selectedItem->aggregate_status }}">
                                <span style="width: {{ $selectedItem->verified_progress }}%"></span>
                            </div>
                        </div>

                        @if ($selectedItem->source_detail_text || $selectedItem->source_note || $selectedItem->source_aux_note)
                            <div class="source-description">
                                <strong>Deskripsi / Ringkasan Butir</strong>
                                @if ($selectedItem->source_detail_text)<p>{{ $selectedItem->source_detail_text }}</p>@endif
                                @if ($selectedItem->source_note)<p>{{ $selectedItem->source_note }}</p>@endif
                                @if ($selectedItem->source_aux_note)<p>{{ $selectedItem->source_aux_note }}</p>@endif
                            </div>
                        @endif

                        <div class="requirements-heading">
                            <div><strong>Ringkasan Rincian</strong><span>{{ $selectedItem->requirements->count() }} rincian dokumen</span></div>
                            <span>Status aktual</span>
                        </div>
                        <ol class="requirement-list quick-list">
                            @forelse ($selectedItem->requirements as $requirement)
                                <li>
                                    <span class="requirement-number">{{ $requirement->number }}</span>
                                    <div><strong>{{ $requirement->title }}</strong><span>{{ $requirement->is_required ? 'Wajib' : 'Opsional' }} &middot; {{ $requirement->documents->count() }} file</span></div>
                                    <span class="workflow-badge status-{{ $requirement->workflow_status }}">{{ $statusOptions[$requirement->workflow_status] ?? $requirement->workflow_status }}</span>
                                </li>
                            @empty
                                <li class="requirements-empty">Belum ada rincian dokumen pada butir ini.</li>
                            @endforelse
                        </ol>
                    @endif
                </aside>
            @endif
        </div>
    @endif
</div>
