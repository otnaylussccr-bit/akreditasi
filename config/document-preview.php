<?php

$backoff = array_values(array_filter(
    array_map('intval', explode(',', (string) env('DOCUMENT_PREVIEW_JOB_BACKOFF', '10,30'))),
    static fn (int $seconds): bool => $seconds > 0,
));
$temporaryRoot = env('DOCUMENT_PREVIEW_TEMP_ROOT');

return [
    'enabled' => (bool) env('DOCUMENT_PREVIEW_ENABLED', false),
    'executable' => env('DOCUMENT_PREVIEW_EXECUTABLE'),
    'profile_version' => env('DOCUMENT_PREVIEW_PROFILE_VERSION', 'libreoffice-v1'),
    'disk' => 'local',
    'cache_prefix' => 'accreditation-preview-cache/documents',
    'temporary_root' => is_string($temporaryRoot) && trim($temporaryRoot) !== ''
        ? $temporaryRoot
        : storage_path('app/document-preview-temporary'),
    'queue' => env('DOCUMENT_PREVIEW_QUEUE', 'office-preview'),
    'process_timeout' => max(1, (int) env('DOCUMENT_PREVIEW_PROCESS_TIMEOUT', 60)),
    'job_timeout' => max(1, (int) env('DOCUMENT_PREVIEW_JOB_TIMEOUT', 75)),
    'job_tries' => max(1, (int) env('DOCUMENT_PREVIEW_JOB_TRIES', 3)),
    'job_backoff' => $backoff !== [] ? $backoff : [10, 30],
    'lock_seconds' => max(1, (int) env('DOCUMENT_PREVIEW_LOCK_SECONDS', 120)),
    'processing_stale_seconds' => max(60, (int) env('DOCUMENT_PREVIEW_PROCESSING_STALE_SECONDS', 300)),
    'temporary_retention_seconds' => max(3600, (int) env('DOCUMENT_PREVIEW_TEMP_RETENTION_SECONDS', 86400)),
    'poll_seconds' => max(1, (int) env('DOCUMENT_PREVIEW_POLL_SECONDS', 2)),
    'max_poll_attempts' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_POLL_ATTEMPTS', 30)),
    'max_input_bytes' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_INPUT_BYTES', 50 * 1024 * 1024)),
    'max_output_bytes' => max(40, (int) env('DOCUMENT_PREVIEW_MAX_OUTPUT_BYTES', 100 * 1024 * 1024)),
    'ooxml' => [
        'max_entries' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_ARCHIVE_ENTRIES', 5000)),
        'max_uncompressed_bytes' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_UNCOMPRESSED_BYTES', 200 * 1024 * 1024)),
        'max_compression_ratio' => max(1, (float) env('DOCUMENT_PREVIEW_MAX_COMPRESSION_RATIO', 100)),
        'max_relationship_bytes' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_RELATIONSHIP_BYTES', 5 * 1024 * 1024)),
        'max_content_types_bytes' => max(1, (int) env('DOCUMENT_PREVIEW_MAX_CONTENT_TYPES_BYTES', 1024 * 1024)),
    ],
    'security' => [
        'network_isolated' => (bool) env('DOCUMENT_PREVIEW_NETWORK_ISOLATED', false),
        'require_network_isolation' => true,
    ],
    'discovery_paths' => [
        'C:/Program Files/LibreOffice/program/soffice.exe',
        'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    ],
    'discovery_names' => ['soffice', 'libreoffice'],
];
