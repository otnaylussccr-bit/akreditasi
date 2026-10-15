<?php

namespace App\Http\Controllers;

use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewSubmission;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccreditationReviewAttachmentAccessController extends Controller
{
    private const PREVIEW_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function open(
        AccreditationReviewSubmission $submission,
        AccreditationReviewAttachment $attachment,
    ): StreamedResponse {
        $this->authorizeAttachment($submission, $attachment);
        $mimeType = self::PREVIEW_MIME_TYPES[strtolower($attachment->extension)] ?? null;

        return $this->fileResponse(
            $attachment,
            $mimeType === null ? 'attachment' : 'inline',
            $mimeType,
        );
    }

    public function download(
        AccreditationReviewSubmission $submission,
        AccreditationReviewAttachment $attachment,
    ): StreamedResponse {
        $this->authorizeAttachment($submission, $attachment);

        return $this->fileResponse($attachment, 'attachment');
    }

    private function authorizeAttachment(
        AccreditationReviewSubmission $submission,
        AccreditationReviewAttachment $attachment,
    ): void {
        $attachment->loadMissing('reviewEvent.submission.requirement.item.criterion.project.studyProgram');
        abort_unless($attachment->reviewEvent?->submission_id === $submission->id, Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $attachment);
    }

    private function fileResponse(
        AccreditationReviewAttachment $attachment,
        string $disposition,
        ?string $contentType = null,
    ): StreamedResponse {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->storage_path), Response::HTTP_NOT_FOUND, 'Lampiran tidak tersedia.');
        abort_unless(
            hash_equals($attachment->sha256, $this->storedFileHash($disk, $attachment->storage_path)),
            Response::HTTP_NOT_FOUND,
            'Lampiran tidak tersedia.',
        );

        return $disk->response(
            $attachment->storage_path,
            $this->safeOriginalName($attachment),
            [
                'Content-Type' => $contentType ?? $attachment->mime_type,
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
        abort_if($stream === false, Response::HTTP_NOT_FOUND, 'Lampiran tidak tersedia.');

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function safeOriginalName(AccreditationReviewAttachment $attachment): string
    {
        $name = trim(str_replace(["\0", "\r", "\n", '/', '\\'], '_', $attachment->original_filename));

        return $name !== '' ? $name : $attachment->storage_uuid.'.'.$attachment->extension;
    }
}
