<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDocument;
use App\Models\AccreditationReviewSubmission;
use App\Services\AccreditationProjectContextService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccreditationDocumentAccessController extends Controller
{
    private const PREVIEW_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function preview(
        AccreditationDocument $document,
        Request $request,
        AccreditationProjectContextService $context,
    ): View {
        $document->loadMissing(['uploader', 'requirement.item.criterion.project']);
        Gate::authorize('view', $document);
        $context->synchronize($document->requirement->item->criterion->project);

        $extension = strtolower($document->extension);
        $fromRepository = $request->query('asal') === 'repository';

        return view('documents.preview', [
            'document' => $document,
            'fileAvailable' => Storage::disk($document->disk)->exists($document->storage_path),
            'previewType' => match ($extension) {
                'pdf' => 'pdf',
                'jpg', 'jpeg', 'png' => 'image',
                default => null,
            },
            'backUrl' => $fromRepository
                ? route('repository.index', [
                    'proyek' => $document->requirement->item->criterion->project_id,
                    'dokumen' => $document->id,
                ])
                : route('documents.upload', [
                    'requirement' => $document->requirement,
                    'proyek' => $document->requirement->item->criterion->project_id,
                ]),
            'backLabel' => $fromRepository
                ? __('documents.preview.evidence_repository')
                : __('documents.preview.back'),
            'fromRepository' => $fromRepository,
        ]);
    }

    public function content(AccreditationDocument $document): StreamedResponse
    {
        $document->loadMissing('requirement.item.criterion.project');
        Gate::authorize('view', $document);

        $mimeType = self::PREVIEW_MIME_TYPES[strtolower($document->extension)] ?? null;
        abort_unless($mimeType !== null, Response::HTTP_NOT_FOUND);

        return $this->fileResponse($document, 'inline', $mimeType);
    }

    public function download(AccreditationDocument $document): StreamedResponse
    {
        $document->loadMissing('requirement.item.criterion.project');
        Gate::authorize('view', $document);

        return $this->fileResponse($document, 'attachment');
    }

    public function openForSubmission(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
    ): StreamedResponse {
        $this->authorizeSnapshotDocument($submission, $document);
        $mimeType = self::PREVIEW_MIME_TYPES[strtolower($document->extension)] ?? null;

        return $this->fileResponse(
            $document,
            $mimeType === null ? 'attachment' : 'inline',
            $mimeType,
        );
    }

    public function downloadForSubmission(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
    ): StreamedResponse {
        $this->authorizeSnapshotDocument($submission, $document);

        return $this->fileResponse($document, 'attachment');
    }

    private function authorizeSnapshotDocument(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
    ): void {
        $submission->loadMissing('requirement.item.criterion.project.studyProgram');
        $document->loadMissing('requirement.item.criterion.project.studyProgram');
        Gate::authorize('view', $submission);
        Gate::authorize('view', $document);
        abort_unless(
            $submission->requirement_id === $document->requirement_id
                && $submission->documents()->whereKey($document->id)->exists(),
            Response::HTTP_NOT_FOUND,
        );
    }

    private function fileResponse(
        AccreditationDocument $document,
        string $disposition,
        ?string $contentType = null,
    ): StreamedResponse {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk);
        abort_unless(
            $disk->exists($document->storage_path),
            Response::HTTP_NOT_FOUND,
            __('documents.errors.document_unavailable'),
        );
        abort_unless(
            hash_equals(strtolower($document->sha256), $this->storedFileHash($disk, $document->storage_path)),
            Response::HTTP_NOT_FOUND,
            __('documents.errors.document_unavailable'),
        );

        return $disk->response(
            $document->storage_path,
            $this->safeOriginalName($document),
            [
                'Content-Type' => $contentType ?? $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $disposition,
        );
    }

    private function storedFileHash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        abort_if(
            $stream === false,
            Response::HTTP_NOT_FOUND,
            __('documents.errors.document_unavailable'),
        );

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function safeOriginalName(AccreditationDocument $document): string
    {
        $name = trim(str_replace(["\0", "\r", "\n", '/', '\\'], '_', $document->original_name));

        return $name !== '' ? $name : $document->storage_uuid.'.'.$document->extension;
    }
}
