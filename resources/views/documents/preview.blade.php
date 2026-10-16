<x-layouts.app>
    <x-slot:title>Preview Dokumen | APU Accreditation</x-slot:title>

    @php
        $requirement = $document->requirement;
        $item = $requirement->item;
        $criterion = $item->criterion;
        $project = $criterion->project;
        $extension = strtoupper($document->extension);
        $formattedSize = $document->size_bytes >= 1048576
            ? number_format($document->size_bytes / 1048576, 2).' MB'
            : number_format($document->size_bytes / 1024, 1).' KB';
    @endphp

    <div class="document-preview-page">
        <header class="document-preview-heading">
            <div>
                <span class="eyebrow dark">Document Preview Viewer</span>
                <h1>{{ $document->original_name }}</h1>
                <p>Pratinjau aman dari penyimpanan privat APU Accreditation.</p>
            </div>
            <div class="document-preview-actions">
                <a class="preview-back-button" href="{{ $backUrl }}">{{ $backLabel }}</a>
                <a class="preview-download-button" href="{{ route('documents.download', $document) }}">Unduh</a>
            </div>
        </header>

        <nav class="document-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ $fromRepository ? route('repository.index', ['proyek' => $project->id, 'dokumen' => $document->id]) : route('documents.index', ['proyek' => $project->id]) }}">{{ $fromRepository ? 'Repository Bukti' : 'Input Dokumen' }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span>{{ $project->code }}</span>
            <span aria-hidden="true">&rsaquo;</span>
            <span>Kriteria {{ $criterion->code }}</span>
            <span aria-hidden="true">&rsaquo;</span>
            <strong>Preview Dokumen</strong>
        </nav>

        <div class="document-preview-layout">
            <section class="document-preview-stage" aria-label="Konten preview dokumen">
                <div class="document-preview-stage-heading">
                    <div>
                        <span>Preview Konten</span>
                        <small>{{ $extension }} &middot; {{ $formattedSize }}</small>
                    </div>
                    <span class="document-active-state {{ $document->is_active ? 'active' : 'historical' }}">
                        {{ $document->is_active ? 'Aktif' : 'Historis' }}
                    </span>
                </div>

                @if (! $fileAvailable)
                    <div class="document-preview-fallback unavailable">
                        <span aria-hidden="true">!</span>
                        <strong>File tidak tersedia</strong>
                        <p>Konten file tidak ditemukan pada penyimpanan privat. Metadata dokumen tetap dapat dilihat.</p>
                    </div>
                @elseif ($previewType === 'pdf')
                    <iframe
                        class="document-preview-frame"
                        src="{{ route('documents.preview.content', $document) }}"
                        title="Preview {{ $document->original_name }}"
                    ></iframe>
                @elseif ($previewType === 'image')
                    <div class="document-preview-image-wrap">
                        <img
                            src="{{ route('documents.preview.content', $document) }}"
                            alt="Preview {{ $document->original_name }}"
                        >
                    </div>
                @else
                    <div class="document-preview-fallback">
                        <span aria-hidden="true">{{ $extension }}</span>
                        <strong>Preview isi belum tersedia</strong>
                        <p>Format ini tidak ditampilkan langsung oleh viewer. Gunakan tombol Unduh untuk membuka file dengan aplikasi yang sesuai.</p>
                        <a href="{{ route('documents.download', $document) }}">Unduh Dokumen</a>
                    </div>
                @endif
            </section>

            <aside class="document-preview-metadata">
                <div class="document-preview-card-heading">
                    <span>Informasi Dokumen</span>
                    <small>Metadata file dan konteks akreditasi.</small>
                </div>

                <dl class="document-preview-file-meta">
                    <div><dt>Nama File</dt><dd>{{ $document->original_name }}</dd></div>
                    <div><dt>Format</dt><dd>{{ $extension }}</dd></div>
                    <div><dt>Ukuran</dt><dd>{{ $formattedSize }}</dd></div>
                    <div><dt>Uploader</dt><dd>{{ $document->uploader->name }}</dd></div>
                    <div><dt>Tanggal Upload</dt><dd>{{ \App\Support\BusinessDateTime::label($document->uploaded_at) }}</dd></div>
                    <div><dt>Nomor Revisi</dt><dd>v{{ $document->revision_number }}</dd></div>
                    <div><dt>Status File</dt><dd>{{ $document->is_active ? 'Aktif' : 'Historis' }}</dd></div>
                </dl>

                <div class="document-preview-context">
                    <div><small>Project</small><strong>{{ $project->code }}</strong><span>{{ $project->name }}</span></div>
                    <div><small>Kriteria</small><strong>Kriteria {{ $criterion->code }}</strong><span>{{ $criterion->name }}</span></div>
                    <div><small>Butir</small><strong>{{ $criterion->code }}.{{ $item->number }}</strong><span>{{ $item->title }}</span></div>
                    <div><small>Rincian</small><strong>Rincian {{ $requirement->number }}</strong><span>{{ $requirement->title }}</span></div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
