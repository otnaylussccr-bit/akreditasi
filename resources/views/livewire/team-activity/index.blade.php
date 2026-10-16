<div class="team-activity-page">
    <header class="team-activity-heading">
        <div>
            <span class="eyebrow dark">Jejak Workflow Akreditasi</span>
            <h1>Aktivitas Tim</h1>
            <p>Riwayat aktivitas tim {{ $project->studyProgram->name }} dalam scope akses Anda.</p>
        </div>
        <div class="team-activity-heading-actions">
            @if ($projects->count() > 1)
                <label class="team-activity-project-select">
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
            <div class="team-activity-deadline">
                <span class="team-activity-calendar" aria-hidden="true">&#9635;</span>
                <div><small>Batas Akhir Pengajuan</small><strong>{{ $project->final_deadline->format('d M Y') }}</strong></div>
                <div><small>Sisa Waktu</small><strong>{{ max(0, (int) now()->startOfDay()->diffInDays($project->final_deadline->copy()->startOfDay(), false)) }} hari</strong></div>
            </div>
        </div>
    </header>

    <section class="team-activity-filters" aria-label="Filter Aktivitas Tim">
        <label>
            <span>Aktivitas</span>
            <select wire:model.live="event">
                <option value="">Semua Aktivitas</option>
                @foreach ($eventOptions as $eventKey => $definition)
                    <option value="{{ $eventKey }}">{{ $definition['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Pengguna</span>
            <select wire:model.live="actorId">
                <option value="">Semua Pengguna</option>
                @foreach ($actorOptions as $actor)
                    <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                @endforeach
            </select>
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
        <div class="team-activity-date-filter">
            <label><span>Dari</span><input type="date" wire:model.live="dateFrom"></label>
            <label><span>Sampai</span><input type="date" wire:model.live="dateTo"></label>
        </div>
        <label class="team-activity-search">
            <span>Pencarian</span>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="Cari aktivitas, dokumen, butir, atau pengguna...">
        </label>
        <button type="button" wire:click="resetFilters">Reset</button>
    </section>

    <div class="team-activity-workspace">
        <section class="team-activity-history">
            <header>
                <div><span class="team-activity-section-icon" aria-hidden="true">&#8801;</span><div><h2>Riwayat Aktivitas</h2><p>{{ $periodLabel }}</p></div></div>
                <span>Menampilkan {{ $activities->firstItem() ?? 0 }}-{{ $activities->lastItem() ?? 0 }} dari {{ $activities->total() }} aktivitas</span>
            </header>

            <div class="team-activity-list">
                @forelse ($activities as $activity)
                    <article style="--activity-color: {{ $activity['color'] }}">
                        <time><strong>{{ \App\Support\BusinessDateTime::format($activity['created_at'], 'd M Y') }}</strong><span>{{ \App\Support\BusinessDateTime::label($activity['created_at'], 'H:i') }}</span></time>
                        <span class="team-activity-event-icon" aria-hidden="true">
                            @switch($activity['category'])
                                @case('upload') &#8593; @break
                                @case('submission') &#10148; @break
                                @case('revision') ! @break
                                @default &#10003;
                            @endswitch
                        </span>
                        <div class="team-activity-actor">
                            <span>{{ $activity['actor_initials'] }}</span>
                            <div><strong>{{ $activity['actor'] }}</strong><small>{{ $activity['actor_role'] }}</small></div>
                        </div>
                        <div class="team-activity-description">
                            <strong>{{ $activity['label'] }}</strong>
                            <a href="{{ $activity['url'] }}" wire:navigate>{{ $activity['item_code'] }} &middot; {{ $activity['item_title'] }}</a>
                        </div>
                        <span class="team-activity-badge">{{ $activity['short_label'] }}</span>
                        <a class="team-activity-open" href="{{ $activity['url'] }}" wire:navigate aria-label="Buka {{ $activity['item_code'] }}">&rsaquo;</a>
                    </article>
                @empty
                    <div class="team-activity-empty">
                        <strong>Aktivitas tidak ditemukan</strong>
                        <span>Ubah filter atau tunggu aktivitas workflow baru dalam scope akses ini.</span>
                    </div>
                @endforelse
            </div>

            @if ($activities->hasPages())
                <footer class="team-activity-pagination">
                    <span>Halaman {{ $activities->currentPage() }} dari {{ $activities->lastPage() }}</span>
                    {{ $activities->onEachSide(1)->links() }}
                </footer>
            @endif
        </section>

        <aside class="team-activity-sidebar">
            <section class="team-activity-summary">
                <header><h2>Ringkasan Aktivitas</h2><span>{{ \App\Support\BusinessDateTime::today()->format('d M Y') }} WIB</span></header>
                <div class="team-activity-kpis">
                    <article class="kpi-today"><span aria-hidden="true">&#9638;</span><div><small>Aktivitas Hari Ini</small><strong>{{ $todayCount }}</strong><p>Semua event hari ini, tidak dipengaruhi periode</p></div></article>
                    <article class="kpi-verified"><span aria-hidden="true">&#10003;</span><div><small>Keputusan Terverifikasi</small><strong>{{ $verifiedCount }}</strong><p>Dalam periode dan filter terpilih</p></div></article>
                    <article class="kpi-revision"><span aria-hidden="true">!</span><div><small>Revisi Terbuka</small><strong>{{ $openRevisionCount }}</strong><p>Status saat ini, bukan riwayat terselesaikan</p></div></article>
                    <article class="kpi-pic"><span aria-hidden="true">&#9787;</span><div><small>PIC Paling Aktif</small>@if ($mostActivePic)<strong class="pic-name">{{ $mostActivePic['name'] }}</strong><p>{{ $mostActivePic['count'] }} aktivitas pada periode terpilih</p>@else<strong class="pic-name">Belum ada</strong><p>Tidak ada aktivitas PIC yang cocok</p>@endif</div></article>
                </div>
            </section>

            <section class="team-activity-categories">
                <header><h2>Kegiatan per Kategori</h2><span>{{ $periodLabel }}</span></header>
                <div>
                    @foreach ($categories as $category)
                        <article class="category-{{ $category['category'] }}">
                            <span class="category-icon" aria-hidden="true">
                                @switch($category['category'])
                                    @case('upload') &#8593; @break
                                    @case('submission') &#10148; @break
                                    @case('revision') ! @break
                                    @default &#10003;
                                @endswitch
                            </span>
                            <div><strong>{{ $category['label'] }}</strong>@if ($category['latest'])<p>Terakhir: {{ $category['latest']['actor'] }} pada {{ $category['latest']['item_code'] }}</p>@else<p>Belum ada aktivitas pada periode ini</p>@endif</div>
                            <strong>{{ $category['count'] }}</strong>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="team-activity-accountability">
                <span aria-hidden="true">&#10003;</span>
                <div><strong>Jejak Aktivitas untuk Akuntabilitas</strong><p>Seluruh aktivitas workflow dicatat otomatis untuk mendukung transparansi dan traceability proses akreditasi.</p></div>
            </section>
        </aside>
    </div>
</div>
