<?php

namespace App\Policies;

use App\Models\AccreditationLedAssessment;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;

class AccreditationLedAssessmentPolicy
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function view(User $user, AccreditationLedAssessment $assessment): bool
    {
        return $this->authorization->canViewAssessment($user, $assessment);
    }

    public function update(User $user, AccreditationLedAssessment $assessment): bool
    {
        return $this->authorization->canEditAssessment($user, $assessment);
    }
}
