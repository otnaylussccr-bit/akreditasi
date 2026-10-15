<?php

namespace App\Services;

use App\Contracts\OfficeDocumentConverter;
use App\Exceptions\OfficeDocumentPreviewException;
use App\Jobs\GenerateOfficeDocumentPreview;
use App\Models\AccreditationDocument;
use App\Models\AccreditationReviewSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OfficeDocumentPreviewService
{
    public const SUPPORTED_EXTENSIONS = ['docx', 'xlsx'];

    public function __construct(
        private OfficeDocumentConverter $converter,
        private OfficeDocumentPreviewPathService $paths,
        private OfficeOpenXmlSecurityValidator $validator,
    ) {}

    public function supports(AccreditationDocument $document): bool
    {
        return in_array(strtolower($document->extension), self::SUPPORTED_EXTENSIONS, true);
    }

    /** @return array{state: string, message: string, poll: bool} */
    public function status(AccreditationDocument $document): array
    {
        if (! $this->supports($document)) {
            return $this->state('unsupported', 'Format ini tidak menggunakan Office Preview Engine.');
        }

        if (! config('document-preview.enabled')) {
            return $this->state('unavailable', 'Converter belum diaktifkan.');
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('document-preview.disk'));
        $previewPath = $this->paths->preview($document);
        if ($disk->exists($previewPath)) {
            return $this->validStoredPdf($disk, $previewPath)
                ? $this->state('ready', 'Preview siap ditampilkan.')
                : $this->state('failed', 'Cache preview tidak valid.');
        }

        $marker = $this->readStatusMarker($document);
        if ($marker !== null
            && ($marker['state'] ?? null) === 'processing'
            && $this->processingMarkerIsStale($marker)) {
            $marker = null;
        }
        if ($marker !== null && in_array($marker['state'] ?? null, ['processing', 'failed'], true)) {
            return $this->state(
                $marker['state'],
                $marker['state'] === 'processing'
                    ? 'Preview sedang diproses.'
                    : 'Preview gagal dibuat. File original tetap tersedia.',
            );
        }

        $availability = $this->converter->availability();
        if (! $availability['available']) {
            return $this->state('unavailable', $availability['reason']);
        }

        return $this->state('missing', 'Preview belum tersedia.');
    }

    /** @return array{state: string, message: string, poll: bool} */
    public function request(
        AccreditationDocument $document,
        AccreditationReviewSubmission $submission,
        bool $force = false,
    ): array {
        $this->assertSnapshotMembership($document, $submission);
        $status = $this->status($document);

        if (in_array($status['state'], ['ready', 'processing', 'unsupported', 'unavailable'], true)
            || ($status['state'] === 'failed' && ! $force)) {
            return $status;
        }

        $lock = Cache::lock($this->paths->lockKey($document, 'dispatch'), 10);
        if (! $lock->get()) {
            return $this->status($document);
        }

        try {
            $current = $this->status($document);
            if (! $force && in_array($current['state'], ['ready', 'processing'], true)) {
                return $current;
            }

            $availability = $this->converter->availability();
            if (! $availability['available']) {
                return $this->state('unavailable', $availability['reason']);
            }

            $this->writeStatusMarker($document, 'processing');
            GenerateOfficeDocumentPreview::dispatch(
                $document->id,
                $submission->id,
                strtolower($document->sha256),
            );

            return $this->state('processing', 'Preview sedang diproses.');
        } catch (Throwable) {
            try {
                $this->writeStatusMarker($document, 'failed');
            } catch (Throwable) {
                // The fallback response must remain available when queue and cache storage both fail.
            }

            return $this->state('failed', 'Preview gagal dijadwalkan. File original tetap tersedia.');
        } finally {
            $lock->release();
        }
    }

    public function generate(
        AccreditationDocument $document,
        AccreditationReviewSubmission $submission,
        string $expectedSha256,
    ): void {
        $expectedSha256 = strtolower($expectedSha256);
        if (! hash_equals(strtolower($document->sha256), $expectedSha256)) {
            throw new OfficeDocumentPreviewException('Checksum document berubah sebelum conversion.');
        }

        $this->assertSnapshotMembership($document, $submission);
        if (! $this->supports($document)) {
            throw new OfficeDocumentPreviewException('Format Office tidak didukung.');
        }

        $availability = $this->converter->availability();
        if (! $availability['available']) {
            throw new OfficeDocumentPreviewException($availability['reason']);
        }

        $lock = Cache::lock(
            $this->paths->lockKey($document, 'conversion'),
            (int) config('document-preview.lock_seconds'),
        );
        if (! $lock->get()) {
            return;
        }

        $workspace = rtrim((string) config('document-preview.temporary_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.Str::uuid();

        try {
            $status = $this->status($document);
            if ($status['state'] === 'ready') {
                return;
            }

            $this->cleanupExpiredWorkspaces();
            File::makeDirectory($workspace, 0700, true, true);
            $inputPath = $workspace.DIRECTORY_SEPARATOR.'input.'.strtolower($document->extension);
            $this->copyAndVerifyOriginal($document, $inputPath);
            $this->validator->validate($inputPath, $document->extension);

            $outputDirectory = $workspace.DIRECTORY_SEPARATOR.'output';
            $outputPath = $this->converter->convert($inputPath, $outputDirectory, $document->extension);
            $this->validateGeneratedPdf($outputPath);
            $this->publish($document, $outputPath);
            $this->writeStatusMarker($document, 'ready');
        } finally {
            $this->cleanupWorkspace($workspace);
            $lock->release();
        }
    }

    public function markFailed(int $documentId, string $expectedSha256): void
    {
        $document = AccreditationDocument::query()->find($documentId);
        if ($document && hash_equals(strtolower($document->sha256), strtolower($expectedSha256))) {
            $this->writeStatusMarker($document, 'failed');
        }
    }

    public function previewPath(AccreditationDocument $document): string
    {
        return $this->paths->preview($document);
    }

    private function assertSnapshotMembership(
        AccreditationDocument $document,
        AccreditationReviewSubmission $submission,
    ): void {
        if ($submission->requirement_id !== $document->requirement_id
            || ! $submission->documents()->whereKey($document->id)->exists()) {
            throw new OfficeDocumentPreviewException('Dokumen bukan bagian dari snapshot submission.');
        }
    }

    private function copyAndVerifyOriginal(AccreditationDocument $document, string $destination): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk);
        if (! $disk->exists($document->storage_path)) {
            throw new OfficeDocumentPreviewException('File original tidak tersedia.');
        }

        $source = $disk->readStream($document->storage_path);
        $target = fopen($destination, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            throw new OfficeDocumentPreviewException('File original tidak dapat disalin.');
        }

        try {
            $bytes = stream_copy_to_stream(
                $source,
                $target,
                (int) config('document-preview.max_input_bytes') + 1,
            );
        } finally {
            fclose($source);
            fclose($target);
        }

        if (! is_int($bytes) || $bytes < 1 || $bytes > (int) config('document-preview.max_input_bytes')) {
            throw new OfficeDocumentPreviewException('Ukuran file Office melewati batas preview.');
        }

        $actualSha256 = hash_file('sha256', $destination);
        if (! is_string($actualSha256) || ! hash_equals(strtolower($document->sha256), $actualSha256)) {
            throw new OfficeDocumentPreviewException('Checksum file original tidak sesuai metadata.');
        }
    }

    private function validateGeneratedPdf(string $path): void
    {
        $size = is_file($path) ? filesize($path) : false;
        if (! is_int($size) || $size < 40 || $size > (int) config('document-preview.max_output_bytes')) {
            throw new OfficeDocumentPreviewException('Ukuran output PDF tidak valid.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new OfficeDocumentPreviewException('Output PDF tidak dapat dibaca.');
        }

        try {
            $head = fread($handle, 5);
            $tailLength = min($size, 8192);
            fseek($handle, -$tailLength, SEEK_END);
            $tail = fread($handle, $tailLength);
        } finally {
            fclose($handle);
        }

        if ($head !== '%PDF-' || ! is_string($tail) || preg_match('/%%EOF\s*$/', $tail) !== 1) {
            throw new OfficeDocumentPreviewException('Output converter bukan PDF yang valid.');
        }
    }

    private function publish(AccreditationDocument $document, string $outputPath): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('document-preview.disk'));
        $previewPath = $this->paths->preview($document);
        $temporaryPath = $previewPath.'.publishing-'.Str::uuid();
        $stream = fopen($outputPath, 'rb');
        if ($stream === false) {
            throw new OfficeDocumentPreviewException('Output PDF tidak dapat dipublikasikan.', true);
        }

        try {
            $disk->put($temporaryPath, $stream, ['visibility' => 'private']);
            $disk->setVisibility($temporaryPath, 'private');
            if ($disk->exists($previewPath)) {
                $disk->delete($previewPath);
            }
            $disk->move($temporaryPath, $previewPath);
            $disk->setVisibility($previewPath, 'private');
        } catch (Throwable $exception) {
            $disk->delete($temporaryPath);
            throw new OfficeDocumentPreviewException('Cache PDF gagal dipublikasikan.', true, $exception);
        } finally {
            fclose($stream);
        }

        if (! $this->validStoredPdf($disk, $previewPath)) {
            throw new OfficeDocumentPreviewException('Cache PDF hasil publish tidak valid.', true);
        }
    }

    private function validStoredPdf(FilesystemAdapter $disk, string $path): bool
    {
        try {
            $size = $disk->size($path);
            if ($size < 40 || $size > (int) config('document-preview.max_output_bytes')) {
                return false;
            }

            $stream = $disk->readStream($path);
            if ($stream === false) {
                return false;
            }

            try {
                if (fread($stream, 5) !== '%PDF-') {
                    return false;
                }

                $tailLength = min($size, 8192);
                if (fseek($stream, -$tailLength, SEEK_END) !== 0) {
                    return false;
                }
                $tail = fread($stream, $tailLength);

                return is_string($tail) && preg_match('/%%EOF\s*$/', $tail) === 1;
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function readStatusMarker(AccreditationDocument $document): ?array
    {
        try {
            $contents = Storage::disk((string) config('document-preview.disk'))
                ->get($this->paths->status($document));
            $marker = json_decode($contents, true);

            return is_array($marker)
                && ($marker['document_id'] ?? null) === $document->id
                && ($marker['sha256'] ?? null) === strtolower($document->sha256)
                    ? $marker
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function writeStatusMarker(AccreditationDocument $document, string $state): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('document-preview.disk'));
        $statusPath = $this->paths->status($document);
        $temporaryPath = $statusPath.'.writing-'.Str::uuid();
        $contents = json_encode([
            'document_id' => $document->id,
            'sha256' => strtolower($document->sha256),
            'profile_version' => config('document-preview.profile_version'),
            'state' => $state,
            'updated_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        try {
            $disk->put($temporaryPath, $contents, ['visibility' => 'private']);
            if ($disk->exists($statusPath)) {
                $disk->delete($statusPath);
            }
            $disk->move($temporaryPath, $statusPath);
            $disk->setVisibility($statusPath, 'private');
        } catch (Throwable $exception) {
            $disk->delete($temporaryPath);
            throw new OfficeDocumentPreviewException('Status cache preview gagal disimpan.', true, $exception);
        }
    }

    /** @param array<string, mixed> $marker */
    private function processingMarkerIsStale(array $marker): bool
    {
        try {
            $updatedAt = CarbonImmutable::parse((string) ($marker['updated_at'] ?? ''));

            return $updatedAt->addSeconds((int) config('document-preview.processing_stale_seconds'))->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    private function cleanupExpiredWorkspaces(): void
    {
        $root = rtrim((string) config('document-preview.temporary_root'), DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            return;
        }

        $cutoff = time() - (int) config('document-preview.temporary_retention_seconds');
        foreach (File::directories($root) as $directory) {
            try {
                if (! $this->isTrustedWorkspace($directory)) {
                    $this->logCleanupWarning($directory, 'boundary_check_failed');

                    continue;
                }

                if (File::lastModified($directory) < $cutoff) {
                    $this->cleanupWorkspace($directory);
                }
            } catch (Throwable) {
                // Cleanup is best-effort and must not block a valid conversion.
            }
        }
    }

    private function cleanupWorkspace(string $workspace): void
    {
        clearstatcache(true, $workspace);
        if (! is_array(@lstat($workspace)) && realpath($workspace) === false && ! is_link($workspace)) {
            return;
        }

        if (! $this->isTrustedWorkspace($workspace)) {
            $this->logCleanupWarning($workspace, 'boundary_check_failed');

            return;
        }

        $failure = null;
        try {
            $this->deleteWorkspaceNode($workspace, (string) realpath($workspace));
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        clearstatcache(true, $workspace);
        if (is_array(@lstat($workspace)) || realpath($workspace) !== false || is_link($workspace)) {
            $this->logCleanupWarning(
                $workspace,
                $failure ? 'delete_error' : 'workspace_remains',
                $failure,
            );
        }
    }

    private function deleteWorkspaceNode(string $path, string $workspace): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $resolvedPath = realpath($path);
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if (! is_array($stat)
            || ! is_string($resolvedPath)
            || is_link($path)
            || ! $this->samePath($resolvedPath, $normalizedPath)
            || ! $this->isPathInsideWorkspace($resolvedPath, $workspace)) {
            throw new \RuntimeException('Unsafe temporary workspace node detected.');
        }

        $type = $stat['mode'] & 0170000;
        if ($type === 0040000) {
            if (PHP_OS_FAMILY === 'Windows' && ! @chmod($path, 0700)) {
                throw new \RuntimeException('Temporary directory attributes could not be normalized.');
            }

            $entries = @scandir($path, SCANDIR_SORT_ASCENDING);
            if (! is_array($entries)) {
                throw new \RuntimeException('Temporary directory could not be read.');
            }

            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->deleteWorkspaceNode($path.DIRECTORY_SEPARATOR.$entry, $workspace);
                }
            }

            if (! @rmdir($path)) {
                throw new \RuntimeException('Temporary directory could not be removed.');
            }

            return;
        }

        if ($type !== 0100000) {
            throw new \RuntimeException('Unsupported temporary workspace node detected.');
        }

        if (PHP_OS_FAMILY === 'Windows' && ! @chmod($path, 0600)) {
            throw new \RuntimeException('Temporary file attributes could not be normalized.');
        }

        if (! @unlink($path)) {
            throw new \RuntimeException('Temporary file could not be removed.');
        }
    }

    private function isTrustedWorkspace(string $workspace): bool
    {
        $workspaceId = basename($workspace);
        $stat = @lstat($workspace);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $workspaceId) !== 1
            || ! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0040000
            || is_link($workspace)) {
            return false;
        }

        $root = realpath((string) config('document-preview.temporary_root'));
        $parent = realpath(dirname($workspace));
        $resolvedWorkspace = realpath($workspace);
        if (! is_string($root) || ! is_string($parent) || ! is_string($resolvedWorkspace)) {
            return false;
        }

        return $this->samePath($root, $parent)
            && $this->samePath($root, dirname($resolvedWorkspace));
    }

    private function isPathInsideWorkspace(string $path, string $workspace): bool
    {
        if ($this->samePath($path, $workspace)) {
            return true;
        }

        $prefix = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
    }

    private function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function logCleanupWarning(string $workspace, string $reason, ?Throwable $exception = null): void
    {
        try {
            Log::warning('Office preview temporary workspace cleanup did not complete.', [
                'workspace_reference' => substr(hash('sha256', $workspace), 0, 16),
                'reason' => $reason,
                'exception' => $exception ? $exception::class : null,
            ]);
        } catch (Throwable) {
            // Cleanup observability must not turn a completed conversion into a retry.
        }
    }

    /** @return array{state: string, message: string, poll: bool} */
    private function state(string $state, string $message): array
    {
        return ['state' => $state, 'message' => $message, 'poll' => $state === 'processing'];
    }
}
