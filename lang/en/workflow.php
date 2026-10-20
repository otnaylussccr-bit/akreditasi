<?php

return [
    'events' => [
        'document_uploaded' => [
            'label' => 'Document uploaded',
            'short_label' => 'Uploaded',
        ],
        'document_emergency_uploaded' => [
            'label' => 'Document uploaded by a Super Admin',
            'short_label' => 'Uploaded',
        ],
        'document_submitted' => [
            'label' => 'Document submitted for verification',
            'short_label' => 'Submitted',
        ],
        'document_revision_requested' => [
            'label' => 'Document revision requested',
            'short_label' => 'Needs Revision',
        ],
        'document_verified' => [
            'label' => 'Document verified',
            'short_label' => 'Verified',
        ],
    ],
    'categories' => [
        'upload' => 'Document Uploads',
        'submission' => 'Verification Submissions',
        'revision' => 'Revisions',
        'verification' => 'Verifications',
    ],
    'actors' => [
        'system' => 'System',
    ],
    'actor_roles' => [
        'internal_reviewer' => 'Internal Verifier',
        'accreditation_team' => 'Accreditation Team',
    ],
    'datetime' => [
        'business_timezone' => 'WIB (UTC+7)',
        'with_timezone' => ':datetime :timezone',
    ],
];
