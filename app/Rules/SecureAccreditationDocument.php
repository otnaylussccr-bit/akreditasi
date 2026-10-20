<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class SecureAccreditationDocument implements ValidationRule
{
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpeg', 'jpg', 'png', 'bmp', 'tiff', 'tif'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('documents.validation.file_unreadable'));

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            $fail(__('documents.validation.unsupported_format'));

            return;
        }

        if (mb_strlen($value->getClientOriginalName()) > 512) {
            $fail(__('documents.validation.filename_too_long'));

            return;
        }

        $path = $value->getRealPath();
        $mime = $value->getMimeType();

        if (! is_string($path) || $path === '' || ! is_string($mime)) {
            $fail(__('documents.validation.content_uninspectable'));

            return;
        }

        $valid = match ($extension) {
            'pdf' => $this->isPdf($path, $mime),
            'jpg', 'jpeg' => $this->isImage($path, $mime, IMAGETYPE_JPEG, ['image/jpeg']),
            'png' => $this->isImage($path, $mime, IMAGETYPE_PNG, ['image/png']),
            'bmp' => $this->isImage($path, $mime, IMAGETYPE_BMP, ['image/bmp', 'image/x-ms-bmp']),
            'tif', 'tiff' => $this->isTiff($path, $mime),
            'doc' => $this->isOleDocument($path, $mime, ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'], ['WordDocument']),
            'xls' => $this->isOleDocument($path, $mime, ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'], ['Workbook', 'Book']),
            'docx' => $this->isOpenXml($path, $mime, 'word/document.xml'),
            'xlsx' => $this->isOpenXml($path, $mime, 'xl/workbook.xml'),
            default => false,
        };

        if (! $valid) {
            $fail(__('documents.validation.extension_mismatch'));
        }
    }

    private function hasPrefix(string $path, string $prefix): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $bytes = fread($handle, strlen($prefix));
        fclose($handle);

        return $bytes === $prefix;
    }

    private function isImage(string $path, string $mime, int $expectedType, array $allowedMimes): bool
    {
        return in_array($mime, $allowedMimes, true) && @exif_imagetype($path) === $expectedType;
    }

    private function isTiff(string $path, string $mime): bool
    {
        return $mime === 'image/tiff'
            && ($this->hasPrefix($path, "II\x2a\x00") || $this->hasPrefix($path, "MM\x00\x2a"));
    }

    private function isPdf(string $path, string $mime): bool
    {
        if (! in_array($mime, ['application/pdf', 'application/x-pdf'], true)
            || ! $this->hasPrefix($path, '%PDF-')) {
            return false;
        }

        $size = filesize($path);

        if ($size === false || $size < 40) {
            return false;
        }

        $head = file_get_contents($path, false, null, 0, min($size, 1048576));
        $handle = fopen($path, 'rb');

        if (! is_string($head) || $handle === false) {
            return false;
        }

        $tailLength = min($size, 8192);
        fseek($handle, -$tailLength, SEEK_END);
        $tail = fread($handle, $tailLength);
        fclose($handle);

        return is_string($tail)
            && preg_match('/\d+\s+\d+\s+obj\b/', $head) === 1
            && str_contains($tail, 'startxref')
            && preg_match('/%%EOF\s*$/', $tail) === 1;
    }

    private function isOleDocument(
        string $path,
        string $mime,
        array $allowedMimes,
        array $requiredStreams,
    ): bool {
        if (! in_array($mime, $allowedMimes, true)
            || ! $this->hasPrefix($path, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")) {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, 512);

        if (! is_string($header) || strlen($header) < 512) {
            return false;
        }

        $majorVersion = unpack('v', substr($header, 26, 2))[1] ?? null;
        $byteOrder = unpack('v', substr($header, 28, 2))[1] ?? null;
        $sectorShift = unpack('v', substr($header, 30, 2))[1] ?? null;

        if (! in_array($majorVersion, [3, 4], true)
            || $byteOrder !== 0xFFFE
            || ! in_array($sectorShift, [9, 12], true)) {
            return false;
        }

        foreach ($requiredStreams as $streamName) {
            if ($this->containsBytes($path, mb_convert_encoding($streamName, 'UTF-16LE', 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    private function containsBytes(string $path, string $needle): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $overlap = '';
        $overlapLength = max(0, strlen($needle) - 1);

        while (! feof($handle)) {
            $chunk = fread($handle, 65536);

            if ($chunk === false) {
                fclose($handle);

                return false;
            }

            $buffer = $overlap.$chunk;

            if (str_contains($buffer, $needle)) {
                fclose($handle);

                return true;
            }

            $overlap = $overlapLength > 0 ? substr($buffer, -$overlapLength) : '';
        }

        fclose($handle);

        return false;
    }

    private function isOpenXml(string $path, string $mime, string $requiredEntry): bool
    {
        if (! in_array($mime, [
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            return false;
        }

        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            return false;
        }

        $valid = $archive->locateName('[Content_Types].xml') !== false
            && $archive->locateName($requiredEntry) !== false;
        $archive->close();

        return $valid;
    }
}
