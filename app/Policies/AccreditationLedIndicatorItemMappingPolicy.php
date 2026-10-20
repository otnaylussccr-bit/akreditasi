<?php

namespace App\Policies;

use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;

class AccreditationLedIndicatorItemMappingPolicy
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function view(User $user, AccreditationLedIndicatorItemMapping $mapping): bool
    {
        return $this->authorization->canViewCycle($user, $mapping->cycle);
    }

    public function revoke(User $user, AccreditationLedIndicatorItemMapping $mapping): bool
    {
        return $mapping->revoked_at === null
            && in_array($mapping->cycle->status, ['draft', 'active'], true)
            && $this->authorization->canManageCycle($user, $mapping->cycle);
    }
}
