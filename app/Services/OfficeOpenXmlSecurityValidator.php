<?php

namespace App\Services;

use App\Exceptions\OfficeDocumentPreviewException;
use SimpleXMLElement;
use ZipArchive;

class OfficeOpenXmlSecurityValidator
{
    private const DANGEROUS_RELATIONSHIP_TYPES = [
        '/activexcontrol',
        '/afchunk',
        '/attachedtemplate',
        '/connections',
        '/control',
        '/externallink',
        '/oleobject',
        '/package',
        '/vbaproject',
    ];

    public function validate(string $path, string $extension): void
    {
        $extension = strtolower($extension);
        $requiredEntry = match ($extension) {
            'docx' => 'word/document.xml',
            'xlsx' => 'xl/workbook.xml',
            default => throw new OfficeDocumentPreviewException('Format Office tidak didukung.'),
        };
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new OfficeDocumentPreviewException('Struktur OOXML tidak dapat dibuka.');
        }

        try {
            if ($archive->numFiles < 1
                || $archive->numFiles > (int) config('document-preview.ooxml.max_entries')
                || $archive->locateName('[Content_Types].xml') === false
                || $archive->locateName($requiredEntry) === false) {
                throw new OfficeDocumentPreviewException('Struktur OOXML tidak valid atau terlalu kompleks.');
            }

            $relationshipEntries = $this->preflight($archive);
            $contentTypes = $archive->getFromName('[Content_Types].xml');
            if (! is_string($contentTypes)) {
                throw new OfficeDocumentPreviewException('Content types OOXML tidak dapat dibaca.');
            }
            $this->validateContentTypes($contentTypes);

            foreach ($relationshipEntries as [$index, $name]) {
                $this->validateRelationships($archive, $index, $name);
            }
        } finally {
            $archive->close();
        }
    }

    /** @return array<int, array{int, string}> */
    private function preflight(ZipArchive $archive): array
    {
        $seen = [];
        $relationshipEntries = [];
        $totalUncompressed = 0;
        $totalRelationshipBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if (! is_array($stat) || ! isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                throw new OfficeDocumentPreviewException('Metadata OOXML tidak valid.');
            }

            $name = str_replace('\\', '/', (string) $stat['name']);
            $lowerName = strtolower($name);
            $size = (int) $stat['size'];
            $compressedSize = (int) $stat['comp_size'];
            $totalUncompressed += $size;

            if ($name === ''
                || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
                || str_starts_with($name, '/')
                || preg_match('/^[a-z]:\//i', $name) === 1
                || preg_match('#(^|/)\.\.(/|$)#', $name) === 1
                || isset($seen[$lowerName])
                || $totalUncompressed > (int) config('document-preview.ooxml.max_uncompressed_bytes')
                || (int) ($stat['encryption_method'] ?? 0) !== 0) {
                throw new OfficeDocumentPreviewException('Paket OOXML melewati batas keamanan.');
            }
            $seen[$lowerName] = true;

            if ($size > 0 && $size / max(1, $compressedSize) > (float) config('document-preview.ooxml.max_compression_ratio')) {
                throw new OfficeDocumentPreviewException('Rasio kompresi OOXML melewati batas keamanan.');
            }

            if ($lowerName === '[content_types].xml'
                && $size > (int) config('document-preview.ooxml.max_content_types_bytes')) {
                throw new OfficeDocumentPreviewException('Content types OOXML terlalu besar.');
            }

            if ($this->isDangerousEntry($lowerName)) {
                throw new OfficeDocumentPreviewException('Dokumen mengandung bagian aktif atau embedded yang tidak diizinkan.');
            }

            if (str_ends_with($lowerName, '.rels')) {
                $totalRelationshipBytes += $size;
                if ($totalRelationshipBytes > (int) config('document-preview.ooxml.max_relationship_bytes')) {
                    throw new OfficeDocumentPreviewException('Relationship OOXML terlalu besar.');
                }
                $relationshipEntries[] = [$index, $name];
            }
        }

        return $relationshipEntries;
    }

    private function isDangerousEntry(string $name): bool
    {
        return str_ends_with($name, 'vbaproject.bin')
            || str_contains($name, '/embeddings/')
            || str_contains($name, '/activex/')
            || str_contains($name, '/externallinks/')
            || str_contains($name, '/afchunk')
            || str_ends_with($name, '/connections.xml')
            || preg_match('/\.(exe|dll|com|bat|cmd|ps1|js|vbs|scr|msi)$/', $name) === 1;
    }

    private function validateContentTypes(string $xml): void
    {
        $contentTypes = $this->parseXml($xml, 'Content types OOXML tidak valid.');

        foreach ($contentTypes->xpath('//*[@ContentType]') ?: [] as $entry) {
            $type = strtolower((string) $entry['ContentType']);
            if (str_contains($type, 'macroenabled')
                || str_contains($type, 'vbaproject')
                || str_contains($type, 'oleobject')
                || str_contains($type, 'activex')
                || str_contains($type, 'externallink')) {
                throw new OfficeDocumentPreviewException('Dokumen mengandung content type aktif yang tidak diizinkan.');
            }
        }
    }

    private function validateRelationships(ZipArchive $archive, int $index, string $relationshipPath): void
    {
        $xml = $archive->getFromIndex($index);
        if (! is_string($xml)) {
            throw new OfficeDocumentPreviewException('Relationship OOXML tidak dapat dibaca.');
        }

        $relationships = $this->parseXml($xml, 'Relationship OOXML tidak valid.');
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $mode = trim((string) $relationship['TargetMode']);
            $type = strtolower(trim((string) $relationship['Type']));
            $target = trim((string) $relationship['Target']);

            if ($mode !== '' && strcasecmp($mode, 'Internal') !== 0) {
                throw new OfficeDocumentPreviewException('External relationship tidak diizinkan pada preview.');
            }

            foreach (self::DANGEROUS_RELATIONSHIP_TYPES as $suffix) {
                if (str_ends_with($type, $suffix)) {
                    throw new OfficeDocumentPreviewException('Relationship aktif atau embedded tidak diizinkan pada preview.');
                }
            }

            $this->validateInternalTarget($relationshipPath, $target);
        }
    }

    private function validateInternalTarget(string $relationshipPath, string $target): void
    {
        $decoded = rawurldecode($target);
        if ($decoded === ''
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            || str_contains($decoded, '\\')
            || str_starts_with($decoded, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded) === 1
            || str_contains($decoded, '?')) {
            throw new OfficeDocumentPreviewException('Target relationship OOXML tidak aman.');
        }

        $path = explode('#', $decoded, 2)[0];
        if ($path === '') {
            return;
        }

        $segments = str_starts_with($path, '/')
            ? []
            : $this->relationshipBaseSegments($relationshipPath);
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new OfficeDocumentPreviewException('Target relationship keluar dari paket OOXML.');
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
    }

    /** @return array<int, string> */
    private function relationshipBaseSegments(string $relationshipPath): array
    {
        $relationshipPath = str_replace('\\', '/', $relationshipPath);
        if ($relationshipPath === '_rels/.rels') {
            return [];
        }

        $marker = '/_rels/';
        $position = strrpos($relationshipPath, $marker);
        if ($position === false || ! str_ends_with($relationshipPath, '.rels')) {
            throw new OfficeDocumentPreviewException('Path relationship OOXML tidak valid.');
        }

        $prefix = substr($relationshipPath, 0, $position);
        $sourceName = substr($relationshipPath, $position + strlen($marker), -strlen('.rels'));
        $sourcePath = ltrim($prefix.'/'.$sourceName, '/');
        $base = dirname($sourcePath);

        return $base === '.' ? [] : explode('/', $base);
    }

    private function parseXml(string $xml, string $errorMessage): SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new OfficeDocumentPreviewException($errorMessage);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if (! $parsed instanceof SimpleXMLElement) {
                throw new OfficeDocumentPreviewException($errorMessage);
            }

            return $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
