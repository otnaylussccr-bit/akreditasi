<?php

namespace App\Http\Controllers;

use App\Models\AccreditationDocument;
use App\Models\AccreditationReviewSubmission;
use App\Services\OfficeDocumentPreviewService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccreditationOfficePreviewController extends Controller
{
    public function requestPreview(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
        OfficeDocumentPreviewService $previews,
    ): JsonResponse {
        $this->authorizeSnapshotDocument($submission, $document);
        $status = $previews->request($document, $submission);

        return response()->json($status, $status['state'] === 'processing' ? 202 : 200);
    }

    public function status(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
        OfficeDocumentPreviewService $previews,
    ): JsonResponse {
        $this->authorizeSnapshotDocument($submission, $document);

        return response()->json($previews->status($document));
    }

    public function content(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
        OfficeDocumentPreviewService $previews,
    ): StreamedResponse {
        $this->authorizeSnapshotDocument($submission, $document);
        abort_unless(
            $previews->status($document)['state'] === 'ready',
            Response::HTTP_NOT_FOUND,
            __('verification.preview.not_ready'),
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('document-preview.disk'));

        return $disk->response(
            $previews->previewPath($document),
            'document-'.$document->id.'-preview.pdf',
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline',
        );
    }

    private function authorizeSnapshotDocument(
        AccreditationReviewSubmission $submission,
        AccreditationDocument $document,
    ): void {
        $submission->loadMissing('requirement.item.criterion.project');
        $document->loadMissing('requirement.item.criterion.project');
        Gate::authorize('view', $submission);
        Gate::authorize('view', $document);
        abort_unless(
            $submission->requirement_id === $document->requirement_id
                && $submission->documents()->whereKey($document->id)->exists(),
            Response::HTTP_NOT_FOUND,
        );
    }
}
