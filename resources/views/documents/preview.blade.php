<x-layouts.app>
    <x-slot:title>{{ __('documents.meta.preview_title') }}</x-slot:title>

    @php
        $requirement = $document->requirement;
        $item = $requirement->item;
        $criterion = $item->criterion;
        $project = $criterion->project;
        $extension = strtoupper($document->extension);
        $formattedSize = $document->size_bytes >= 1048576
            ? __('documents.units.megabytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1048576, 2, true)])
            : __('documents.units.kilobytes', ['size' => \App\Support\LocalizedNumber::format($document->size_bytes / 1024, 1, true)]);
    @endphp

    <div class="document-preview-page">
        <header class="document-preview-heading">
            <div>
                <span class="eyebrow dark">{{ __('documents.preview.eyebrow') }}</span>
                <h1>{{ $document->original_name }}</h1>
                <p>{{ __('documents.preview.introduction') }}</p>
            </div>
            <div class="document-preview-actions">
                <a class="preview-back-button" href="{{ $backUrl }}">{{ $backLabel }}</a>
                <a class="preview-download-button" href="{{ route('documents.download', $document) }}">{{ __('documents.actions.download') }}</a>
            </div>
        </header>

        <nav class="document-breadcrumb" aria-label="{{ __('documents.preview.breadcrumb_aria') }}">
            <a href="{{ $fromRepository ? route('repository.index', ['proyek' => $project->id, 'dokumen' => $document->id]) : route('documents.index', ['proyek' => $project->id]) }}">{{ $fromRepository ? __('documents.preview.evidence_repository') : __('documents.preview.document_input') }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span>{{ $project->code }}</span>
            <span aria-hidden="true">&rsaquo;</span>
            <span>{{ __('documents.preview.criterion_with_code', ['code' => $criterion->code]) }}</span>
            <span aria-hidden="true">&rsaquo;</span>
            <strong>{{ __('documents.preview.document_preview') }}</strong>
        </nav>

        <div class="document-preview-layout">
            <section class="document-preview-stage" aria-label="{{ __('documents.preview.content_aria') }}">
                <div class="document-preview-stage-heading">
                    <div>
                        <span>{{ __('documents.preview.content_heading') }}</span>
                        <small>{{ $extension }} &middot; {{ $formattedSize }}</small>
                    </div>
                    <span class="document-active-state {{ $document->is_active ? 'active' : 'historical' }}">
                        {{ $document->is_active ? __('documents.status.active') : __('documents.status.historical') }}
                    </span>
                </div>

                @if (! $fileAvailable)
                    <div class="document-preview-fallback unavailable">
                        <span aria-hidden="true">!</span>
                        <strong>{{ __('documents.preview.unavailable_title') }}</strong>
                        <p>{{ __('documents.preview.unavailable_body') }}</p>
                    </div>
                @elseif ($previewType === 'pdf')
                    <iframe
                        class="document-preview-frame"
                        src="{{ route('documents.preview.content', $document) }}"
                        title="{{ __('documents.preview.file_preview_aria', ['name' => $document->original_name]) }}"
                    ></iframe>
                @elseif ($previewType === 'image')
                    <div class="document-preview-image-wrap">
                        <img
                            src="{{ route('documents.preview.content', $document) }}"
                            alt="{{ __('documents.preview.file_preview_aria', ['name' => $document->original_name]) }}"
                        >
                    </div>
                @else
                    <div class="document-preview-fallback">
                        <span aria-hidden="true">{{ $extension }}</span>
                        <strong>{{ __('documents.preview.unsupported_title') }}</strong>
                        <p>{{ __('documents.preview.unsupported_body') }}</p>
                        <a href="{{ route('documents.download', $document) }}">{{ __('documents.preview.download_document') }}</a>
                    </div>
                @endif
            </section>

            <aside class="document-preview-metadata">
                <div class="document-preview-card-heading">
                    <span>{{ __('documents.preview.information_heading') }}</span>
                    <small>{{ __('documents.preview.information_description') }}</small>
                </div>

                <dl class="document-preview-file-meta">
                    <div><dt>{{ __('documents.preview.file_name') }}</dt><dd>{{ $document->original_name }}</dd></div>
                    <div><dt>{{ __('documents.preview.format') }}</dt><dd>{{ $extension }}</dd></div>
                    <div><dt>{{ __('documents.preview.size') }}</dt><dd>{{ $formattedSize }}</dd></div>
                    <div><dt>{{ __('documents.preview.uploader') }}</dt><dd>{{ $document->uploader->name }}</dd></div>
                    <div><dt>{{ __('documents.preview.upload_date') }}</dt><dd>{{ \App\Support\BusinessDateTime::label($document->uploaded_at) }}</dd></div>
                    <div><dt>{{ __('documents.preview.revision_number') }}</dt><dd>{{ __('documents.preview.revision_value', ['revision' => $document->revision_number]) }}</dd></div>
                    <div><dt>{{ __('documents.preview.file_status') }}</dt><dd>{{ $document->is_active ? __('documents.status.active') : __('documents.status.historical') }}</dd></div>
                </dl>

                <div class="document-preview-context">
                    <div><small>{{ __('documents.preview.project') }}</small><strong>{{ $project->code }}</strong><span>{{ $project->name }}</span></div>
                    <div><small>{{ __('documents.preview.criterion') }}</small><strong>{{ __('documents.preview.criterion_with_code', ['code' => $criterion->code]) }}</strong><span>{{ $criterion->name }}</span></div>
                    <div><small>{{ __('documents.preview.item') }}</small><strong>{{ $criterion->code }}.{{ $item->number }}</strong><span>{{ $item->title }}</span></div>
                    <div><small>{{ __('documents.preview.requirement') }}</small><strong>{{ __('documents.preview.requirement_with_number', ['number' => $requirement->number]) }}</strong><span>{{ $requirement->title }}</span></div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
