<?php

namespace App\Jobs;

use App\Exceptions\OfficeDocumentPreviewException;
use App\Models\AccreditationDocument;
use App\Models\AccreditationReviewSubmission;
use App\Services\OfficeDocumentPreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateOfficeDocumentPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $documentId,
        public int $submissionId,
        public string $expectedSha256,
    ) {
        $this->tries = (int) config('document-preview.job_tries');
        $this->timeout = (int) config('document-preview.job_timeout');
        $this->onQueue((string) config('document-preview.queue'));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('document-preview.job_backoff'));
    }

    public function handle(OfficeDocumentPreviewService $previews): void
    {
        $document = AccreditationDocument::query()->findOrFail($this->documentId);
        $submission = AccreditationReviewSubmission::query()->findOrFail($this->submissionId);

        try {
            $previews->generate($document, $submission, $this->expectedSha256);
        } catch (OfficeDocumentPreviewException $exception) {
            if (! $exception->retryable) {
                $previews->markFailed($this->documentId, $this->expectedSha256);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(OfficeDocumentPreviewService::class)->markFailed($this->documentId, $this->expectedSha256);
    }
}
