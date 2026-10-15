<?php

namespace App\Policies;

use App\Models\AccreditationDocument;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;

class AccreditationDocumentPolicy
{
    public function __construct(private AccreditationAuthorizationService $authorization) {}

    public function view(User $user, AccreditationDocument $document): bool
    {
        return $this->authorization->canViewDocument($user, $document);
    }
}
