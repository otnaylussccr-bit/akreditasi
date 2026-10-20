<?php

return [
    'events' => [
        'document_uploaded' => [
            'label' => 'Dokumen diunggah',
            'short_label' => 'Upload',
        ],
        'document_emergency_uploaded' => [
            'label' => 'Dokumen diunggah oleh Super Admin',
            'short_label' => 'Upload',
        ],
        'document_submitted' => [
            'label' => 'Dokumen dikirim untuk verifikasi',
            'short_label' => 'Dikirim',
        ],
        'document_revision_requested' => [
            'label' => 'Perbaikan dokumen diminta',
            'short_label' => 'Perlu Revisi',
        ],
        'document_verified' => [
            'label' => 'Dokumen terverifikasi',
            'short_label' => 'Terverifikasi',
        ],
    ],
    'categories' => [
        'upload' => 'Upload Dokumen',
        'submission' => 'Pengiriman Verifikasi',
        'revision' => 'Revisi',
        'verification' => 'Verifikasi',
    ],
    'actors' => [
        'system' => 'Sistem',
    ],
    'actor_roles' => [
        'internal_reviewer' => 'Verifikator Internal',
        'accreditation_team' => 'Tim Akreditasi',
    ],
    'datetime' => [
        'business_timezone' => 'WIB',
        'with_timezone' => ':datetime :timezone',
    ],
];
