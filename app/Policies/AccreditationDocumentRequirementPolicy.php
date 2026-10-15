<?php

namespace App\Policies;

use App\Models\AccreditationDocumentRequirement;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;

class AccreditationDocumentRequirementPolicy
{
    public function __construct(private AccreditationAuthorizationService $authorization) {}

    public function view(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        return $this->authorization->canViewRequirement($user, $requirement);
    }

    public function upload(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        return $this->authorization->canUploadRequirement($user, $requirement);
    }

    public function accessUpload(User $user, AccreditationDocumentRequirement $requirement): bool
    {
        return $this->authorization->canAccessRequirementUpload($user, $requirement);
    }
}
