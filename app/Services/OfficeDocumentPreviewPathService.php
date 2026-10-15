<?php

namespace App\Services;

use App\Models\AccreditationDocument;
use InvalidArgumentException;

class OfficeDocumentPreviewPathService
{
    public function preview(AccreditationDocument $document): string
    {
        return $this->directory($document).'/preview.pdf';
    }

    public function status(AccreditationDocument $document): string
    {
        return $this->directory($document).'/status.json';
    }

    public function lockKey(AccreditationDocument $document, string $purpose): string
    {
        return implode(':', [
            'office-preview',
            $purpose,
            $document->id,
            strtolower($document->sha256),
            $this->profileVersion(),
        ]);
    }

    private function directory(AccreditationDocument $document): string
    {
        if (! $document->exists || $document->id < 1) {
            throw new InvalidArgumentException('Document ID preview tidak valid.');
        }

        $sha256 = strtolower($document->sha256);
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException('Checksum document preview tidak valid.');
        }

        return implode('/', [
            trim((string) config('document-preview.cache_prefix'), '/'),
            $document->id,
            $sha256,
            $this->profileVersion(),
        ]);
    }

    private function profileVersion(): string
    {
        $version = strtolower((string) config('document-preview.profile_version'));
        $version = preg_replace('/[^a-z0-9._-]+/', '-', $version) ?? '';
        $version = trim($version, '-.');

        if ($version === '') {
            throw new InvalidArgumentException('Versi profil converter tidak valid.');
        }

        return substr($version, 0, 80);
    }
}
