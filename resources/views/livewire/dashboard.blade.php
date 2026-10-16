<div class="progress-dashboard-page" @if ($viewerTvMode) wire:poll.30s.visible="refreshDashboard" @endif>
    @if ($viewerTvMode)
        <div class="dashboard-refresh-status" role="status" aria-live="polite">
            <span><i aria-hidden="true"></i>Pembaruan otomatis setiap 30 detik</span>
            @if ($lastUpdatedAt)
                <time datetime="{{ $lastUpdatedAt }}">Terakhir diperbarui {{ \App\Support\BusinessDateTime::label($lastUpdatedAt, 'd M Y, H:i:s') }}</time>
            @endif
        </div>
    @endif

    @if ($projects->isEmpty())
        <header class="progress-dashboard-heading">
            <div>
                <span class="eyebrow dark">Dashboard Akreditasi</span>
                <h1>Ringkasan Progres</h1>
                <p>Monitoring pekerjaan akreditasi yang dapat Anda akses.</p>
            </div>
        </header>

        <section class="empty-state">
            <div class="empty-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm2 3h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg>
            </div>
            <h2>Belum ada proyek yang dapat diakses</h2>
            <p>Project membership atau assignment kriteria aktif belum tersedia untuk akun ini.</p>
        </section>
    @else
        @php $bucketMap = $statusBuckets->keyBy('status'); @endphp

        <header class="progress-dashboard-heading">
            <div>
                <span class="eyebrow dark">Dashboard Akreditasi</span>
                <h1>Ringkasan Progres</h1>
                <p>Monitoring Akreditasi {{ $project->studyProgram->name }} - Agung Putra University.</p>
            </div>
            @if ($projects->count() > 1)
                <label class="dashboard-project-select">
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
        </header>

        <section class="dashboard-deadline-banner deadline-{{ $deadline['state'] }}">
            <div class="dashboard-deadline-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2h2V2Zm12 8H5v8h14v-8ZM5 8h14V6H5v2Z"/></svg>
            </div>
            <div class="dashboard-deadline-date">
                <span>Batas Akhir Pengajuan</span>
                <strong>{{ $deadline['date']->format('d M Y') }}</strong>
                <small>{{ $project->code }} &middot; {{ $project->name }}</small>
            </div>
            <div class="dashboard-deadline-remaining">
                <span>Sisa Waktu</span>
                <strong>{{ $deadline['label'] }}</strong>
                <small>Progress waktu tidak ditampilkan karena tanggal mulai project tidak tersedia.</small>
            </div>
        </section>

        <section class="dashboard-kpi-grid" aria-label="KPI progres Follow Up LED">
            <article class="dashboard-kpi-card total">
                <span class="dashboard-kpi-icon" aria-hidden="true">LED</span>
                <div><span>Total Butir</span><strong>{{ $summary['work_count'] }}</strong><small>{{ $summary['header_count'] }} header non-operasional tidak dihitung</small></div>
            </article>

            @foreach ($statusBuckets as $bucket)
                <article class="dashboard-kpi-card status-{{ $bucket['status'] }}">
                    <span class="dashboard-kpi-icon" aria-hidden="true">
                        @switch($bucket['status'])
                            @case('verified') &#10003; @break
                            @case('pending_review') &#9201; @break
                            @case('need_revision') ! @break
                            @case('draft') D @break
                            @case('in_progress') ~ @break
                            @default &bull;
                        @endswitch
                    </span>
                    <div><span>{{ $bucket['label'] }}</span><strong>{{ $bucket['count'] }}</strong><small>{{ number_format($bucket['percentage'], 1, ',', '.') }}% dari total pekerjaan</small></div>
                </article>
            @endforeach
        </section>

        <div class="dashboard-analytics-grid">
            <section class="dashboard-panel criteria-progress-panel">
                <header class="dashboard-panel-heading">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">|||</span><div><h2>Progress per Kriteria</h2><p>Persentase selesai dihitung dari butir yang seluruh rincian wajibnya terverifikasi.</p></div></div>
                    <div class="dashboard-status-legend compact" aria-label="Legenda distribusi status">
                        @foreach ($statusBuckets as $bucket)
                            <span><i style="--legend-color: {{ $bucket['color'] }}"></i>{{ $bucket['label'] }}</span>
                        @endforeach
                    </div>
                </header>

                <div class="criteria-progress-table-wrap">
                    <table class="criteria-progress-table">
                        <thead><tr><th>Kriteria</th><th>Nama Kriteria</th><th>Distribusi Status</th><th>Selesai</th><th>Selesai / Total</th></tr></thead>
                        <tbody>
                            @forelse ($criteriaProgress as $entry)
                                <tr class="criterion-identity-row" style="--criterion-header: {{ $entry['criterion']->color_header }}; --criterion-body: {{ $entry['criterion']->color_body }}">
                                    <td class="criterion-identity-code-cell"><span class="dashboard-criterion-code">{{ $entry['criterion']->code }}</span></td>
                                    <td class="criterion-identity-name-cell"><strong>{{ $entry['criterion']->name }}</strong></td>
                                    <td>
                                        @if ($entry['summary']['work_count'] > 0)
                                            <div class="criteria-stacked-bar" aria-label="Distribusi status Kriteria {{ $entry['criterion']->code }}">
                                                @foreach ($entry['segments'] as $segment)
                                                    @if ($segment['count'] > 0)
                                                        <span title="{{ $segment['label'] }}: {{ $segment['count'] }}" style="width: {{ $segment['percentage'] }}%; --segment-color: {{ $segment['color'] }}"></span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="criteria-no-work">Tidak ada pekerjaan</span>
                                        @endif
                                    </td>
                                    <td><strong>{{ $entry['summary']['completion_percentage'] }}%</strong></td>
                                    <td>{{ $entry['summary']['verified_count'] }} / {{ $entry['summary']['work_count'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="dashboard-table-empty">Tidak ada kriteria dalam scope akses ini.</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-panel status-distribution-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">|||</span><div><h2>Distribusi Status</h2><p>Setiap butir pekerjaan masuk tepat satu bucket.</p></div></div>
                </header>

                @if ($summary['work_count'] > 0)
                    <div class="status-donut-layout">
                        <div class="status-donut" style="--donut-gradient: {{ $donutGradient }}" role="img" aria-label="Distribusi status {{ $summary['work_count'] }} butir pekerjaan">
                            <div><strong>{{ $summary['work_count'] }}</strong><span>butir</span></div>
                        </div>
                        <div class="dashboard-status-legend donut-legend">
                            @foreach ($statusBuckets as $bucket)
                                <div><span><i style="--legend-color: {{ $bucket['color'] }}"></i>{{ $bucket['label'] }}</span><strong>{{ $bucket['count'] }}</strong><small>{{ number_format($bucket['percentage'], 1, ',', '.') }}%</small></div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="dashboard-chart-empty"><strong>Belum ada pekerjaan operasional</strong><span>Header LED tidak dimasukkan ke distribusi status.</span></div>
                @endif
            </section>
        </div>

        <div class="dashboard-lower-grid">
            <section class="dashboard-panel attention-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon warning" aria-hidden="true">!</span><div><h2>Butir Mendekati Deadline / Perlu Perhatian</h2><p>Urutan: terlambat, perlu revisi, menunggu verifikasi, lalu deadline dalam {{ $attentionThresholdDays }} hari.</p></div></div>
                    @if ($followUpUrl)<a href="{{ $followUpUrl }}">Lihat Semua</a>@endif
                </header>

                <div class="dashboard-attention-table-wrap">
                    <table class="dashboard-attention-table">
                        <thead><tr><th>No.</th><th>Kode Butir</th><th>Judul Butir Follow Up LED</th><th>Kriteria</th><th>Status Agregat</th><th>Deadline Internal</th><th>Sisa Waktu</th><th>Aksi</th></tr></thead>
                        <tbody>
                            @forelse ($attentionItems as $entry)
                                <tr class="attention-criterion-row" style="--criterion-header: {{ $entry['item']->criterion->color_header }}; --criterion-body: {{ $entry['item']->criterion->color_body }}">
                                    <td>{{ $loop->iteration }}</td>
                                    <td class="attention-criterion-code-cell"><strong>{{ $entry['item']->criterion->code }}.{{ $entry['item']->number }}</strong></td>
                                    <td class="attention-criterion-title-cell">{{ $entry['item']->title }}</td>
                                    <td>{{ $entry['item']->criterion->code }} &middot; {{ $entry['item']->criterion->name }}</td>
                                    <td>
                                        <span class="workflow-badge status-{{ $entry['item']->aggregate_status }}">{{ $bucketMap[$entry['item']->aggregate_status]['label'] }}</span>
                                        @if ($entry['item']->has_required_revision)<small class="attention-revision-note">Ada rincian perlu revisi</small>@endif
                                    </td>
                                    <td>{{ $entry['deadline']?->format('d M Y') ?? 'Belum ditentukan' }}</td>
                                    <td><span class="attention-time {{ ($entry['days'] ?? 0) < 0 ? 'overdue' : '' }}">{{ $entry['time_label'] }}</span></td>
                                    <td>
                                        @if ($entry['url'])
                                            <a class="attention-open-link" href="{{ $entry['url'] }}">Buka Butir</a>
                                        @else
                                            <span class="attention-read-only">Hanya pantauan</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8"><div class="dashboard-table-empty"><strong>Tidak ada butir yang memerlukan perhatian.</strong><span>Butir terverifikasi dan deadline di luar threshold tidak ditampilkan.</span></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-panel activity-panel">
                <header class="dashboard-panel-heading simple">
                    <div><span class="dashboard-panel-icon" aria-hidden="true">i</span><div><h2>Catatan Penting & Aktivitas Terbaru</h2><p>Aktivitas workflow dalam scope akses project dan kriteria aktif.</p></div></div>
                    @if ($activityUrl)<a href="{{ $activityUrl }}" wire:navigate>Lihat Semua</a>@endif
                </header>

                @if (! $canViewActivity)
                    <div class="dashboard-activity-empty"><strong>Aktivitas tidak tersedia untuk peran ini</strong><span>Ringkasan progres tetap mengikuti scope akses Anda.</span></div>
                @elseif ($activities->isEmpty())
                    <div class="dashboard-activity-empty"><strong>Belum ada aktivitas terbaru</strong><span>Upload, submission, dan keputusan review yang valid akan tampil di sini.</span></div>
                @else
                    <div class="dashboard-activity-list">
                        @foreach ($activities as $activity)
                            <article>
                                <span class="activity-mark" aria-hidden="true">&bull;</span>
                                <div>
                                    <div><strong>{{ $activity['label'] }}</strong><time>{{ $activity['created_at'] ? \App\Support\BusinessDateTime::label($activity['created_at']) : 'Waktu tidak tersedia' }}</time></div>
                                    <p><a href="{{ $activity['url'] }}">{{ $activity['item_code'] }} &middot; {{ $activity['item_title'] }}</a></p>
                                    <small>Oleh {{ $activity['actor'] }}</small>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
